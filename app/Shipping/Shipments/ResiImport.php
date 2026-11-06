<?php

namespace App\Shipping\Shipments;

use App\Automation\RetailStages;
use App\Models\Lead;
use App\Support\PhoneNumber;

/**
 * "Impor resi": a courier aggregator's export (tracking number, receiver name and phone) matched to this agency's
 * leads. The phone (normalised) decides; among several leads with that number, the one in Dikemas & dikirim without
 * a tracking number wins; still several, or none, the row waits for a person to pick or skip it. Nothing is saved
 * here. Must run inside the tenant.
 */
class ResiImport
{
    public const MATCHED = 'cocok';

    public const AMBIGUOUS = 'ambigu';

    public const UNMATCHED = 'tidak_cocok';

    public const LINKED = 'sudah_terhubung';

    public const INVALID = 'tidak_valid';

    /** Header names an export uses for each column. */
    private const GUESS = [
        'awb' => ['awb', 'no resi', 'nomor resi', 'resi', 'awb number', 'no. resi', 'tracking number', 'no awb', 'airwaybill'],
        'name' => ['nama penerima', 'penerima', 'receiver name', 'receiver', 'nama', 'consignee', 'nama pembeli', 'customer'],
        'phone' => ['hp penerima', 'no hp penerima', 'telepon penerima', 'receiver phone', 'phone', 'no hp', 'hp', 'telepon', 'nomor hp', 'no telp', 'whatsapp'],
        'courier' => ['kurir', 'ekspedisi', 'logistic', 'courier', 'logistik', 'jasa kirim'],
    ];

    /**
     * @param  list<string>  $header
     * @return array{awb: ?int, name: ?int, phone: ?int, courier: ?int}
     */
    public static function guessColumns(array $header): array
    {
        $keys = array_map(fn ($h) => trim(preg_replace('/[_\s]+/', ' ', mb_strtolower($h))), $header);
        $out = [];

        foreach (self::GUESS as $field => $names) {
            $out[$field] = null;

            foreach ($names as $name) {
                $index = array_search($name, $keys, true);

                if ($index === false) {
                    $index = collect($keys)->search(fn ($k) => str_contains($k, $name));
                }

                if ($index !== false && ! in_array($index, $out, true)) {
                    $out[$field] = (int) $index;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<list<string>>  $rows  without the header
     * @param  array{awb: ?int, name: ?int, phone: ?int, courier: ?int}  $columns
     * @return list<array{row: int, awb: string, name: string, phone: string, courier: string, status: string, lead_id: ?int, options: array<int, string>, note: string}>
     */
    public static function match(array $rows, array $columns): array
    {
        $stage = (new RetailStages)->stage(RetailStages::SHIPPING);
        $seen = [];
        $out = [];

        foreach ($rows as $i => $cells) {
            $cell = fn (?int $col) => $col === null ? '' : trim((string) ($cells[$col] ?? ''));
            $awb = Shipments::normalizeAwb($cell($columns['awb']));
            $row = ['row' => $i + 2, 'awb' => $awb, 'name' => $cell($columns['name']), 'phone' => $cell($columns['phone']), 'courier' => $cell($columns['courier']),
                'status' => self::UNMATCHED, 'lead_id' => null, 'options' => [], 'note' => ''];

            if ($awb === '' || ($problem = Shipments::awbProblem($awb))) {
                $out[] = ['status' => self::INVALID, 'note' => $awb === '' ? 'Nomor resi kosong.' : $problem] + $row;

                continue;
            }

            if (isset($seen[$awb])) {
                $out[] = ['status' => self::INVALID, 'note' => 'Resi sama dengan baris '.$seen[$awb].'.'] + $row;

                continue;
            }

            $seen[$awb] = $row['row'];

            if ($owner = Shipments::awbOwner($awb)) {
                $out[] = ['status' => self::LINKED, 'lead_id' => $owner->getKey(), 'note' => 'Sudah terhubung ke '.$owner->name.'.'] + $row;

                continue;
            }

            $phone = PhoneNumber::normalize($row['phone']);
            $byPhone = $phone ? Lead::query()->where('phone', $phone)->orderByDesc('id')->limit(10)->get() : collect();
            $waiting = $byPhone->filter(fn (Lead $l) => $stage !== null && (int) $l->stage_id === (int) $stage->getKey() && blank($l->tracking_number));
            $pick = match (true) {
                $waiting->count() === 1 => $waiting->first(),
                $waiting->isEmpty() && $byPhone->count() === 1 => $byPhone->first(),
                default => null,
            };

            if ($pick !== null) {
                $out[] = ['status' => self::MATCHED, 'lead_id' => $pick->getKey(), 'options' => self::options($byPhone), 'note' => 'Cocok dari nomor HP.'] + $row;

                continue;
            }

            if ($byPhone->count() > 1) {
                $out[] = ['status' => self::AMBIGUOUS, 'options' => self::options($byPhone), 'note' => $byPhone->count().' lead dengan nomor ini; pilih salah satu.'] + $row;

                continue;
            }

            // No lead with that number: the leads waiting for a tracking number to choose from.
            $candidates = $stage === null ? collect() : Lead::query()->where('stage_id', $stage->getKey())->whereNull('tracking_number')->orderByDesc('id')->limit(50)->get();
            $named = $row['name'] !== '' ? $candidates->filter(fn (Lead $l) => mb_strtolower(trim($l->name)) === mb_strtolower($row['name'])) : collect();

            $out[] = ($named->count() === 1
                ? ['status' => self::AMBIGUOUS, 'lead_id' => $named->first()->getKey(), 'note' => 'Nomor HP tidak cocok; nama sama dengan lead ini. Periksa dulu.']
                : ['note' => 'Tidak ada lead dengan nomor HP ini. Pilih lead atau lewati.']) + ['options' => self::options($candidates)] + $row;
        }

        return array_map(fn (array $r) => Shipments::isProviderOrderNo($r['awb']) && ! in_array($r['status'], [self::INVALID, self::LINKED], true)
            ? ['note' => trim($r['note'].' Ini nomor order Everpro: diganti nomor resi asli saat disimpan.')] + $r : $r, $out);
    }

    /** @return array<int, string> lead id => "name · phone · stage" */
    private static function options($leads): array
    {
        return $leads->loadMissing('stage')->mapWithKeys(fn (Lead $l) => [$l->getKey() => trim($l->name.' · '.($l->phone ?? '-').' · '.($l->stage?->name ?? '-').($l->tracking_number ? ' · resi '.$l->tracking_number : ''))])->all();
    }
}
