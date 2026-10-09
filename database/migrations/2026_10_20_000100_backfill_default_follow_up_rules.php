<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Same starters as ProvisionTenant::FOLLOW_UPS, copied here so this migration never changes. [name, stage, days, body] */
    private const RULES = [
        ['Sapaan H+1', 'Lead baru', 1, 'Halo {nama}, saya {agen} dari {agensi}. Kemarin Anda menanyakan {properti}. Boleh saya bantu dengan informasi unit dan harganya?'],
        ['Tanya kebutuhan H+3', 'Dihubungi', 3, 'Halo {nama}, apakah masih mencari properti? Kalau berkenan, ceritakan budget dan lokasi yang Anda inginkan, nanti saya carikan pilihan terbaik.'],
        ['Tawarkan survei H+7', 'Terkualifikasi', 7, 'Halo {nama}, bagaimana kalau kita jadwalkan survei ke {properti}? Saya bisa atur waktunya sesuai jadwal Anda.'],
    ];

    /**
     * Agencies that have no follow-up rule at all get the three starters, switched
     * off (they only send after the agency turns them on). An agency whose pipeline
     * has no stage of that name skips that rule: a rule with no stage would apply to every stage.
     */
    public function up(): void
    {
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            if (DB::table('follow_up_rules')->where('tenant_id', $tenantId)->exists()) {
                continue;
            }

            foreach (self::RULES as [$name, $stageName, $days, $body]) {
                $stageId = DB::table('stages')->where('tenant_id', $tenantId)->where('name', $stageName)->value('id');

                if ($stageId === null) {
                    continue;
                }

                DB::table('follow_up_rules')->insert([
                    'tenant_id' => $tenantId, 'name' => $name, 'stage_id' => $stageId, 'delay_days' => $days,
                    'send_time' => '09:00', 'body' => $body, 'active' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Rules may have been edited since; nothing is removed.
    }
};
