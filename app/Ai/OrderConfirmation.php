<?php

namespace App\Ai;

use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\WaMessage;
use App\Shipping\ShippingQuote;
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

    /** Told to the model when the draft order of a form message is already written. */
    public const DRAFT_NOTE = 'PESANAN DRAFT SUDAH DICATAT oleh sistem dari formulir pembeli (pesan terakhir adalah pesan pembuka dari formulir). Jangan memanggil '
        .ShopTools::RECORD.' lagi dan jangan menyerahkan ke admin karena pesan ini. Rangkum pesanannya singkat (produk, varian, jumlah, alamat, ongkir dan total dari hasil cek ongkir), '
        .'lalu minta pembeli membalas OK/SESUAI bila sudah benar dan memilih cara bayar: transfer atau COD.';

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

        $input = self::orderInput($tenant, $lead, (string) $summary->body, self::draftedOrder($inbound));

        $missing = array_keys(array_filter([
            'produk' => blank($input['items'] ?? null),
            'alamat kirim' => blank($input['alamat'] ?? null) || blank($input['kota'] ?? null),
            'kurir' => blank($input['kurir'] ?? null),
            'cara bayar' => blank($input['pembayaran'] ?? null),
        ]));

        return new self($input + ['status' => 'konfirmasi'], $missing);
    }

    /**
     * The draft order of the message a form opens the chat with, written from what the form saved (never left to
     * the model), and the summary sent when the model cannot write one. Null when the form left something out or
     * the shipping cannot be priced: the model (or a person) asks as usual.
     *
     * @return array{0: array{name: string, input: array, result: array}, 1: string, 2: LeadQuote}|null
     */
    public static function fromForm(Tenant $tenant, Lead $lead, ShopTools $shop, ?ProductContext $products = null): ?array
    {
        $quote = LeadQuote::forLead($tenant, $lead, $products);
        $option = $quote?->quote->options[0] ?? null;
        $subtotal = $quote?->quote->subtotal();

        if ($quote === null || ! $quote->quote->ok() || $option === null || $subtotal === null) {
            return null;
        }

        $input = array_diff_key(self::orderInput($tenant, $lead, '', null), ['pembayaran' => 1, 'kurir' => 1]) + ['status' => 'draft'];
        $result = $shop->run(ShopTools::RECORD, $input);
        $shop->forget();

        if (($result['status'] ?? null) !== 'draft_tercatat') {
            return null;
        }

        $names = Property::query()->whereIn('id', array_column($input['items'], 'product_id'))->pluck('name', 'id');
        $lines = array_map(fn (array $i) => '- '.trim(($names[$i['product_id'] ?? 0] ?? $i['produk'] ?? 'Produk').' '.($i['varian'] ?? '')).' x '.(int) ($i['jumlah'] ?? 1), $input['items']);
        $fallback = implode("\n", [
            'Halo kak '.$input['nama'].', terima kasih 🙏 Pesanan sudah kami catat sebagai draft:',
            ...$lines,
            'Dikirim ke: '.$input['alamat'],
            'Ongkir '.$option->courier.': '.ShippingQuote::rupiah($option->price),
            'Total: '.ShippingQuote::rupiah($subtotal + $option->price),
            'Mohon balas OK/SESUAI bila sudah benar, dan pilih cara bayar: transfer atau COD ya kak.',
        ]);

        return [['name' => ShopTools::RECORD, 'input' => $input, 'result' => $result], $fallback, $quote];
    }

    /** catat_pesanan input from the order the AI drafted, else what the lead holds, and the summary text. */
    private static function orderInput(Tenant $tenant, Lead $lead, string $text, ?array $draft): array
    {
        $destination = LeadQuote::destination($lead);

        return array_filter([
            'items' => $draft['items'] ?? LeadQuote::items($lead),
            'nama' => $draft['nama'] ?? $lead->name,
            'alamat' => $draft['alamat'] ?? $lead->shipping_address,
            'kota' => $draft['kota'] ?? $destination['kota'] ?? null,
            'provinsi' => $draft['provinsi'] ?? $destination['provinsi'] ?? null,
            'kecamatan' => $draft['kecamatan'] ?? $destination['kecamatan'] ?? null,
            'kurir' => $draft['kurir'] ?? self::courier($tenant, $lead, $text),
            'pembayaran' => $draft['pembayaran'] ?? self::payment($lead, $text),
        ], fn ($v) => filled($v));
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

        // Not named: the shop's primary courier, the only one quoted unless the buyer asked for another.
        return count($named) === 1 ? $named[0] : ShippingSettings::of($tenant)->primaryCourier();
    }
}
