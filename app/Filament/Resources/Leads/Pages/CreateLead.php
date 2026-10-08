<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\AssignLead;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Stage;
use App\Support\CurrentTenant;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = app(CurrentTenant::class)->roleOf(auth()->user());

        if (! ($role?->seesAllLeads() ?? false)) {
            // Agents always own the leads they add themselves.
            $data['owner_id'] = auth()->id();
        } elseif (blank($data['owner_id'] ?? null)) {
            $data['owner_id'] = app(AssignLead::class)->nextAgent()?->getKey() ?? auth()->id();
        }

        $first = Stage::query()->where('type', 'open')->orderBy('position')->firstOrFail();
        $data['stage_id'] = $first->getKey();
        $data['next_action'] = $first->default_action;
        $data['next_action_due_at'] = $first->default_due_hours === null ? null : now()->addHours($first->default_due_hours);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->activities()->create([
            'user_id' => auth()->id(),
            'type' => 'created',
            'body' => 'Lead dibuat',
        ]);

        $duplicates = $this->record->duplicates()->limit(3)->pluck('name');

        if ($duplicates->isNotEmpty()) {
            Notification::make()
                ->warning()
                ->title('Kemungkinan lead ganda')
                ->body('Nomor atau email yang sama sudah dipakai: '.$duplicates->join(', ').'.')
                ->persistent()
                ->send();
        }
    }
}
