<?php

namespace App\Actions;

use App\Automation\StageAutomation;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use App\Shipping\Shipping;
use App\Shipping\ShippingItem;
use App\Shipping\ShippingQuote;
use Illuminate\Support\Facades\DB;

/**
 * Writes a shop order onto its lead: items, variant, quantity, shipping address, courier, the shipping cost and
 * the total. A confirmed order moves the lead to "Menunggu pembayaran" (transfer) or "Dikemas & dikirim" (cash
 * on delivery); a draft (details complete, buyer not confirmed yet) moves it to "Order masuk". For shops those
 * moves follow Pengaturan > Otomatisasi tahap (App\Automation\StageAutomation). The shipping cost is always
 * priced again here, so no caller (the AI included) can set it.
 */
class RecordOrder
{
    public const PAYMENT_STAGE = 'Menunggu pembayaran';

    public const DRAFT = 'draft';

    public const PAYMENTS = ['transfer', 'cod'];

    public function __construct(private MoveLeadToStage $move, private StageAutomation $automation) {}

    /**
     * @param  array{items?: list<array>, alamat?: ?string, kecamatan?: ?string, kota?: ?string, provinsi?: ?string, kurir?: ?string, status?: ?string, nama?: ?string, pembayaran?: ?string}  $order
     * @return array{status: string, pesan: string, ongkir?: int, total?: ?int, tahap?: ?string}
     */
    public function __invoke(Lead $lead, array $order, User $actor, string $by = 'agen'): array
    {
        $quote = Shipping::quote($lead->tenant, $order['kota'] ?? null, $order['provinsi'] ?? null, $order['items'] ?? [], $order['kecamatan'] ?? null);

        if (! $quote->ok()) {
            return ['status' => $quote->status, 'pesan' => $quote->message];
        }

        if (($order['status'] ?? null) === self::DRAFT) {
            return $this->draft($lead, $order, $quote, $actor, $by);
        }

        // What the buyer said now, else what the order form already recorded ("other": QRIS, e-wallet), else transfer.
        $payment = in_array($order['pembayaran'] ?? null, self::PAYMENTS, true) ? $order['pembayaran']
            : (in_array($lead->payment_type, [...self::PAYMENTS, 'other'], true) ? $lead->payment_type : 'transfer');

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

        $stage = DB::transaction(function () use ($lead, $quote, $option, $fullAddress, $total, $actor, $by, $order, $payment) {
            $first = $quote->items[0];

            $lead->paymentFromChat = true;
            $lead->fill(array_filter(['name' => filled($order['nama'] ?? null) ? trim($order['nama']) : null]) + [
                'payment_type' => $payment,
                // Chosen in the chat when the buyer confirmed the order, else assumed: confirmed only when said.
                'payment_type_source' => in_array($order['pembayaran'] ?? null, self::PAYMENTS, true) ? null : ($lead->payment_type === $payment ? $lead->payment_type_source : 'chat'),
                'shipping_province' => $quote->province,
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
                    .". Ongkir {$option->courier} ".ShippingQuote::rupiah($option->price)
                    .($option->discount() > 0 ? ' (normal '.ShippingQuote::rupiah($option->normalPrice).', '.$option->promo.')' : '').($total !== null ? ', total '.ShippingQuote::rupiah($total) : '')
                    .match ($payment) {
                        'cod' => '. Bayar di tempat (COD)', 'other' => '. Bayar lainnya (QRIS/e-wallet)', default => '. Bayar transfer'
                    },
                'meta' => ['ongkir' => $option->price, 'ongkir_normal' => $option->discount() > 0 ? $option->normalPrice : null, 'promo_ongkir' => $option->promo, 'total' => $total, 'berat_kg' => $quote->billableKg, 'zona' => $quote->zone, 'pembayaran' => $payment],
            ]);

            return $this->automation->applies() ? $this->automation->orderConfirmed($lead, $payment) : $this->moveToPayment($lead, $actor);
        });

        $this->structure($lead);

        return array_filter([
            'status' => 'tercatat',
            'pesan' => 'Pesanan tercatat'.($stage ? ' dan lead dipindah ke tahap '.$stage : '').'.',
            'kurir' => $option->courier,
            'ongkir' => $option->price,
            'ongkir_teks' => ShippingQuote::rupiah($option->price),
            'potongan_ongkir_teks' => $option->discount() > 0 ? ShippingQuote::rupiah($option->discount()) : null,
            'promo_ongkir' => $option->discount() > 0 ? $option->promo : null,
            'subtotal_produk' => $subtotal,
            'total' => $total,
            'total_teks' => $total !== null ? ShippingQuote::rupiah($total) : null,
            'tahap' => $stage,
        ], fn ($v) => $v !== null);
    }

