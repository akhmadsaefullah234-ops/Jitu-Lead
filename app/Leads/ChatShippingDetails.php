<?php

namespace App\Leads;

use App\Automation\RetailStages;
use App\Catalog\ProductMatcher;
use App\LeadForms\FormFields;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\WaMessage;
use App\Regions\RegionDirectory;
use App\Shipping\Regions;
use App\Support\Industry;
use Illuminate\Support\Collection;

/**
 * Keeps a shop lead's order details in step with the chat, whoever answers it (the AI or a person): each buyer
 * message with a format order, an address, or a piece of one (RT/RW, landmark, house number, kelurahan,
 * kecamatan, kota, postal code), a colour or variant, a quantity or a way of paying updates the lead. Pieces
 * complete the address already there; a different full address replaces it and the old one is kept in the
 * lead's activity. The address is also split into shipping_address_parts and placed with the imported regions,
 * so "Buat resi" finds the kelurahan. Nothing is written once the parcel has a tracking number.
 *
 * Nothing here logs the buyer's details. Must run inside the tenant.
 */
class ChatShippingDetails
{
    public const ACTIVITY = 'Alamat diperbarui dari chat';

    /** How far back a whole conversation is read. */
    private const MAX_MESSAGES = 300;

    private const LABELS = [
        'name' => 'nama', 'shipping_address' => 'alamat', 'rt_rw' => 'RT/RW', 'house' => 'nomor rumah', 'landmark' => 'patokan', 'dusun' => 'dusun',
        'street' => 'jalan', 'kelurahan' => 'kelurahan', 'kecamatan' => 'kecamatan', 'kota' => 'kota', 'provinsi' => 'provinsi', 'kode_pos' => 'kode pos',
        'variant' => 'varian', 'quantity' => 'jumlah', 'payment_type' => 'cara bayar', 'property_id' => 'produk',
    ];

    private const ADDRESS_KEYS = ['shipping_address', 'rt_rw', 'house', 'landmark', 'dusun', 'street', 'kelurahan', 'kecamatan', 'kota', 'provinsi', 'kode_pos'];

    public function __construct(private ChatOrderParser $parser, private ProductMatcher $products) {}

    /** One message the buyer just sent. */
    public function fromMessage(Lead $lead, WaMessage $message): ?array
    {
        if ($message->direction !== 'in' || $message->type !== 'text' || blank($message->body) || ! $this->applies($lead)) {
            return null;
        }

        $asked = WaMessage::query()->where('conversation_id', $message->conversation_id)->where('direction', 'out')
            ->where('id', '<', $message->getKey())->latest('id')->value('body');
        $parsed = $this->parser->parse((string) $message->body, $asked);

        if ($parsed === []) {
            return null;
        }

        return $this->finish($lead, $this->apply($this->state($lead), $parsed), save: true);
    }

    /**
     * The whole conversation once, oldest first (moving to Dikemas & dikirim, "Ambil dari chat", the backfill).
     *
     * @return array{changes: array<string, mixed>, labels: list<string>, previous: list<string>}|null
     */
    public function fromConversation(Lead $lead, bool $save = true): ?array
    {
        if (! $this->applies($lead)) {
            return null;
        }

        $conversation = $lead->conversation()->first();
        $state = $this->state($lead);

        if ($conversation !== null) {
            $messages = WaMessage::query()->where('conversation_id', $conversation->getKey())->whereIn('direction', ['in', 'out'])
                ->latest('id')->limit(self::MAX_MESSAGES)->get(['id', 'direction', 'type', 'body'])->reverse();
            $asked = null;

            foreach ($messages as $message) {
                if ($message->direction === 'out') {
                    $asked = $message->body;
                } elseif ($message->type === 'text' && filled($message->body)) {
                    $state = $this->apply($state, $this->parser->parse((string) $message->body, $asked));
                }
            }
        }

        // An address typed in one piece (by the AI's catat_pesanan or a form) is split too.
        if (filled($state['shipping_address'])) {
            $state = $this->apply($state, ['address' => $state['shipping_address'], 'parts' => $this->parser->addressParts($state['shipping_address']), 'fill' => true]);
        }

        return $this->finish($lead, $state, $save);
    }

