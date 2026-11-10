<?php

namespace App\Shipping\Shipments;

use App\Jobs\MatchProviderLocation;
use App\Models\Lead;
use App\Models\ProviderLocationCorrection;
use App\Models\User;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierProvider;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Regions;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Log;

/**
 * Our address (regions with Kemendagri codes and a postal code per kelurahan) mapped once to the shipping
 * provider's own place (Everpro sub_district_id), so Buat resi and resi otomatis do not search again:
 *
 * 1. The agency's own correction for this kelurahan (an agent's earlier choice) wins.
 * 2. Postal code + the same kelurahan (names normalised, small spelling differences allowed).
 * 3. Postal code + the same kecamatan, when only one place of that kecamatan is there.
 * 4. Without a postal code, or when the provider does not know it: the names alone.
 *
 * Several equally good places are never picked silently ("perlu dipilih"); nothing found is "tidak ketemu".
 * Differences (our postal code against the provider's, spelling) are written to the note. Resi otomatis runs
 * only for "pasti". Matching runs on the queue when a lead's address changes (MatchProviderLocation).
 */
final class ProviderLocations
{
    public const EXACT = 'exact';

    public const MULTIPLE = 'multiple';

    public const NONE = 'none';

    public const LABELS = [self::EXACT => 'pasti', self::MULTIPLE => 'perlu dipilih', self::NONE => 'tidak ketemu'];

    public function __construct(private CurrentTenant $current) {}

    /** "Lokasi Everpro: pasti" (Inbox chip, lead, Pengiriman), or null before matching. */
    public static function chip(Lead $lead): ?string
    {
        $state = $lead->provider_location_state;

        return isset(self::LABELS[$state]) ? 'Lokasi '.Carriers::label(Carriers::account(app(CurrentTenant::class)->get())?->provider ?? 'everpro').': '.self::LABELS[$state] : null;
    }

    /** The parts of the address the match depends on: a new key means matching again. */
    public static function key(Lead $lead): ?string
    {
        $parts = (array) $lead->shipping_address_parts;
        $region = self::regionKey($parts);

        return $region !== null ? sha1($region) : null;
    }

    /** "40134|sekeloa|coblong|kota bandung": the correction table's key for a place of ours. */
    public static function regionKey(array $parts): ?string
    {
        $postal = preg_replace('/\D/', '', (string) ($parts['kode_pos'] ?? ''));
        $names = array_map(fn ($k) => self::norm($parts[$k] ?? null), ['kelurahan', 'kecamatan', 'kota']);

        return ($postal === '' && $names[0] === '') ? null : $postal.'|'.implode('|', $names);
    }

    /** Queued after a lead's address changes, for an agency with a shipping provider. */
    public static function queue(Lead $lead): void
    {
        if (! Carriers::configured(app(CurrentTenant::class)->get()) || self::key($lead) === null || self::key($lead) === $lead->provider_location_key) {
            return;
        }

        MatchProviderLocation::dispatch((int) $lead->tenant_id, (int) $lead->getKey())->afterCommit();
    }

    /** Matches the lead's address to the provider's place and keeps it on the lead. Returns the state. */
    public function match(Lead $lead, bool $force = false, ?CarrierProvider $provider = null): ?string
    {
        $key = self::key($lead);

        if ($key === null) {
            return null;
        }

        if (! $force && $key === $lead->provider_location_key && $lead->provider_location_state !== null) {
            return $lead->provider_location_state;
        }

        $provider ??= Carriers::for($this->current->get());

        if ($provider === null) {
            return null;
        }

        $parts = (array) $lead->shipping_address_parts;

        if ($correction = $this->correction($provider->key(), $parts)) {
            $correction->increment('used_count');

            $place = filled($correction->location['id'] ?? null) ? CarrierLocation::fromArray($correction->location)
                : new CarrierLocation($correction->location_id, $correction->location_label, null, null, null, $correction->location_postal);

            return $this->keep($lead, $key, self::EXACT, $place, [], 'Pilihan agen sebelumnya untuk kelurahan ini.');
        }

        try {
            [$state, $place, $candidates, $note] = $this->find($provider, $parts);
        } catch (CarrierException $e) {
            // Not kept: tried again with the next change or by shipping:locations --match.
            Log::warning('Provider location match failed', ['tenant' => $this->current->id(), 'lead' => $lead->getKey(), 'reason' => $e->getMessage()]);

            return $lead->provider_location_state;
        }

        // The provider's province is kept with the place (candidates); a different one than ours is noted, never fatal.
        if (($warning = self::provinceDifference($parts + ['provinsi' => $lead->shipping_province], $place, $provider->key())) !== null) {
            $note = $note !== null ? $note.'; '.$warning : $warning;
            Log::warning('Provider location province differs', ['tenant' => $this->current->id(), 'lead' => $lead->getKey(), 'note' => $warning]);
        }

        return $this->keep($lead, $key, $state, $place, $candidates, $note);
    }

