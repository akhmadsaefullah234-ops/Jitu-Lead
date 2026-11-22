<?php

namespace App\Automation;

use App\Models\Tenant;

/**
 * Which automatic stage moves a digital-product agency has switched on (tenants.stage_automation['digital'], edited
 * under Pengaturan > Otomatisasi tahap). A new digital agency starts with every rule on (IndustryTemplate). Kept apart
 * from the shop rules (StageAutomationSettings) and the property rules, and never read for another industry.
 */
class DigitalStageAutomationSettings
{
    public const KEY = 'digital';

    /** Rule => [label, help, number settings (key => [label, default, suffix])] */
    public const RULES = [
        'purchase_recorded' => ['Pembeli konfirmasi pembelian → Menunggu pembayaran',
            'Saat AI atau agen mencatat pembelian yang sudah dikonfirmasi pembeli. Instruksi bayar dikirim sistem bila rekening sudah diisi di Pengaturan > Pembayaran.', []],
        'payment_proof' => ['Gambar saat Menunggu pembayaran → tandai "Bukti transfer masuk"',
            'Admin dan agen pemilik lead diberi notifikasi untuk mengecek. AI tidak pernah menyatakan lunas; agen menekan "Pembayaran diterima" untuk memindah lead ke Lunas & akses terkirim.', []],
        'no_payment' => ['Belum bayar di Menunggu pembayaran → Tidak jadi bayar ("Pembayaran gagal")',
            'Dihitung sejak lead masuk Menunggu pembayaran. Lead yang sudah mengirim bukti transfer tidak dipindah.', ['hours' => ['Setelah berapa jam', 48, 'jam']]],
        'reopen' => ['Pembeli Tidak jadi bayar mengirim chat baru → Lead baru',
            'Tidak berlaku untuk lead yang sudah Lunas atau Refund.', []],
    ];

    /** @param  array<string, mixed>  $stored */
    private function __construct(private array $stored) {}

    public static function of(?Tenant $tenant): self
    {
        return new self((array) (((array) ($tenant?->stage_automation ?? []))[self::KEY] ?? []));
    }

    public static function appliesTo(?Tenant $tenant): bool
    {
        return $tenant?->industry()->key === 'digital';
    }

    public function chosen(): bool
    {
        return $this->stored !== [];
    }

    public function enabled(string $rule): bool
    {
        return (bool) ($this->stored[$rule]['enabled'] ?? false);
    }

    public function number(string $rule, string $key): int
    {
        $default = self::RULES[$rule][2][$key][1] ?? 1;

        return max(1, min(8760, (int) ($this->stored[$rule][$key] ?? $default)));
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        $out = [];

        foreach (self::RULES as $rule => [, , $numbers]) {
            $out[$rule] = ['enabled' => $this->enabled($rule)];

            foreach (array_keys($numbers) as $key) {
                $out[$rule][$key] = $this->number($rule, $key);
            }
        }

        return $out;
    }

    /** Every rule on with its default numbers. */
    public static function defaults(): array
    {
        $state = [];

        foreach (self::RULES as $rule => [, , $numbers]) {
            $state[$rule] = ['enabled' => true] + array_map(fn (array $n) => $n[1], $numbers);
        }

        return self::clean($state);
    }

    /** @param  array<string, array<string, mixed>>  $state */
    public static function clean(array $state): array
    {
        $out = [];

        foreach (self::RULES as $rule => [, , $numbers]) {
            $out[$rule] = ['enabled' => (bool) ($state[$rule]['enabled'] ?? false)];

            foreach ($numbers as $key => [, $default]) {
                $out[$rule][$key] = max(1, min(8760, (int) ($state[$rule][$key] ?? $default)));
            }
        }

        return $out;
    }

    /** Stores the rules on the agency, keeping whatever else the column holds. */
    public static function store(Tenant $tenant, array $clean): void
    {
        $tenant->forceFill(['stage_automation' => [...(array) ($tenant->stage_automation ?? []), self::KEY => $clean]])->save();
    }
}
