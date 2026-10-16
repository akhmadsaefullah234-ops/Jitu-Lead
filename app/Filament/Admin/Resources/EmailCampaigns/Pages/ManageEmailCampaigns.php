<?php

namespace App\Filament\Admin\Resources\EmailCampaigns\Pages;

use App\Filament\Admin\Resources\EmailCampaigns\EmailCampaignResource;
use App\Models\EmailCampaign;
use App\Retention\TrialEmailSettings;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEmailCampaigns extends ManageRecords
{
    protected static string $resource = EmailCampaignResource::class;

    public function getSubheading(): ?string
    {
        return 'Email dikirim dari '.TrialEmailSettings::fromAddress().' lewat antrean. Kampanye dan email percobaan berbagi batas '
            .(int) config('trial_emails.daily_limit').' email per hari; sisanya dikirim keesokan harinya.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Buat kampanye')->slideOver()
            ->mutateDataUsing(fn (array $data) => $data + ['status' => EmailCampaign::DRAFT])];
    }
}
