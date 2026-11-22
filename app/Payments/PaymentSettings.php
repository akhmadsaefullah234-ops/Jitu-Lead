<?php

namespace App\Payments;

use App\Models\PaymentAccount;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Pengaturan > Pembayaran milik satu tenant: rekening bank (tabel payment_accounts) dan tenants.payment_settings
 * {batas_waktu_bayar (jam), kode_unik (bool), semua_rekening (bool), template_instruksi_bayar (teks)}.
 *
 * Tenant tanpa rekening aktif tidak memakai apa pun dari sini (sendsInstructions() false): AI tetap memakai
 * rekening dari PENGETAHUAN seperti sebelumnya.
 */
final class PaymentSettings
{
    /** Variabel yang boleh dipakai di template. */
    public const VARIABLES = ['{total}', '{rekening}', '{kode_unik}', '{batas_bayar}', '{nama}'];

    /** Variabel yang wajib ada di template: tanpa itu instruksi tidak berguna. */
    public const REQUIRED = ['{total}', '{rekening}'];

    public const MAX_DEADLINE_HOURS = 168;

    public const DEFAULT_TEMPLATE = <<<'TXT'
Terima kasih kak {nama}, pesanannya sudah kami catat 🙏
Total transfer: *{total}*
3 digit terakhir ({kode_unik}) adalah kode unik. Mohon transfer tepat sampai 3 digit terakhir agar mudah kami cek.
Silakan transfer ke:
{rekening}
Mohon transfer sebelum {batas_bayar}.
Setelah transfer, kirim foto bukti transfernya di chat ini ya kak. Admin kami akan mengecek dulu sebelum pesanan dikemas.
TXT;

    /** @param  array<string, mixed>  $data */
    private function __construct(private Tenant $tenant, private array $data) {}

    public static function of(Tenant $tenant): self
    {
        return new self($tenant, (array) ($tenant->payment_settings ?? []));
    }

    /** Jam sejak pesanan dicatat sampai batas bayar; null bila tidak diatur. */
    public function deadlineHours(): ?int
    {
        $hours = (int) ($this->data['batas_waktu_bayar'] ?? 0);

        return $hours > 0 ? min($hours, self::MAX_DEADLINE_HOURS) : null;
    }

    public function uniqueCode(): bool
    {
        return (bool) ($this->data['kode_unik'] ?? false);
    }

    /** Semua rekening aktif ditulis di instruksi; bila tidak, hanya rekening aktif teratas. */
    public function allAccounts(): bool
    {
        return (bool) ($this->data['semua_rekening'] ?? false);
    }

    public function template(): string
    {
        $template = trim((string) ($this->data['template_instruksi_bayar'] ?? ''));

        return $template !== '' ? $template : self::DEFAULT_TEMPLATE;
    }

    /**
     * Rekening yang ditulis di instruksi: rekening aktif teratas, atau semua rekening aktif bila diatur. Selalu lewat
     * relasi tenant, jadi tetap terbatas pada tenant ini walau tidak ada tenant aktif (konsol, antrean).
     *
     * @return Collection<int, PaymentAccount>
     */
    public function accounts(): Collection
    {
        $query = $this->tenant->paymentAccounts()->where('active', true);

        return $this->allAccounts() ? $query->get() : $query->limit(1)->get();
    }

    /** True bila tenant punya minimal satu rekening aktif: hanya saat itu sistem mengirim instruksi bayar sendiri. */
    public function sendsInstructions(): bool
    {
        return $this->tenant->paymentAccounts()->where('active', true)->exists();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batas_waktu_bayar' => $this->deadlineHours(),
            'kode_unik' => $this->uniqueCode(),
            'semua_rekening' => $this->allAccounts(),
            'template_instruksi_bayar' => $this->template(),
        ];
    }

    /** Variabel wajib yang belum ada di template. @return list<string> */
    public static function missingVariables(string $template): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $v) => ! str_contains($template, $v)));
    }
}