    /**
     * The parts of the address the lead already holds (an order the AI recorded): split and placed, without
     * changing the address text. Quiet: no activity.
     */
    public function structure(Lead $lead): void
    {
        if (blank($lead->shipping_address) || ! $this->applies($lead)) {
            return;
        }

        $parsed = ['address' => $lead->shipping_address, 'parts' => $this->parser->addressParts($lead->shipping_address), 'fill' => true];
        $this->finish($lead, $this->apply($this->state($lead), $parsed), save: true, activity: false);
    }

    /** What "Buat resi" needs from the address: the text, and a kecamatan or postal code. */
    public static function complete(Lead $lead): bool
    {
        $parts = (array) $lead->shipping_address_parts;

        return mb_strlen(trim((string) $lead->shipping_address)) >= 10 && (filled($parts['kode_pos'] ?? null) || filled($parts['kecamatan'] ?? null)) && filled($parts['kelurahan'] ?? null);
    }

    /** Moving into Dikemas & dikirim with the address incomplete: read the chat once. */
    public function enteringShipping(Lead $lead): void
    {
        if (! self::complete($lead) && (new RetailStages)->is($lead->stage, RetailStages::SHIPPING)) {
            $this->fromConversation($lead);
        }
    }

    private function applies(Lead $lead): bool
    {
        return Industry::current()->usesLeadField('shipping_address') && blank($lead->tracking_number)
            && ! Shipment::query()->where('lead_id', $lead->getKey())->whereNotNull('awb')->exists();
    }

    private function state(Lead $lead): array
    {
        return [
            'name' => $lead->name, 'shipping_address' => $lead->shipping_address, 'parts' => array_diff_key((array) $lead->shipping_address_parts, ['codes' => 1]),
            'codes' => ((array) $lead->shipping_address_parts)['codes'] ?? null,
            'variant' => $lead->variant, 'quantity' => $lead->quantity, 'payment_type' => $lead->payment_type, 'product' => null,
            'previous' => [], 'given' => [],
        ];
    }

    /** Merges one parsed message into the running state: the latest word wins. */
    private function apply(array $state, array $parsed): array
    {
        if ($parsed === []) {
            return $state;
        }

        $parts = (array) ($parsed['parts'] ?? []);

        if (filled($parsed['address'] ?? null)) {
            $old = trim((string) $state['shipping_address']);
            $new = trim((string) $parsed['address']);

            if ($old === '') {
                $state['shipping_address'] = $new;
                $state['parts'] = $parts + $state['parts'];
            } elseif (self::same($old, $new)) {
                // The same address again (perhaps longer): keep the fuller text, add what it adds. Splitting the
                // lead's own text only fills the gaps.
                $state['shipping_address'] = mb_strlen(self::key($new)) > mb_strlen(self::key($old)) ? $new : $old;
                $state['parts'] = ($parsed['fill'] ?? false) ? $state['parts'] + $parts : $parts + $state['parts'];
            } else {
                $state['previous'][] = $old;
                $state['shipping_address'] = $new;
                $state['parts'] = $parts;
                $state['codes'] = null;
            }
        } elseif ($parts !== []) {
            $added = array_filter($parts, fn ($v, $k) => self::key((string) ($state['parts'][$k] ?? '')) !== self::key((string) $v), ARRAY_FILTER_USE_BOTH);
            $state['parts'] = $parts + $state['parts'];
            $state['given'] = array_unique([...$state['given'], ...array_keys($added)]);
            $state['shipping_address'] = $this->withPieces((string) $state['shipping_address'], $added, $state['parts']);
        }

        foreach (['name' => 'name', 'variant' => 'variant', 'quantity' => 'quantity', 'payment' => 'payment_type', 'product' => 'product'] as $from => $to) {
            if (filled($parsed[$from] ?? null)) {
                $state[$to] = $parsed[$from];
            }
        }

        return $state;
    }

