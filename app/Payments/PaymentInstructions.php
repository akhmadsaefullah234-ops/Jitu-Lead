<?php

namespace App\Payments;

use App\Actions\SendWhatsAppMessage;
use App\Ai\PropertyClientFacts;
use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\PaymentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Shipping\ShippingQuote;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\WhatsAppException;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Instruksi bayar transfer yang disusun sistem dari data terstruktur (Pengaturan > Pembayaran), bukan oleh AI:
 * total dihitung ulang oleh RecordOrder, kode unik dipilih di sini dan disimpan di lead, dan rekening diambil dari
 * payment_accounts tenant. Pesannya dikirim lewat SendWhatsAppMessage dengan origin "payment".
 *
 * Nomor rekening tidak ditulis ke log, ke aktivitas lead, atau ke apa pun yang dibaca AI: riwayat chat dan memori
 * AI membaca pesan ini lewat forAi(), yang menyamarkan nomornya.
 *
 * Harus berjalan di dalam tenant lead (CurrentTenant), seperti RecordOrder.
 */
class PaymentInstructions
{
    public const ORIGIN = 'payment';

    /** Kode unik 3 digit: 100 sampai 999. */
    public const CODE_MIN = 100;

    public const CODE_MAX = 999;

    /** Kode unik tidak dipakai dua kali untuk nominal yang sama selama ini, di antara lead yang belum dibayar. */
    private const CODE_WINDOW_DAYS = 14;

    public function __construct(private RouteSelector $routes) {}

    /** True bila tenant mengisi rekening aktif. Tanpa itu, tidak ada yang berubah dari perilaku lama. */
    public static function applies(Tenant $tenant): bool
    {
        return PaymentSettings::of($tenant)->sendsInstructions();
    }

    /**
     * Menyusun, menyimpan (kode unik, nominal, batas bayar) dan mengirim instruksi bayar untuk pesanan transfer.
     *
     * @param  int  $total  total pesanan (produk + ongkir) yang dihitung ulang oleh sistem
     * @return array{status: string, pesan: string, nominal?: int, kode_unik?: int}
     */
    public function send(Lead $lead, int $total, User $actor): array
    {
        $tenant = $lead->tenant;
        $settings = PaymentSettings::of($tenant);
        $accounts = $settings->accounts();

        if ($accounts->isEmpty()) {
            return ['status' => 'tidak_berlaku', 'pesan' => 'Tenant belum mengisi rekening aktif.'];
        }

        $code = $settings->uniqueCode() ? $this->code($lead, $total) : null;
        $amount = $total + (int) $code;
        $due = $settings->deadlineHours() !== null ? now()->addHours($settings->deadlineHours()) : null;

        $lead->forceFill([
            'payment_unique_code' => $code,
            'payment_amount' => $amount,
            'payment_due_at' => $due,
        ])->save();

        $text = self::render($settings->template(), [
            'total' => $amount,
            'rekening' => $accounts,
            'kode_unik' => $code,
            'batas_bayar' => $due,
            'nama' => $lead->name,
        ], $tenant);

        $summary = 'nominal '.ShippingQuote::rupiah($amount).($code !== null ? ' (termasuk kode unik '.$code.')' : '')
            .($due !== null ? ', batas bayar '.self::when($due, $tenant) : '');

        $why = $this->deliver($lead, $actor, $text);

        if ($why !== null) {
            // Agen yang melanjutkan: nominal dan kode unik sudah tersimpan di lead. Rekening tidak ditulis di sini.
            $lead->activities()->create(['type' => 'payment_instruction', 'body' => 'Instruksi bayar transfer tidak terkirim otomatis ('.$why.'): '.$summary.'. Kirim manual dari Inbox.',
                'meta' => ['nominal' => $amount, 'kode_unik' => $code, 'terkirim' => false]]);

            return ['status' => 'tidak_terkirim', 'pesan' => $why, 'nominal' => $amount] + array_filter(['kode_unik' => $code]);
        }

        $lead->forceFill(['payment_instruction_sent_at' => now()])->save();
        $lead->activities()->create(['type' => 'payment_instruction', 'body' => 'Instruksi bayar transfer dikirim sistem: '.$summary.', '.$accounts->count().' rekening.',
            'meta' => ['nominal' => $amount, 'kode_unik' => $code, 'rekening' => $accounts->count(), 'terkirim' => true]]);

        return ['status' => 'terkirim', 'pesan' => 'Instruksi bayar dikirim sistem.', 'nominal' => $amount] + array_filter(['kode_unik' => $code]);
    }