    /**
     * 'provinsi Everpro "Jawa Tengah" berbeda dari provinsi kita "Jawa Barat"', or null when they agree or one is
     * unknown. Our province may be derived from the city code (forms that start at the city).
     */
    public static function provinceDifference(array $parts, ?CarrierLocation $place, string $provider = 'everpro'): ?string
    {
        $ours = trim((string) ($parts['provinsi'] ?? ''));
        $theirs = trim((string) $place?->province);

        if ($ours === '' || $theirs === '') {
            return null;
        }

        $canonical = fn (string $name) => self::norm(Regions::province($name) ?? $name);

        return $canonical($ours) === $canonical($theirs) ? null
            : 'provinsi '.Carriers::label($provider).' "'.$theirs.'" berbeda dari provinsi kita "'.$ours.'"';
    }

    /**
     * @return array{0: string, 1: ?CarrierLocation, 2: list<CarrierLocation>, 3: ?string}
     */
    public function find(CarrierProvider $provider, array $parts): array
    {
        $postal = preg_match('/^\d{5}$/', $p = preg_replace('/\D/', '', (string) ($parts['kode_pos'] ?? ''))) ? $p : null;
        $kelurahan = self::norm($parts['kelurahan'] ?? null);
        $kecamatan = self::norm($parts['kecamatan'] ?? null);
        $area = implode(', ', array_filter([$parts['kelurahan'] ?? null, $parts['kecamatan'] ?? null, $parts['kota'] ?? null], fn ($v) => is_string($v) && trim($v) !== ''));
        $notes = [];

        $byPostal = $postal !== null ? $provider->locations($postal) : [];

        if ($byPostal === [] && $area !== '') {
            $byPostal = $provider->locations(null, $area);

            if ($postal !== null && $byPostal !== []) {
                $notes[] = 'kode pos '.$postal.' tidak dikenal '.Carriers::label($provider->key()).'; dicocokkan dari nama';
            }
        }

        if ($byPostal === []) {
            return [self::NONE, null, [], $postal === null && $area === '' ? 'alamat belum ada kode pos atau kelurahan' : 'tidak ada lokasi yang cocok'];
        }

        // 1. Same kelurahan (a small spelling difference allowed), in the same kecamatan when both say one.
        $sameKec = fn (CarrierLocation $l) => $kecamatan === '' || $l->district === null || self::same(self::norm($l->district), $kecamatan);
        $exact = collect($byPostal)->filter(fn (CarrierLocation $l) => $kelurahan !== '' && self::norm($l->subDistrict) === $kelurahan && $sameKec($l))->values();
        $close = $exact->isNotEmpty() ? $exact : collect($byPostal)->filter(fn (CarrierLocation $l) => $kelurahan !== '' && self::same(self::norm($l->subDistrict), $kelurahan) && $sameKec($l))->values();

        if ($close->count() === 1) {
            $place = $close->first();

            if ($exact->isEmpty()) {
                $notes[] = 'ejaan berbeda: "'.($parts['kelurahan'] ?? '').'" = "'.$place->subDistrict.'"';
            }

            return [self::EXACT, $place, $byPostal, $this->notes($notes, $postal, $place)];
        }

        if ($close->count() > 1) {
            return [self::MULTIPLE, null, $close->all(), $this->notes([...$notes, $close->count().' lokasi sama cocoknya'], $postal, null)];
        }

        // 2. Same kecamatan, only one place of it under the postal code.
        $inKec = collect($byPostal)->filter(fn (CarrierLocation $l) => $kecamatan !== '' && $l->district !== null && self::same(self::norm($l->district), $kecamatan))->values();

        if ($inKec->count() === 1 && $kelurahan === '') {
            return [self::EXACT, $inKec->first(), $byPostal, $this->notes([...$notes, 'dicocokkan dari kecamatan'], $postal, $inKec->first())];
        }

        if ($inKec->count() === 1 && count($byPostal) === 1) {
            $notes[] = 'kelurahan berbeda: "'.($parts['kelurahan'] ?? '').'" vs "'.$inKec->first()->subDistrict.'"';

            return [self::EXACT, $inKec->first(), $byPostal, $this->notes($notes, $postal, $inKec->first())];
        }

        if ($kelurahan === '' && $kecamatan === '' && count($byPostal) === 1) {
            return [self::EXACT, $byPostal[0], $byPostal, $this->notes([...$notes, 'hanya satu lokasi untuk kode pos ini'], $postal, $byPostal[0])];
        }

        $list = $inKec->isNotEmpty() ? $inKec->all() : $byPostal;

        return [self::MULTIPLE, null, array_slice($list, 0, 20), $this->notes([...$notes, 'kelurahan "'.($parts['kelurahan'] ?? '-').'" tidak ditemukan pasti'], $postal, null)];
    }