    /** The address with the pieces added later written in: RT/RW, number and landmark after the street, places at the end. */
    private function withPieces(string $address, array $added, array $parts): string
    {
        $address = trim($address);

        if ($address === '') {
            $composed = FormFields::address($parts, array_keys(FormFields::ADDRESS_PARTS));

            return mb_strlen($composed) >= 10 ? $composed : '';
        }

        $near = [];
        $far = [];
        $has = fn (string $v) => str_contains(self::key($address), self::key($v));

        foreach ($added as $key => $value) {
            $value = trim((string) $value);

            match ($key) {
                'rt_rw' => preg_match('/\bRT\b/iu', $address)
                    ? $address = preg_replace('/\bRT\s*\/?\s*RW\.?\s*[:.]?\s*\d{1,3}\s*[\/\-.]\s*\d{1,3}|\bRT\.?\s*[:.]?\s*\d{1,3}(?:\s*(?:[\/,\-]\s*|\s+)(?:RW\.?\s*[:.]?\s*)?\d{1,3})?/iu', 'RT/RW '.$value, $address, 1)
                    : $near[] = 'RT/RW '.$value,
                'house' => preg_match('/\b(?:no|nomor)\b\.?\s*\d/iu', $address) ? null : $near[] = 'No. '.$value,
                'landmark' => $has($value) ? null : $near[] = '('.$value.')',
                'dusun' => $has($value) ? null : $near[] = 'Dusun '.$value,
                'kelurahan' => $has($value) ? null : $far[] = 'Kel. '.$value,
                'kecamatan' => $has($value) ? null : $far[] = 'Kec. '.$value,
                'kota', 'provinsi', 'kode_pos' => $has($value) ? null : $far[] = $value,
                default => null,
            };
        }

        if ($near !== []) {
            // After the street and the pieces already written after it.
            $segments = array_map('trim', explode(',', $address));
            $at = 1;

            while (isset($segments[$at]) && preg_match('/^(?:RT\/RW\b|RT\b|No\.|\(|Dusun\b)/iu', $segments[$at])) {
                $at++;
            }

            array_splice($segments, $at, 0, $near);
            $address = implode(', ', $segments);
        }

        return implode(', ', array_filter([$address, ...$far], fn ($p) => $p !== ''));
    }

