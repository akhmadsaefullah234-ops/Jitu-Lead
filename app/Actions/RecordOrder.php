<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use App\Shipping\Shipping;
use App\Shipping\ShippingItem;
use App\Shipping\ShippingQuote;
use Illuminate\Support\Facades\DB;

/**
 * Writes a confirmed shop order onto its lead: items, variant, quantity, shipping address, courier, the shipping
 * cost and the total, then moves the lead to "Menunggu pembayaran". The shipping cost is always priced again
 * here, so no caller (the AI included) can set it.
 */
class RecordOrder
{
    public const PAYMENT_STAGE = 'Menunggu pembayaran';

    public function __construct(private MoveLeadToStage $move) {}

    /**
     * @param  array{items?: list<array>, alamat?: ?string, kecamatan?: ?string, kota?: ?string, provinsi?: ?string, kurir?: ?string}  $order
     * @return array{status: string, pesan: string, ongkir?: int, total?: ?int, tahap?: ?string}
     */
    public function __invoke(Lead $lead, array $order, User $actor, string $by = 'agen'): array
    {
        $quote = Shipping::quote($lead->tenant, $order['kota'] ?? null, $order['provinsi'] ?? null, $order['items'] ?? [], $order['kecamatan'] ?? null);

        if (! $quote->ok()) {
            return ['status' => $quote->status, 'pesan' => $quote->message];
        }

        $option = $quote->option((string) ($order['kurir'] ?? ''));
        $address = trim((string) ($order['alamat'] ?? ''));

        if ($option === null) {
            return ['status' => 'kurir_belum_dipilih', 'pesan' => 'Kurir belum dipilih. Pilihan: '.implode(', ', array_map(fn ($o) => $o->courier, $quote->options)).'.'];
        }

        if ($address === '') {
            return ['status' => 'alamat_belum_lengkap', 'pesan' => 'Alamat kirim lengkap belum diketahui.'];
        }

        $subtotal = $quote->subtotal();
        $total = $subtotal !== null ? $subtotal + $option->price : null;
        // The city and province are added unless the buyer already wrote them
        $fullAddress = implode(', ', [$address, ...array_filter([$order['kecamatan'] ?? null, $quote->city, $quote->province], fn ($p) => filled($p) && ! str_contains(mb_strtolower($address), mb_strtolower((string) $p)))]);

        $stage = DB::transaction(function () use ($lead, $quote, $option, $fullAddress, $total, $actor, $by) {
            $first = $quote->items[0];

            $lead->fill([
                'property_id' => $first->product?->getKey() ?? $lead->property_id,
                'variant' => implode('; ', array_filter(array_map(fn (ShippingItem $i) => count($quote->items) > 1 ? trim($i->name.' '.$i->variant) : $i->variant, $quote->items))) ?: null,
                'quantity' => array_sum(array_map(fn (ShippingItem $i) => $i->quantity, $quote->items)),
                'shipping_address' => $fullAddress,
                'courier' => $option->courier,
                'shipping_cost' => $option->price,
                'order_items' => array_map(fn (ShippingItem $i) => $i->toArray(), $quote->items),
                'deal_value' => $total ?? $lead->deal_value,
            ]);
            $lead->save();

            $lead->activities()->create([
                'user_id' => $by === 'AI' ? null : $actor->getKey(),
                'type' => 'order_recorded',
                'body' => "Pesanan dicatat oleh {$by}: ".implode(', ', array_map(fn (ShippingItem $i) => trim($i->name.' '.$i->variant).' x'.$i->quantity, $quote->items))
                    .". Ongkir {$option->courier} ".ShippingQuote::rupiah($option->price).($total !== null ? ', total '.ShippingQuote::rupiah($total) : ''),
                'meta' => ['ongkir' => $option->price, 'total' => $total, 'berat_kg' => $quote->billableKg, 'zona' => $quote->zone],
            ]);

            return $this->moveToPayment($lead, $actor);
        });

        return array_filter([
            'status' => 'tercatat',
            'pesan' => 'Pesanan tercatat'.($stage ? ' dan lead dipindah ke tahap '.$stage : '').'.',
            'kurir' => $option->courier,
            'ongkir' => $option->price,
            'ongkir_teks' => ShippingQuote::rupiah($option->price),
            'subtotal_produk' => $subtotal,
            'total' => $total,
            'total_teks' => $total !== null ? ShippingQuote::rupiah($total) : null,
            'tahap' => $stage,
        ], fn ($v) => $v !== null);
    }

    /** Only forward: a lead already past payment, won or lost stays where it is. */
    private function moveToPayment(Lead $lead, User $actor): ?string
    {
        $target = Stage::query()->whereRaw('lower(name) = ?', [mb_strtolower(self::PAYMENT_STAGE)])->first();
        $current = $lead->stage;

        if ($target === null || $target->is($current) || ($current && (! $current->isOpen() || $current->position >= $target->position))) {
            return null;
        }

        ($this->move)($lead, $target, $actor);

        return $target->name;
    }
}
