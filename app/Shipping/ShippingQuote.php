<?php

namespace App\Shipping;

use App\Models\Property;
use App\Support\PlainData;

/**
 * The answer to a shipping question. Either prices per courier, or the reason there are none: the destination
 * is not clear enough yet, or the agency wants an admin to confirm the cost for that area.
 */
final class ShippingQuote
{
    public const OK = 'ok';

    public const UNCLEAR_DESTINATION = 'tujuan_belum_jelas';

    public const NEEDS_ADMIN = 'perlu_konfirmasi_admin';

    public const NOT_AVAILABLE = 'tidak_tersedia';

    /**
     * @param  list<ShippingOption>  $options
     * @param  list<ShippingItem>  $items
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $options = [],
        public readonly ?string $city = null,
        public readonly ?string $province = null,
        public readonly ?string $zone = null,
        public readonly int $actualGrams = 0,
        public readonly int $volumeGrams = 0,
        public readonly int $billableKg = 0,
        public readonly array $items = [],
        /** Where the prices come from: ShippingSettings::ZONE or ShippingSettings::EVERPRO. */
        public readonly string $source = ShippingSettings::ZONE,
        /** Why the zone table was used for a shop that prices with Everpro ("kode pos tujuan belum diketahui"). */
        public readonly ?string $sourceNote = null,
        /** Set when only the shop's primary courier was priced because the buyer asked for no other. */
        public readonly ?string $primaryCourier = null,
    ) {}

    /** The same quote with some values changed (named as in the constructor). */
    public function copy(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** "Everpro" or "tabel zona Jabodetabek", with why the zone table was used when the shop prices with Everpro. */
    public function sourceLabel(): string
    {
        return $this->source === ShippingSettings::EVERPRO
            ? 'Everpro'
            : trim('tabel zona '.$this->zone).($this->sourceNote ? ' ('.$this->sourceNote.')' : '');
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /** Sum of the item prices, or null when any item has no price in the catalogue. */
    public function subtotal(): ?int
    {
        $sum = 0;

        foreach ($this->items as $item) {
            if ($item->price() === null) {
                return null;
            }
            $sum += $item->price() * $item->quantity;
        }

        return $sum;
    }

    public function option(string $courier): ?ShippingOption
    {
        $key = mb_strtolower(trim($courier));

        foreach ($this->options as $option) {
            if (mb_strtolower($option->courier) === $key) {
                return $option;
            }
        }

        // "JNE" for "JNE REG" when only one JNE service is offered
        $matches = array_values(array_filter($this->options, fn (ShippingOption $o) => str_starts_with(mb_strtolower($o->courier), $key)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function destination(): string
    {
        return implode(', ', array_filter([$this->city, $this->province]));
    }

    /** Ready-to-send text for a chat. */
    public function summary(): string
    {
        if (! $this->ok()) {
            return $this->message;
        }

        $lines = ['Ongkir ke '.$this->destination().' (berat '.$this->billableKg.' kg):'];
        $subtotal = $this->subtotal();

        foreach ($this->options as $o) {
            $lines[] = '- '.$o->courier.': '.self::rupiah($o->price).($o->discount() > 0 ? ' (normal '.self::rupiah($o->normalPrice).', '.$o->promo.')' : '').($o->eta() ? ' ('.$o->eta().')' : '')
                .($subtotal !== null ? ', total '.self::rupiah($subtotal + $o->price) : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Every constructor value as plain data (scalars, null and arrays), options and items included, for a cache
     * that keeps no objects (cache.serializable_classes is false). fromData() builds the same quote back. Not
     * toArray(), which is the AI tool's answer and leaves values out.
     *
     * @return array<string, mixed>
     */
    public function toData(): array
    {
        return [
            'status' => $this->status, 'message' => $this->message,
            'options' => array_map(fn (ShippingOption $o) => $o->toData(), $this->options),
            'city' => $this->city, 'province' => $this->province, 'zone' => $this->zone,
            'actualGrams' => $this->actualGrams, 'volumeGrams' => $this->volumeGrams, 'billableKg' => $this->billableKg,
            'items' => array_map(fn (ShippingItem $i) => $i->toData(), $this->items),
            'source' => $this->source, 'sourceNote' => $this->sourceNote, 'primaryCourier' => $this->primaryCourier,
        ];
    }

    /**
     * The quote toData() wrote. Products are looked up again in the current tenant's catalogue (one query).
     *
     * @throws \InvalidArgumentException when a value is missing or of another type
     */
    public static function fromData(array $data): self
    {
        $items = PlainData::rows($data, 'items');
        $ids = array_values(array_unique(array_filter(array_map(fn (array $i) => PlainData::nullableInt($i, 'product_id'), $items), fn ($id) => $id !== null)));
        $products = $ids === [] ? [] : Property::query()->whereKey($ids)->get()->keyBy(fn (Property $p) => $p->getKey())->all();

        return new self(
            status: PlainData::string($data, 'status'),
            message: PlainData::string($data, 'message'),
            options: array_map(fn (array $o) => ShippingOption::fromData($o), PlainData::rows($data, 'options')),
            city: PlainData::nullableString($data, 'city'),
            province: PlainData::nullableString($data, 'province'),
            zone: PlainData::nullableString($data, 'zone'),
            actualGrams: PlainData::int($data, 'actualGrams'),
            volumeGrams: PlainData::int($data, 'volumeGrams'),
            billableKg: PlainData::int($data, 'billableKg'),
            items: array_map(fn (array $i) => ShippingItem::fromData($i, $products), $items),
            source: PlainData::string($data, 'source'),
            sourceNote: PlainData::nullableString($data, 'sourceNote'),
            primaryCourier: PlainData::nullableString($data, 'primaryCourier'),
        );
    }

    /** What the AI tool returns. Every amount the AI may quote is in here, already added up. */
    public function toArray(): array
    {
        $subtotal = $this->subtotal();

        return array_filter([
            'status' => $this->status,
            'pesan' => $this->message,
            'tujuan' => $this->destination() ?: null,
            'zona' => $this->zone,
            'berat_tertagih_kg' => $this->ok() ? $this->billableKg : null,
            'subtotal_produk' => $this->ok() ? $subtotal : null,
            'kurir_utama' => $this->ok() && $this->primaryCourier !== null ? $this->primaryCourier : null,
            'catatan_kurir' => $this->ok() && $this->primaryCourier !== null
                ? 'Hanya kurir utama toko. Pakai kurir ini tanpa menawarkan atau menanyakan kurir lain; kurir lain dihitung hanya bila pembeli memintanya.' : null,
            'opsi' => $this->ok() ? array_map(fn (ShippingOption $o) => array_filter([
                'kurir' => $o->courier,
                'ongkir' => $o->price,
                'ongkir_teks' => self::rupiah($o->price),
                'ongkir_normal' => $o->discount() > 0 ? $o->normalPrice : null,
                'ongkir_normal_teks' => $o->discount() > 0 ? self::rupiah($o->normalPrice) : null,
                'potongan_ongkir' => $o->discount() > 0 ? $o->discount() : null,
                'potongan_ongkir_teks' => $o->discount() > 0 ? self::rupiah($o->discount()) : null,
                'promo_ongkir' => $o->discount() > 0 ? $o->promo : null,
                'estimasi' => $o->eta(),
                'total' => $subtotal !== null ? $subtotal + $o->price : null,
                'total_teks' => $subtotal !== null ? self::rupiah($subtotal + $o->price) : null,
            ], fn ($v) => $v !== null), $this->options) : null,
        ], fn ($v) => $v !== null);
    }

    public static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