    /**
     * The agent's choice: kept on the lead ("pasti") and in the agency's corrections, so the same kelurahan is
     * not asked again.
     */
    public function choose(Lead $lead, CarrierLocation $place, ?User $by = null): void
    {
        $parts = (array) $lead->shipping_address_parts;
        $region = self::regionKey($parts);
        $provider = Carriers::account($this->current->get())?->provider ?? 'everpro';

        if ($region !== null) {
            ProviderLocationCorrection::query()->updateOrCreate(['provider' => $provider, 'region_key' => $region], [
                'postal_code' => preg_replace('/\D/', '', (string) ($parts['kode_pos'] ?? '')) ?: null,
                'kelurahan' => mb_substr((string) ($parts['kelurahan'] ?? ''), 0, 120) ?: null, 'kecamatan' => mb_substr((string) ($parts['kecamatan'] ?? ''), 0, 120) ?: null,
                'kota' => mb_substr((string) ($parts['kota'] ?? ''), 0, 120) ?: null,
                'location_id' => $place->id, 'location_label' => mb_substr($place->label(), 0, 250), 'location_postal' => $place->postalCode, 'location' => $place->toArray(), 'user_id' => $by?->getKey(),
            ]);
        }

        $this->keep($lead, self::key($lead) ?? '', self::EXACT, $place, (array) $lead->provider_location_candidates ? array_map(CarrierLocation::fromArray(...), (array) $lead->provider_location_candidates) : [],
            'Dipilih '.($by?->name ?? 'agen').'.');
        $lead->activities()->create(['user_id' => $by?->getKey(), 'type' => 'note', 'body' => 'Lokasi '.Carriers::label($provider).' dipilih: '.$place->label().'. Dipakai lagi untuk alamat di kelurahan yang sama.']);
    }

    /** The stored place, when certain. */
    public static function place(Lead $lead): ?CarrierLocation
    {
        if ($lead->provider_location_state !== self::EXACT || blank($lead->provider_location_id) || self::key($lead) !== $lead->provider_location_key) {
            return null;
        }

        $known = collect((array) $lead->provider_location_candidates)->first(fn ($c) => (string) ($c['id'] ?? '') === (string) $lead->provider_location_id);

        return $known ? CarrierLocation::fromArray($known) : new CarrierLocation((string) $lead->provider_location_id, (string) $lead->provider_location_label, null, null, null, null);
    }

    /** @return list<CarrierLocation> the stored candidates (to choose from) */
    public static function candidates(Lead $lead): array
    {
        return array_map(CarrierLocation::fromArray(...), array_values(array_filter((array) $lead->provider_location_candidates, fn ($c) => filled($c['id'] ?? null))));
    }

    private function correction(string $provider, array $parts): ?ProviderLocationCorrection
    {
        $region = self::regionKey($parts);

        return $region !== null ? ProviderLocationCorrection::query()->where('provider', $provider)->where('region_key', $region)->first() : null;
    }

    /** @param  list<CarrierLocation>  $candidates */
    private function keep(Lead $lead, string $key, string $state, ?CarrierLocation $place, array $candidates, ?string $note): string
    {
        $values = [
            'provider_location_state' => $state, 'provider_location_id' => $place?->id, 'provider_location_label' => $place ? mb_substr($place->label(), 0, 250) : null,
            'provider_location_candidates' => array_map(fn (CarrierLocation $l) => $l->toArray(), array_slice(array_values(array_unique(array_filter([$place, ...$candidates]), SORT_REGULAR)), 0, 20)),
            'provider_location_note' => $note !== null ? mb_substr($note, 0, 500) : null, 'provider_location_key' => $key, 'provider_location_at' => now(),
        ];
        // Written straight to the row: no stage, payment or conversion hooks for a lookup.
        Lead::query()->whereKey($lead->getKey())->update(['provider_location_candidates' => json_encode($values['provider_location_candidates'])] + $values);
        $lead->forceFill($values)->syncOriginalAttributes(array_keys($values));

        return $state;
    }

    private function notes(array $notes, ?string $postal, ?CarrierLocation $place): ?string
    {
        if ($postal !== null && $place?->postalCode !== null && $place->postalCode !== $postal) {
            $notes[] = 'kode pos kita '.$postal.', '.config('carriers.labels.everpro', 'Everpro').' '.$place->postalCode;
        }

        return $notes === [] ? null : implode('; ', $notes);
    }

    /** "Kel. Kedung Waringin" => "kedungwaringin" */
    public static function norm(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/^(kel(urahan)?|desa|ds|kec(amatan)?|kab(upaten)?|kota|kodya)\.?\s+/u', '', $name);

        return preg_replace('/[^a-z0-9]+/u', '', $name);
    }

    /** Equal, or one small spelling difference in a long enough name ("cibeunying" / "cibeuying"). */
    private static function same(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || (min(strlen($a), strlen($b)) >= 6 && levenshtein($a, $b) <= (max(strlen($a), strlen($b)) >= 10 ? 2 : 1));
    }
}
