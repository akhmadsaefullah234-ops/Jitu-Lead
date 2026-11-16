<?php

namespace App\Automation;

use App\Models\Tenant;

/**
 * Which automatic stage moves and tasks a property agency has switched on (tenants.stage_automation['property'],
 * edited under Pengaturan > Otomatisasi tahap). An agency made before these rules existed has nothing stored and
 * everything off until an admin presses "Nyalakan"; a new property agency starts with every rule on except the
 * thank-you message after a survey (IndustryTemplate). Kept apart from the shop rules (StageAutomationSettings).
 */
class PropertyStageAutomationSettings
{
    public const KEY = 'property';

    public const THANKS_MESSAGE = "Terima kasih sudah menyempatkan survei, Kak {nama} 🙏\nKalau ada yang ingin ditanyakan atau mau dihitungkan simulasinya, kabari saja ya.";

    /** Rule => [label, help, number settings (key => [label, default, suffix])] */
    public const RULES = [
        'contacted' => ['Klien membalas → Dihubungi',
            'Lead baru pindah ke Dihubungi hanya bila klien membalas setelah pesan pertama dari kita (komunikasi dua arah). Lead yang diam tetap di Lead baru, dan rangkaian follow-up Lead baru tetap berjalan.', []],
        'qualified' => ['Kebutuhan tercatat → Terkualifikasi',
            'Saat tujuan dan salah satu dari domisili, budget, atau cara bayar sudah tercatat (oleh AI lewat simpan_kebutuhan atau oleh agen) dan klien sudah membalas minimal 2 kali.', []],
        'survey_change' => ['Klien membatalkan survei lewat chat → kembali ke Terkualifikasi',
            'AI boleh mencatat pembatalan survei yang klien sampaikan sendiri. Jadwal dikosongkan, pengingat H-1 tidak dikirim, dan agen mendapat tugas menawarkan jadwal baru. Mengubah jadwal sudah ditangani alat jadwalkan_survei.', []],
        'surveyed' => ['Jadwal survei lewat → Sudah survei',
            'Beberapa jam setelah jam survei lewat. Tugas "Kirim penawaran dan simulasi" dibuat. Bila survei batal, agen tinggal memindah lead kembali.', ['hours' => ['Setelah berapa jam', 3, 'jam']]],
        'thanks' => ['Kirim pesan terima kasih setelah survei',
            'Dikirim lewat WhatsApp saat lead pindah otomatis ke Sudah survei, hanya bila jendela chat gratis masih terbuka.', []],
        'silent' => ['Klien diam → Gugur ("Tidak ada respons")',
            'Untuk Lead baru, Dihubungi, dan Terkualifikasi, dihitung dari balasan klien terakhir (atau sejak masuk tahap), dan baru setelah follow-up terakhir lewat 2 hari. Chat klien yang belum dibalas tidak digugurkan.',
            ['days_new' => ['Hari untuk Lead baru', 14, 'hari'], 'days_other' => ['Hari untuk Dihubungi dan Terkualifikasi', 10, 'hari']]],
        'reopen' => ['Klien Gugur menulis lagi → Dihubungi',
            'Tidak berlaku untuk lead yang sudah Closing.', []],
        'booking_ready' => ['Klien siap booking → diserahkan ke agen',
            'Saat klien menulis bahwa ia mau booking, bayar tanda jadi, atau ambil unit, AI tidak menjawab sendiri: chat diserahkan ke agen dengan tugas "Proses booking". Tahap tidak dipindah; Booking dan Closing tetap diisi agen (unit dan nilai).', []],
        'tasks' => ['Tugas mengikuti percakapan',
            'Tugas "Hubungi lewat WhatsApp atau telepon" selesai sendiri saat balasan pertama keluar (AI atau agen), dan "Gali kebutuhan" selesai saat tujuan klien tercatat. Chat yang diserahkan AI menjadi tugas agen beserta alasannya.', []],
    ];

    /** Off unless switched on, for every agency that never chose. */
    private const OFF_BY_DEFAULT = ['thanks'];

    /** @param  array<string, mixed>  $stored */
    private function __construct(private array $stored) {}

    public static function of(?Tenant $tenant): self
    {
        return new self((array) (((array) ($tenant?->stage_automation ?? []))[self::KEY] ?? []));
    }

    public static function appliesTo(?Tenant $tenant): bool
    {
        return $tenant?->industry()->key === 'properti';
    }

    /** False for an agency that never saved these rules: everything stays off until an admin turns it on. */
    public function chosen(): bool
    {
        return $this->stored !== [];
    }

    public function enabled(string $rule): bool
    {
        return $this->chosen() && (bool) ($this->stored[$rule]['enabled'] ?? false);
    }

    public function number(string $rule, string $key): int
    {
        $default = self::RULES[$rule][2][$key][1] ?? 1;

        return max(1, min(365, (int) ($this->stored[$rule][$key] ?? $default)));
    }

    public function thanksMessage(): string
    {
        $text = trim((string) ($this->stored['thanks']['message'] ?? ''));

        return $text !== '' ? $text : self::THANKS_MESSAGE;
    }

    /** @return array<string, array<string, mixed>> every rule with its current values, for the settings form */
    public function toArray(): array
    {
        $out = [];

        foreach (self::RULES as $rule => [, , $numbers]) {
            $out[$rule] = ['enabled' => $this->enabled($rule)];

            foreach (array_keys($numbers) as $key) {
                $out[$rule][$key] = $this->number($rule, $key);
            }
        }

        $out['thanks']['message'] = $this->thanksMessage();

        return $out;
    }

    /** What a new property agency starts with, and what "Nyalakan" stores: every rule on but the thank-you message. */
    public static function defaults(): array
    {
        $state = [];

        foreach (self::RULES as $rule => [, , $numbers]) {
            $state[$rule] = ['enabled' => ! in_array($rule, self::OFF_BY_DEFAULT, true)] + array_map(fn (array $n) => $n[1], $numbers);
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
                $out[$rule][$key] = max(1, min(365, (int) ($state[$rule][$key] ?? $default)));
            }
        }

        $message = trim((string) ($state['thanks']['message'] ?? ''));
        $out['thanks']['message'] = $message === '' || $message === self::THANKS_MESSAGE ? null : mb_substr($message, 0, 1000);

        return $out;
    }

    /** Stores the rules on the agency, keeping whatever else the column holds. */
    public static function store(Tenant $tenant, array $clean): void
    {
        $tenant->forceFill(['stage_automation' => [...(array) ($tenant->stage_automation ?? []), self::KEY => $clean]])->save();
    }
}
