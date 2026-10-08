<?php

namespace Database\Seeders;

use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Enums\PropertyKind;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development: two agencies, so tenant isolation can be
 * seen in the browser. Every login uses the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->user('Andini Putri', 'admin@griyaprima.test');
        $tenant = app(ProvisionTenant::class)('Griya Prima Realty', 'griya-prima', $admin);

        $agents = collect([
            ['Rina Amelia', 'rina@griyaprima.test'],
            ['Dimas Pratama', 'dimas@griyaprima.test'],
            ['Sari Wulandari', 'sari@griyaprima.test'],
        ])->map(function ($row) use ($tenant) {
            $user = $this->user(...$row);
            $tenant->users()->attach($user, ['role' => Role::Agent->value, 'status' => 'active']);

            return $user;
        });

        app(CurrentTenant::class)->run($tenant, fn () => $this->griyaPrima($tenant, $admin, $agents->all()));

        $other = app(ProvisionTenant::class)('Nusa Properti', 'nusa-properti', $this->user('Bayu Saputra', 'admin@nusaproperti.test'));
        app(CurrentTenant::class)->run($other, function () use ($other) {
            Lead::create([
                'name' => 'Lead milik Nusa Properti',
                'phone' => '081100000001',
                'stage_id' => $other->stages()->first()->getKey(),
                'owner_id' => $other->users()->first()->getKey(),
                'interest' => Interest::Hot,
            ]);
        });
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    private function griyaPrima(Tenant $tenant, User $admin, array $agents): void
    {
        [$rina, $dimas, $sari] = $agents;

        $bukit = Property::create(['kind' => PropertyKind::Primary, 'name' => 'Cluster Bukit Asri', 'property_type' => 'Rumah', 'location' => 'Bogor Selatan', 'developer' => 'PT Bukit Asri Lestari', 'price_from' => 785_000_000]);
        $taman = Property::create(['kind' => PropertyKind::Primary, 'name' => 'Apartemen Taman Kota Tower B', 'property_type' => 'Apartemen', 'location' => 'Jakarta Selatan', 'developer' => 'PT Taman Kota Hunian', 'price_from' => 620_000_000]);
        $kenanga = Property::create(['kind' => PropertyKind::Secondary, 'name' => 'Rumah Jl. Kenanga No. 8', 'property_type' => 'Rumah', 'location' => 'Depok', 'price_from' => 1_150_000_000, 'details' => ['luas_tanah' => 120, 'luas_bangunan' => 90, 'sertifikat' => 'SHM']]);

        $source = fn (string $name) => LeadSource::query()->where('name', $name)->value('id');
        $stage = fn (string $name) => Stage::query()->where('name', $name)->firstOrFail();

        $rows = [
            ['Budi Santoso', '0812 3456 7710', 'Iklan Meta', $bukit, Interest::Hot, $rina, 'Lead baru', [], 700, 850],
            ['Siti Rahmawati', '0857 1122 4408', 'Formulir web', $taman, Interest::Warm, $dimas, 'Lead baru', [], 550, 650],
            ['Andi Pratama', '0821 5543 9012', 'Iklan Meta', $bukit, Interest::Hot, $rina, 'Dihubungi', [], 750, 900],
            ['Dewi Lestari', '0811 2201 7789', 'Portal properti', $kenanga, Interest::Cold, $sari, 'Dihubungi', [], 1000, 1150],
            ['Rizky Ramadhan', '0819 4410 3321', 'Iklan Meta', $bukit, Interest::Warm, $rina, 'Terkualifikasi', [], 780, 820],
            ['Hendra Wijaya', '0812 9988 1203', 'Iklan Meta', $bukit, Interest::Hot, $rina, 'Jadwal survei', ['survey_at' => now()->addDays(2)->setTime(10, 0), 'survey_location' => 'Unit contoh Cluster Bukit Asri'], 800, 900],
            ['Lina Kusuma', '0838 1200 5567', 'Portal properti', $kenanga, Interest::Warm, $sari, 'Sudah survei', [], 1100, 1200],
            ['Fajar Nugroho', '0822 6789 0012', 'Iklan Meta', $taman, Interest::Hot, $dimas, 'Negosiasi', [], 600, 650],
            ['Ayu Wulandari', '0815 4402 8890', 'Iklan Meta', $bukit, Interest::Hot, $rina, 'Booking', ['unit' => 'Blok C-12', 'deal_value' => 785_000_000], 780, 800],
            ['Joko Susilo', '0817 3301 6645', 'Referral', $kenanga, Interest::Hot, $sari, 'Closing', ['unit' => 'Jl. Kenanga No. 8', 'deal_value' => 1_150_000_000], 1100, 1200],
            ['Ratna Sari', '0852 1190 7734', 'Iklan Meta', $bukit, Interest::Cold, $rina, 'Gugur', ['lost_reason_id' => LostReason::query()->where('label', 'KPR ditolak')->value('id')], 500, 600],
        ];

        $first = $stage('Lead baru');

        foreach ($rows as [$name, $phone, $sourceName, $property, $interest, $owner, $stageName, $details, $min, $max]) {
            $lead = Lead::create([
                'name' => $name,
                'phone' => $phone,
                'lead_source_id' => $source($sourceName),
                'stage_id' => $first->getKey(),
                'owner_id' => $owner->getKey(),
                'interest' => $interest,
                'need' => Need::Buy,
                'property_type' => $property->property_type,
                'location' => $property->location,
                'budget_min' => $min * 1_000_000,
                'budget_max' => $max * 1_000_000,
                'payment_method' => PaymentMethod::Kpr,
                'property_id' => $property->getKey(),
                'next_action' => $first->default_action,
                'next_action_due_at' => now()->addHour(),
            ]);

            if ($stageName !== 'Lead baru') {
                app(MoveLeadToStage::class)($lead, $stage($stageName), $owner, $details);
            }
        }

        // Make two actions overdue so the board shows what late work looks like.
        Lead::query()->where('name', 'Siti Rahmawati')->update(['next_action_due_at' => now()->subHours(2)]);
        Lead::query()->where('name', 'Dewi Lestari')->update(['next_action_due_at' => now()->subDay(), 'next_action' => 'Tanyakan kembali minat setelah lihat brosur']);
    }
}
