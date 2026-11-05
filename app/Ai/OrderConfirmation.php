<?php

namespace App\Ai;

use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Models\WaMessage;
use App\Shipping\ShippingSettings;

/**
 * A buyer's "ok" to the order summary the chat just sent. The order is built from that summary: the order the AI
 * drafted for it, else what the lead holds (form or landing page), with the way of paying and the courier from
 * the lead or the summary text. GenerateAiReply records it like the AI's own catat_pesanan (status konfirmasi),
 * so StageAutomation moves the lead once the reply is really sent. Must run inside the tenant.
 */
class OrderConfirmation
{
    /** A message that summarises an order: money and words like total, ringkasan, rincian, konfirmasi. */
    private const SUMMARY = '/\b(?:total|ringkasan|rincian|konfirmasi|pesanan(?:nya)?|order(?:an)?)\b/iu';

    /** How long a summary waits for its "ok". */
    private const WITHIN_HOURS = 72;

    /**
     * @param  array<string, mixed>  $input  catat_pesanan input, status konfirmasi
     * @param  list<string>  $missing  what the buyer still has to choose, in words
     */
    public function __construct(public readonly array $input, public readonly array $missing) {}

    public static function pending(Tenant $tenant, Lead $lead, WaMessage $inbound): ?self
    {
        $summary = WaMessage::query()->where('conversation_id', $inbound->conversation_id)->where('direction', 'out')
            ->where('id', '<', $inbound->getKey())->latest('id')->first();

        if ($summary === null || $summary->created_at?->lt(now()->subHours(self::WITHIN_HOURS)) || ! self::summarises((string) $summary->body)) {
            return null;
        }

        // Already recorded after that summary: a second "ok" records nothing.
        if (LeadActivity::query()->where('lead_id', $lead->getKey())->where('type', 'order_recorded')
            ->where('created_at', '>=', $summary->created_at)->exists()) {
            return null;
        }

        $text = (string) $summary->body;
        $draft = self::draftedOrder($inbound);
        $destination = LeadQuote::destination($lead);
        $input = array_filter([
            'items' => $draft['items'] ?? LeadQuote::items($lead),
            'nama' => $draft['nama'] ?? $lead->name,
            'alamat' => $draft['alamat'] ?? $lead->shipping_address,
            'kota' => $draft['kota'] ?? $destination['kota'] ?? null,
            'provinsi' => $draft['provinsi'] ?? $destination['provinsi'] ?? null,
            'kecamatan' => $draft['kecamatan'] ?? $destination['kecamatan'] ?? null,
            'kurir' => $draft['kurir'] ?? self::courier($tenant, $lead, $text),
            'pembayaran' => $draft['pembayaran'] ?? self::payment($lead, $text),
        ], fn ($v) => filled($v));

        $missing = array_keys(array_filter([
            'produk' => blank($input['items'] ?? null),
            'alamat kirim' => blank($input['alamat'] ?? null) || blank($input['kota'] ?? null),
            'kurir' => blank($input['kurir'] ?? null),
            'cara bayar' => blank($input['pembayaran'] ?? null),
        ]));

        return new self($input + ['status' => 'konfirmasi'], $missing);
    }

    public static function summarises(string $text): bool
    {
        return ShopTools::amounts($text) !== [] && preg_match(self::SUMMARY, $text) === 1;
    }

    /** What the model is told before it writes the reply. */
    public static function recordedNote(array $result, string $payment): string
    {
        return implode("\n", [
            'PESANAN SUDAH DICATAT oleh sistem karena pembeli menyetujui ringkasan pesanan. Hasil '.ShopTools::RECORD.':',
            json_encode($result, JSON_UNESCAPED_UNICODE),
            'Balas konfirmasi singkat, satu atau dua kalimat, dengan total dari hasil ini. '.($payment === 'cod'
                ? 'Bayar di tempat (COD): sampaikan total dibayar saat paket diterima dan pesanan segera dikemas.'
                : 'Transfer: minta pembeli mentransfer sebesar total (rekening sesuai PENGETAHUAN). Jangan menyatakan pembayaran sudah diterima.')
            .' Jangan memanggil '.ShopTools::RECORD.' lagi, jangan melampirkan foto, dan jangan menyerahkan ke admin.',
        ]);
    }