    /**
     * The buyer has given everything an order needs (name, full address with RT/RW, or a house number or landmark without one,
     * product, colour or size, quantity) but has not confirmed it yet: the details go on the lead and it moves to
     * "Order masuk". Nothing is priced into the lead yet; the courier may not be chosen.
     */
    private function draft(Lead $lead, array $order, ShippingQuote $quote, User $actor, string $by): array
    {
        $address = trim((string) ($order['alamat'] ?? ''));
        $fullAddress = implode(', ', [$address, ...array_filter([$order['kecamatan'] ?? null, $quote->city, $quote->province], fn ($p) => filled($p) && ! str_contains(mb_strtolower($address), mb_strtolower((string) $p)))]);

        $stage = DB::transaction(function () use ($lead, $order, $quote, $fullAddress, $actor, $by) {
            $lead->paymentFromChat = true;
            $lead->fill(array_filter(['name' => filled($order['nama'] ?? null) ? trim($order['nama']) : null]) + [
                'property_id' => $quote->items[0]->product?->getKey() ?? $lead->property_id,
                'variant' => implode('; ', array_filter(array_map(fn (ShippingItem $i) => count($quote->items) > 1 ? trim($i->name.' '.$i->variant) : $i->variant, $quote->items))) ?: null,
                'quantity' => array_sum(array_map(fn (ShippingItem $i) => $i->quantity, $quote->items)),
                'shipping_address' => $fullAddress,
                'shipping_province' => $quote->province,
                'order_items' => array_map(fn (ShippingItem $i) => $i->toArray(), $quote->items),
            ] + (in_array($order['pembayaran'] ?? null, self::PAYMENTS, true) && $order['pembayaran'] !== $lead->payment_type
                // What the buyer said so far, not yet confirmed: "Buat resi" asks the agent.
                ? ['payment_type' => $order['pembayaran'], 'payment_type_source' => 'chat'] : []))->save();

            $lead->activities()->create([
                'user_id' => $by === 'AI' ? null : $actor->getKey(),
                'type' => 'order_draft',
                'body' => "Format order lengkap dicatat oleh {$by}, menunggu konfirmasi pembeli: "
                    .implode(', ', array_map(fn (ShippingItem $i) => trim($i->name.' '.$i->variant).' x'.$i->quantity, $quote->items)).'. Kirim ke '.$fullAddress,
            ]);

            return $this->automation->orderDrafted($lead);
        });

        $this->structure($lead);

        return array_filter([
            'status' => 'draft_tercatat',
            'pesan' => 'Data pesanan dicatat'.($stage ? ' dan lead dipindah ke tahap '.$stage : '').'. Menunggu konfirmasi pembeli.',
            'tahap' => $stage,
        ], fn ($v) => $v !== null);
    }

    /** The address just written, split into its parts and placed, so "Buat resi" finds the kelurahan. */
    private function structure(Lead $lead): void
    {
        try {
            app(ChatShippingDetails::class)->structure($lead);
        } catch (\Throwable $e) {
            report($e);
        }
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
