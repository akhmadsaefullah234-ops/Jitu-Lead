<?php

namespace App\Automation;

use App\Models\Tenant;

/**
 * Which automatic stage moves a shop has switched on, and their day counts (tenants.stage_automation, edited
 * under Pengaturan > Otomatisasi tahap). Every rule starts on with the defaults below; only shops (retail) use them.
 */
class StageAutomationSettings
{
    /** Rule => [label, help, day settings (key => [label, default])] */
    public const RULES = [
        'order_draft' => ['Format order lengkap → Order masuk',
            'Saat AI mendapati data pesanan lengkap (nama, alamat dengan RT/RW, atau nomor rumah/patokan bila tanpa RT/RW, warna/varian, jumlah) sebelum pembeli konfirmasi.', []],
        'order_confirmed' => ['Pembeli konfirmasi → Menunggu pembayaran (transfer) atau Dikemas & dikirim (COD)',
            'Saat AI atau agen mencatat pesanan yang sudah dikonfirmasi pembeli.', []],
        'payment_proof' => ['Gambar saat Menunggu pembayaran → tandai "Bukti transfer masuk"',
            'Admin dan agen pemilik lead diberi notifikasi untuk mengecek. AI tidak pernah menyatakan pembayaran diterima; agen menekan "Pembayaran diterima" untuk lanjut ke Dikemas & dikirim.', []],
        'tracking' => ['Nomor resi diisi → kirim WhatsApp resi dan pindah ke Dikemas & dikirim',
            'Pesan ke pembeli berisi kurir, nomor resi, dan tautan lacak.', []],
        'delivered' => ['Setelah resi dikirim → Selesai',
            'Dihitung dari waktu nomor resi dikirim ke pembeli, menurut provinsi alamat kirim.', ['days_java' => ['Hari untuk tujuan Pulau Jawa', 5], 'days_other' => ['Hari untuk tujuan luar Jawa', 8]]],
        'no_reply' => ['Tidak ada balasan di Tanya produk → Batal ("Tidak ada respons")',
            'Dihitung dari chat terakhir pembeli (atau sejak masuk tahap bila belum pernah chat). Lead yang chatnya belum dibalas agen tidak dibatalkan.', ['days' => ['Setelah berapa hari', 7]]],
        'no_payment' => ['Belum bayar di Menunggu pembayaran → Batal ("Batal setelah order")',
            'Dihitung dari follow-up terakhir yang terkirim (atau sejak masuk tahap). Lead yang sudah mengirim bukti transfer tidak dibatalkan.', ['days' => ['Setelah berapa hari', 3]]],
        'reopen' => ['Pembeli Batal mengirim chat baru → Tanya produk',
            'Tidak berlaku untuk lead yang sudah Selesai.', []],
    ];

    /** @param  array<string, array{enabled?: bool, days?: int, days_java?: int, days_other?: int}>  $stored */
    private function __construct(private array $stored) {}

    public static function of(?Tenant $tenant): self
    {
        return new self((array) ($tenant?->stage_automation ?? []));
    }

    /** Only shops move leads automatically. */
    public static function appliesTo(?Tenant $tenant): bool
    {
        return $tenant?->industry()->key === 'retail';
    }

    public function enabled(string $rule): bool
    {
        return (bool) ($this->stored[$rule]['enabled'] ?? true);
    }

    public function days(string $rule, string $key = 'days'): int
    {
        $default = self::RULES[$rule][2][$key][1] ?? 1;

        return max(1, min(365, (int) ($this->stored[$rule][$key] ?? $default)));
    }

    /** @return array<string, array<string, bool|int>> every rule with its current values, for the settings form */
    public function toArray(): array
    {
        $out = [];

        foreach (self::RULES as $rule => [, , $days]) {
            $out[$rule] = ['enabled' => $this->enabled($rule)];

            foreach (array_keys($days) as $key) {
                $out[$rule][$key] = $this->days($rule, $key);
            }
        }

        return $out;
    }

    /** @param  array<string, array<string, mixed>>  $state */
    public static function clean(array $state): array
    {
        $out = [];

        foreach (self::RULES as $rule => [, , $days]) {
            $out[$rule] = ['enabled' => (bool) ($state[$rule]['enabled'] ?? false)];

            foreach ($days as $key => [, $default]) {
                $out[$rule][$key] = max(1, min(365, (int) ($state[$rule][$key] ?? $default)));
            }
        }

        return $out;
    }
}