    /** The note when the buyer said yes but something must still be chosen. */
    public static function missingNote(array $missing): string
    {
        return 'Pembeli menyetujui ringkasan pesanan, tetapi '.implode(' dan ', $missing).' belum diketahui. Tanyakan itu dalam satu pesan singkat'
            .(in_array('cara bayar', $missing, true) ? ' (cara bayar: transfer atau COD)' : '').'. Jangan mencatat pesanan dulu dan jangan menyerahkan ke admin.';
    }

    /** What is sent when the model cannot write the reply: the order is never left unanswered. */
    public static function fallback(?array $result, string $payment, array $missing, Tenant $tenant): string
    {
        if ($result !== null) {
            return 'Siap kak, pesanannya sudah kami catat. '.(isset($result['total_teks']) ? 'Totalnya '.$result['total_teks'].($payment === 'cod' ? ' dibayar saat paket diterima (COD)' : '').'. ' : '')
                .($payment === 'cod' ? 'Pesanan segera kami kemas ya kak 🙏' : 'Silakan transfer sesuai total, nanti admin kami cek dulu ya kak 🙏');
        }

        $couriers = ShippingSettings::of($tenant)->couriers();

        return 'Siap kak. '.implode(' ', array_filter([
            in_array('kurir', $missing, true) ? 'Mau pakai kurir apa'.($couriers !== [] ? ' ('.implode(' atau ', $couriers).')' : '').'?' : null,
            in_array('cara bayar', $missing, true) ? 'Bayarnya mau transfer atau COD (bayar di tempat)?' : null,
            in_array('alamat kirim', $missing, true) ? 'Boleh dikirim alamat lengkapnya (jalan, nomor rumah, RT/RW, kelurahan, kecamatan, kota)?' : null,
            in_array('produk', $missing, true) ? 'Produk dan jumlahnya yang mana ya kak?' : null,
        ]));
    }

    /** What the buyer still has to give when recording failed, in words; null when a person must take over. */
    public static function problem(array $result): ?array
    {
        return match ($result['status'] ?? null) {
            'kurir_belum_dipilih' => ['kurir'],
            'alamat_belum_lengkap', 'tujuan_belum_jelas' => ['alamat kirim'],
            default => null,
        };
    }

    /** The order the AI drafted in this chat (catat_pesanan status draft), most recent first. */
    private static function draftedOrder(WaMessage $inbound): ?array
    {
        $drafts = AiDraft::query()->where('conversation_id', $inbound->conversation_id)->where('message_id', '<', $inbound->getKey())
            ->whereNotNull('actions')->latest('id')->limit(5)->get();

        foreach ($drafts as $draft) {
            foreach (array_reverse((array) $draft->actions) as $action) {
                if (($action['type'] ?? null) === 'order' && is_array($action['input'] ?? null)) {
                    return array_diff_key($action['input'], ['status' => 1]);
                }
            }
        }

        return null;
    }

    private static function payment(Lead $lead, string $summary): ?string
    {
        if (in_array($lead->payment_type, ShopTools::PAYMENTS, true)) {
            return $lead->payment_type;
        }

        $cod = preg_match('/\bCOD\b|bayar\s+di\s+tempat/iu', $summary) === 1;
        $transfer = preg_match('/\btransfer\b|\bTF\b/iu', $summary) === 1;

        return ($cod xor $transfer) ? ($cod ? 'cod' : 'transfer') : null;
    }

    private static function courier(Tenant $tenant, Lead $lead, string $summary): ?string
    {
        if (filled($lead->courier)) {
            return $lead->courier;
        }

        $couriers = ShippingSettings::of($tenant)->couriers();
        $named = array_values(array_filter($couriers, fn ($c) => mb_stripos($summary, $c) !== false));

        return match (true) {
            count($named) === 1 => $named[0],
            count($couriers) === 1 => $couriers[0],
            default => null,
        };
    }
}