    /**
     * Places the address with the regions, writes what changed, and notes it on the lead.
     *
     * @return array{changes: array<string, mixed>, labels: list<string>, previous: list<string>}
     */
    private function finish(Lead $lead, array $state, bool $save, bool $activity = true): array
    {
        $parts = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $state['parts']), fn ($v) => $v !== '' && $v !== null);
        $found = RegionDirectory::locate($parts);

        foreach (['kelurahan', 'kecamatan', 'kota', 'provinsi', 'kode_pos'] as $key) {
            $written = RegionDirectory::searchKey($parts[$key] ?? '');

            // Filled when missing; the official spelling when it is what was written ("cibinong bogor" => "Cibinong").
            if (isset($found[$key]) && ($written === '' || str_starts_with($written, RegionDirectory::searchKey($found[$key])) || $key === 'provinsi')) {
                $parts[$key] = $found[$key];
            }
        }

        if (! isset($parts['provinsi']) && isset($parts['kota'])) {
            $parts['provinsi'] = Regions::provinceOfCity(RegionDirectory::searchKey($parts['kota'])) ?? null;
            $parts = array_filter($parts, fn ($v) => $v !== null);
        }

        $changes = [];
        $original = array_diff_key((array) $lead->shipping_address_parts, ['codes' => 1]);

        if ($parts != $original) {
            $changes['shipping_address_parts'] = ($parts === [] ? null : $parts + array_filter(['codes' => $state['codes']]));
        }

        if (trim((string) $state['shipping_address']) !== trim((string) $lead->shipping_address) && filled($state['shipping_address'])) {
            $changes['shipping_address'] = mb_substr(trim((string) $state['shipping_address']), 0, 1000);
        }

        if (filled($parts['provinsi'] ?? null) && ($province = Regions::province($parts['provinsi']) ?? $parts['provinsi']) !== $lead->shipping_province) {
            $changes['shipping_province'] = $province;
        }

        if (filled($state['name']) && mb_strtolower(trim($state['name'])) !== mb_strtolower(trim((string) $lead->name))) {
            $changes['name'] = $state['name'];
        }

        foreach (['variant', 'quantity', 'payment_type'] as $field) {
            if (filled($state[$field]) && (string) $state[$field] !== (string) $lead->{$field}) {
                $changes[$field] = $state[$field];
            }
        }

        if (filled($state['product']) && $lead->property_id === null && ($match = $this->products->match(null, $state['product'])) !== null) {
            $changes['property_id'] = $match['product']->getKey();
        }

        $items = (array) $lead->order_items;

        if (count($items) === 1 && (isset($changes['variant']) || isset($changes['quantity']))) {
            $items[0] = array_merge($items[0], array_filter(['variant' => $changes['variant'] ?? null, 'quantity' => isset($changes['quantity']) ? (int) $changes['quantity'] : null]));
            $changes['order_items'] = $items;
        }

        $labels = $this->labels($changes, $original, $parts);

        if ($save && $changes !== []) {
            $lead->forceFill($changes + (isset($changes['property_id']) ? ['product_source' => 'chat'] : []))->save();

            if ($activity && $labels !== []) {
                $lead->activities()->create([
                    'type' => 'note',
                    'body' => (array_intersect(array_keys($changes), ['shipping_address', 'shipping_address_parts']) !== [] ? self::ACTIVITY : 'Data pesanan diperbarui dari chat')
                        .': '.implode(', ', $labels).'.'
                        .($state['previous'] !== [] ? ' Alamat sebelumnya: '.mb_substr(end($state['previous']), 0, 500) : ''),
                    'meta' => ['from_chat' => array_keys($changes)] + ($state['previous'] !== [] ? ['previous_address' => end($state['previous'])] : []),
                ]);
            }
        }

        return ['changes' => $changes, 'labels' => $labels, 'previous' => $state['previous']];
    }

    /** @return list<string> what changed, in words */
    private function labels(array $changes, array $before, array $after): array
    {
        $out = [];

        foreach ($changes as $field => $value) {
            if ($field === 'shipping_address_parts') {
                foreach ($after as $key => $v) {
                    if (isset(self::LABELS[$key]) && self::key((string) ($before[$key] ?? '')) !== self::key((string) $v)) {
                        $out[] = self::LABELS[$key];
                    }
                }
            } elseif (isset(self::LABELS[$field])) {
                $out[] = self::LABELS[$field];
            }
        }

        return array_values(array_unique($out));
    }

    /** One address written again (perhaps with more or less detail). */
    private static function same(string $a, string $b): bool
    {
        [$a, $b] = [self::key($a), self::key($b)];

        if ($a === '' || $b === '') {
            return false;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        // Most words of the shorter one appear in the longer one.
        $words = fn (string $s) => array_filter(explode(' ', $s), fn ($w) => mb_strlen($w) >= 3);
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$words($a), $words($b)] : [$words($b), $words($a)];

        return $short !== [] && count(array_intersect($short, $long)) / count($short) >= 0.8;
    }

    private static function key(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /** @param  Collection<int, Lead>  $leads */
    public static function incomplete(Collection $leads): Collection
    {
        return $leads->reject(fn (Lead $l) => self::complete($l));
    }
}
