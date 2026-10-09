<?php

namespace App\Actions;

use App\Ai\Defaults;
use App\Enums\Role;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Creates a tenant with the default property pipeline, lead sources and lost
 * reasons, and makes the given user its admin.
 */
class ProvisionTenant
{
    public const STAGES = [
        ['Lead baru', StageType::Open, null, 'Hubungi lewat WhatsApp atau telepon', 1],
        ['Dihubungi', StageType::Open, null, 'Gali kebutuhan: tipe, lokasi, budget, cara bayar', 48],
        ['Terkualifikasi', StageType::Open, null, 'Kirim pilihan unit atau listing yang cocok', 48],
        ['Jadwal survei', StageType::Open, StageRequirement::Survey, 'Konfirmasi H-1 sebelum survei', null],
        ['Sudah survei', StageType::Open, null, 'Kirim penawaran dan simulasi KPR', 24],
        ['Negosiasi', StageType::Open, null, 'Follow-up keputusan', 72],
        ['Booking', StageType::Open, StageRequirement::Booking, 'Lengkapi berkas, proses KPR', 168],
        ['Closing', StageType::Won, StageRequirement::Booking, 'Minta referral', 168],
        ['Gugur', StageType::Lost, StageRequirement::LostReason, null, null],
    ];

    public const LOST_REASONS = [
        'Budget tidak cukup',
        'KPR ditolak',
        'Membeli di tempat lain',
        'Tidak ada respons',
        'Hanya cek harga',
        'Lokasi tidak cocok',
    ];

    public const SOURCES = [
        ['Iklan Meta', 'ads'],
        ['Portal properti', 'portal'],
        ['Formulir web', 'form'],
        ['Referral', 'manual'],
        ['Pameran', 'manual'],
        ['Walk-in', 'manual'],
    ];

    /** Starter follow-ups, switched off until the agency checks the wording. [name, stage, hours, body] */
    public const FOLLOW_UPS = [
        ['Sapaan H+1', 'Lead baru', 1, 'Halo {nama}, saya {agen} dari {agensi}. Kemarin Anda menanyakan {properti}. Boleh saya bantu dengan informasi unit dan harganya?'],
        ['Tanya kebutuhan H+3', 'Dihubungi', 3, 'Halo {nama}, apakah masih mencari properti? Kalau berkenan, ceritakan budget dan lokasi yang Anda inginkan, nanti saya carikan pilihan terbaik.'],
        ['Tawarkan survei H+7', 'Terkualifikasi', 7, 'Halo {nama}, bagaimana kalau kita jadwalkan survei ke {properti}? Saya bisa atur waktunya sesuai jadwal Anda.'],
    ];

    public function __construct(private CurrentTenant $current) {}

    public function __invoke(string $name, string $slug, User $admin): Tenant
    {
        return DB::transaction(function () use ($name, $slug, $admin) {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug,
                'plan' => 'trial',
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'ai_instructions' => Defaults::INSTRUCTIONS,
                'ai_handoff_keywords' => Defaults::keywordText(),
            ]);

            $tenant->currentSubscription();

            $tenant->users()->attach($admin, ['role' => Role::Admin->value, 'status' => 'active']);

            $this->current->run($tenant, function () use ($tenant) {
                foreach (self::STAGES as $position => [$stageName, $type, $requirement, $action, $hours]) {
                    $tenant->stages()->create([
                        'name' => $stageName,
                        'position' => $position + 1,
                        'type' => $type,
                        'requirement' => $requirement,
                        'default_action' => $action,
                        'default_due_hours' => $hours,
                    ]);
                }

                foreach (self::LOST_REASONS as $label) {
                    $tenant->lostReasons()->create(['label' => $label]);
                }

                foreach (self::FOLLOW_UPS as [$ruleName, $stageName, $days, $body]) {
                    $tenant->followUpRules()->create([
                        'name' => $ruleName,
                        'stage_id' => $tenant->stages()->where('name', $stageName)->value('id'),
                        'delay_days' => $days,
                        'body' => $body,
                        'active' => false,
                    ]);
                }

                foreach (self::SOURCES as [$sourceName, $type]) {
                    $tenant->leadSources()->create(['name' => $sourceName, 'type' => $type]);
                }
            });

            return $tenant;
        });
    }
}