    /** Null bila terkirim, else alasannya. */
    private function deliver(Lead $lead, User $actor, string $text): ?string
    {
        if (blank($lead->phone)) {
            return 'lead belum punya nomor WhatsApp';
        }

        $conversation = WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]);
        $decision = $this->routes->decide($conversation);

        if (! $decision->canSend() || $decision->isPaid()) {
            return $decision->isPaid() ? 'jendela chat gratis sudah tutup' : ($decision->note ?: 'tidak ada nomor WhatsApp yang bisa mengirim');
        }

        try {
            $message = app(SendWhatsAppMessage::class)->from(self::ORIGIN)($conversation->refresh(), $actor, $text);
        } catch (WhatsAppException $e) {
            return 'pengiriman gagal';
        } catch (Throwable $e) {
            report($e);

            return 'pengiriman gagal';
        }

        return $message->status === MessageStatus::Failed ? 'pengiriman gagal' : null;
    }

    /**
     * Kode unik untuk lead ini: kode lama dipakai lagi selama lead belum dibayar (pesanan yang dikonfirmasi ulang
     * tetap dengan kode yang sama), selain itu acak 100-999 yang tidak membuat nominal sama dengan lead lain tenant
     * ini yang masih menunggu pembayaran.
     */
    private function code(Lead $lead, int $total): int
    {
        if ($lead->payment_unique_code !== null && $lead->paid_at === null) {
            return (int) $lead->payment_unique_code;
        }

        $taken = Lead::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)->whereKeyNot($lead->getKey())
            ->whereNull('paid_at')->whereNotNull('payment_amount')->where('updated_at', '>=', now()->subDays(self::CODE_WINDOW_DAYS))
            ->whereBetween('payment_amount', [$total + self::CODE_MIN, $total + self::CODE_MAX])
            ->pluck('payment_amount')->map(fn ($a) => (int) $a - $total)->all();

        $free = array_values(array_diff(range(self::CODE_MIN, self::CODE_MAX), $taken));

        return $free !== [] ? $free[random_int(0, count($free) - 1)] : random_int(self::CODE_MIN, self::CODE_MAX);
    }

    /**
     * Mengisi template. Baris yang memuat {kode_unik} atau {batas_bayar} dibuang bila nilainya kosong (kode unik mati,
     * batas waktu tidak diatur), jadi satu template berlaku untuk semua pilihan.
     *
     * @param  array{total: int, rekening: Collection<int, PaymentAccount>|list<PaymentAccount>, kode_unik: ?int, batas_bayar: ?CarbonInterface, nama: ?string}  $data
     */
    public static function render(string $template, array $data, Tenant $tenant): string
    {
        $accounts = collect($data['rekening']);
        $values = [
            '{total}' => ShippingQuote::rupiah((int) $data['total']),
            '{rekening}' => $accounts->count() > 1
                ? $accounts->map(fn (PaymentAccount $a) => '- '.$a->line())->implode("\n")
                : (string) $accounts->first()?->line(),
            '{kode_unik}' => $data['kode_unik'] !== null ? (string) $data['kode_unik'] : '',
            '{batas_bayar}' => $data['batas_bayar'] !== null ? self::when($data['batas_bayar'], $tenant) : '',
            '{nama}' => explode(' ', trim((string) $data['nama']))[0] ?? '',
        ];

        $lines = [];

        foreach (preg_split('/\R/u', str_replace("\r\n", "\n", $template)) as $line) {
            $empty = array_filter(['{kode_unik}', '{batas_bayar}'], fn (string $v) => str_contains($line, $v) && $values[$v] === '');

            if ($empty !== []) {
                continue;
            }

            $line = strtr($line, $values);
            // "kak ," bila nama kosong
            $line = preg_replace(['/[ \t]+([,.!?])/u', '/[ \t]{2,}/u'], ['$1', ' '], $line);
            $lines[] = rtrim($line);
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return mb_substr($text, 0, SendWhatsAppMessage::MAX_LENGTH);
    }

    /** Contoh pesan untuk pratinjau di Pengaturan > Pembayaran: data contoh, rekening dari form. */
    public static function preview(Tenant $tenant, string $template, array $accounts, bool $uniqueCode, ?int $deadlineHours): string
    {
        $rows = collect($accounts)->filter(fn (array $a) => ($a['active'] ?? true) && filled($a['bank_name'] ?? null) && filled($a['account_number'] ?? null))
            ->map(fn (array $a) => new PaymentAccount(['bank_name' => (string) $a['bank_name'], 'account_number' => (string) $a['account_number'], 'account_holder' => (string) ($a['account_holder'] ?? '')]))
            ->values();

        return self::render(trim($template) !== '' ? $template : PaymentSettings::DEFAULT_TEMPLATE, [
            'total' => 231000 + ($uniqueCode ? 123 : 0),
            'rekening' => $rows->isEmpty() ? collect([new PaymentAccount(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => $tenant->name])]) : $rows,
            'kode_unik' => $uniqueCode ? 123 : null,
            'batas_bayar' => $deadlineHours ? now()->addHours($deadlineHours) : null,
            'nama' => 'Budi Santoso',
        ], $tenant);
    }

    /** Keterangan tombol "Pembayaran diterima": nominal yang ditunggu bila sistem sudah mengirim instruksi bayar. */
    public static function confirmDescription(?Lead $lead): string
    {
        $expected = $lead?->payment_amount !== null
            ? 'Nominal yang ditunggu: '.ShippingQuote::rupiah((int) $lead->payment_amount).($lead->payment_unique_code !== null ? ' (kode unik '.$lead->payment_unique_code.')' : '').'. '
            : '';

        return $expected.'Pastikan dana sudah ada di rekening. Lead pindah ke tahap Dikemas & dikirim.';
    }

    /** "Senin, 23 November 2026 pukul 14.30 WIB", di zona waktu tenant. */
    public static function when(CarbonInterface $at, Tenant $tenant): string
    {
        $timezone = $tenant->timezone ?: config('app.timezone');
        $local = $at->copy()->timezone($timezone)->locale('id');

        return $local->translatedFormat('l, j F Y').' pukul '.$local->format('H.i').' '.PropertyClientFacts::zone($timezone);
    }

    /**
     * Pesan instruksi bayar seperti dibaca AI (riwayat chat, ringkasan memori): nomor rekening disamarkan, sehingga AI
     * tidak bisa menyalin atau mengingatnya. Nominal rupiah bertitik ("Rp 231.123") tetap terbaca.
     */
    public static function forAi(string $body): string
    {
        return '[Instruksi bayar otomatis dari sistem] '.preg_replace('/(?<![\d.,])\d(?:[ \-]?\d){5,}(?![\d.,])/u', '[nomor rekening]', $body);
    }
}
