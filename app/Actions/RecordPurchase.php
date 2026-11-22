<?php

namespace App\Actions;

use App\Ai\DigitalTools;
use App\Automation\DigitalStageAutomation;
use App\Models\Lead;
use App\Models\User;
use App\Payments\PaymentInstructions;
use App\Shipping\ShippingQuote;
use Illuminate\Support\Facades\DB;

/**
 * Writes a digital purchase onto its lead (industry "digital"): product, quantity, the items and the total priced
 * again from the catalogue, the buyer's email and device. No address, courier or shipping cost. A confirmed purchase
 * moves the lead to "Menunggu pembayaran" (Pengaturan > Otomatisasi tahap) and, when the agency filled in its bank
 * accounts, the system sends the transfer instructions. Nothing here marks a purchase paid.
 */
class RecordPurchase
{
    public function __construct(private DigitalStageAutomation $automation) {}

    /**
     * @param  array{items?: list<array>, status?: ?string, nama?: ?string, email?: ?string, perangkat?: ?string}  $purchase
     * @return array{status: string, pesan: string, total?: ?int, tahap?: ?string}
     */
    public function __invoke(Lead $lead, array $purchase, User $actor, string $by = 'agen'): array
    {
        $priced = DigitalTools::price((array) ($purchase['items'] ?? []));

        if ($priced['lines'] === []) {
            return ['status' => 'produk_belum_jelas', 'pesan' => 'Produk tidak ditemukan di katalog.'];
        }

        $confirmed = ($purchase['status'] ?? null) === DigitalTools::CONFIRMED;
        $lines = $priced['lines'];
        $total = $priced['total'];
        $summary = collect($lines)->map(fn ($l) => $l['name'].' x'.$l['quantity'])->implode(', ');
        $email = filled($purchase['email'] ?? null) && filter_var(trim((string) $purchase['email']), FILTER_VALIDATE_EMAIL) ? trim((string) $purchase['email']) : null;

        $stage = DB::transaction(function () use ($lead, $purchase, $lines, $total, $summary, $email, $confirmed, $actor, $by) {
            $lead->paymentFromChat = true;
            $lead->fill(array_filter([
                'name' => filled($purchase['nama'] ?? null) ? trim((string) $purchase['nama']) : null,
                // An email already on the lead is kept: only an empty one is filled from the chat.
                'email' => blank($lead->email) ? $email : null,
                'device' => filled($purchase['perangkat'] ?? null) ? mb_substr(trim((string) $purchase['perangkat']), 0, 60) : null,
            ]) + [
                'property_id' => $lines[0]['product_id'],
                'quantity' => array_sum(array_column($lines, 'quantity')),
                'order_items' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'name' => $l['name'], 'variant' => null, 'quantity' => $l['quantity'], 'price' => $l['price']], $lines),
            ] + ($confirmed ? [
                'payment_type' => 'transfer',
                'payment_type_source' => $lead->payment_type === 'transfer' ? $lead->payment_type_source : 'chat',
                'deal_value' => $total ?? $lead->deal_value,
            ] : []))->save();

            $lead->activities()->create([
                'user_id' => $by === 'AI' ? null : $actor->getKey(),
                'type' => $confirmed ? 'purchase_recorded' : 'purchase_draft',
                'body' => ($confirmed ? "Pembelian dicatat oleh {$by}: " : "Pembelian dicatat oleh {$by}, menunggu konfirmasi pembeli: ")
                    .$summary.($total !== null ? ', total '.ShippingQuote::rupiah($total) : ', harga belum diisi di katalog'),
                'meta' => ['total' => $total, 'items' => $lines],
            ]);

            return $confirmed ? $this->automation->purchaseRecorded($lead) : null;
        });

        // Agencies with an active bank account (Pengaturan > Pembayaran): the system writes and sends the instructions.
        $instruction = $confirmed && $total !== null && $lead->paid_at === null && PaymentInstructions::applies($lead->tenant)
            ? app(PaymentInstructions::class)->send($lead, $total, $actor) : null;

        return array_filter([
            'status' => $confirmed ? 'tercatat' : 'draft_tercatat',
            'pesan' => ($confirmed ? 'Pembelian tercatat' : 'Pembelian dicatat, menunggu konfirmasi pembeli').($stage ? ' dan lead dipindah ke tahap '.$stage : '').'.',
            'total' => $total,
            'total_teks' => $total !== null ? ShippingQuote::rupiah($total) : null,
            'tahap' => $stage,
            'instruksi_bayar' => $instruction['status'] ?? null,
            'nominal_transfer' => $instruction['nominal'] ?? null,
            'kode_unik' => $instruction['kode_unik'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
