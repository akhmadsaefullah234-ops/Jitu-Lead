<?php

namespace Database\Seeders\Demo;

use App\Ai\Facts\Facts;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\Tenant;
use App\Models\WaChannel;

/**
 * A NEW property agency that was only given its knowledge through "Isi properti" (Facts::saveWizard, exactly what an
 * owner submits), each with its own payment model, so `ai:evaluate` can show the AI follows any model without its AI
 * settings being touched. Everything is made up; numbers start 0899000.
 */
abstract class PaymentProfileSeeder extends DemoSeeder
{
    protected function industry(): string
    {
        return 'properti';
    }

    /** @return array<string, mixed> the wizard's payment step (project.*) */
    abstract protected function payment(): array;

    abstract protected function channelPhone(): string;

    protected function fill(Tenant $tenant, array $team): void
    {
        WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'WA '.$this->name(), 'phone' => $this->channelPhone(), 'status' => WaChannelStatus::Connected, 'position' => 1,
            'ai_mode' => AiMode::Draft, 'credentials' => ['base_url' => 'https://gateway.contoh.test', 'api_key' => 'demo', 'signing_secret' => 'demo'],
        ]);

        $project = 'Perumahan '.str_replace('Agensi Demo ', 'Contoh ', $this->name());

        Facts::saveWizard([
            'project' => [
                'project' => $project, 'developer' => 'PT Pengembang Contoh', 'location' => 'Jl. Contoh Indah, Kota Contoh Barat',
                'legal' => 'SHM', 'handover' => 'Sekitar 12 bulan setelah akad', 'survey_hours' => 'Setiap hari 09.00 sampai 16.00',
                'survey_location' => 'Rumah contoh '.$project, 'contact' => 'Kantor pemasaran '.$project,
            ] + $this->payment(),
            'types' => [
                ['name' => '36/72', 'price' => '480000000', 'building_area' => '36', 'land_area' => '72', 'bedrooms' => '2', 'bathrooms' => '1', 'availability' => 'Tersedia'],
                ['name' => '45/90', 'price' => '620000000', 'building_area' => '45', 'land_area' => '90', 'bedrooms' => '3', 'bathrooms' => '2', 'availability' => 'Inden'],
            ],
        ]);
    }
}
