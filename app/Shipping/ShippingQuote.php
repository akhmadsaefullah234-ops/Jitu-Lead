<?php

namespace App\Shipping;

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
    ) {}

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
            $lines[] = '- '.$o->courier.': '.self::rupiah($o->price).($o->eta() ? ' ('.$o->eta().')' : '')
                .($subtotal !== null ? ', total '.self::rupiah($subtotal + $o->price) : '');
        }

        return implode("\n", $lines);
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
            'opsi' => $this->ok() ? array_map(fn (ShippingOption $o) => array_filter([
                'kurir' => $o->courier,
                'ongkir' => $o->price,
                'ongkir_teks' => self::rupiah($o->price),
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
