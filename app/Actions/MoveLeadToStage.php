<?php

namespace App\Actions;

use App\Billing\PlanLimits;
use App\Enums\StageRequirement;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a lead to another pipeline stage, enforcing the stage's required
 * details and filling the next action from the stage defaults (PRD F3).
 */
class MoveLeadToStage
{
    /**
     * @param  array{survey_at?: mixed, survey_location?: string, unit?: string, deal_value?: int|string, lost_reason_id?: int|string}  $details
     */
    public function __invoke(Lead $lead, Stage $stage, User $actor, array $details = []): Lead
    {
        if (PlanLimits::current()?->readOnly()) {
            throw ValidationException::withMessages(['stage' => 'Langganan hanya-baca, lead tidak bisa dipindah. Perpanjang paket di menu Langganan.']);
        }

        if ((int) $stage->tenant_id !== (int) $lead->tenant_id) {
            throw ValidationException::withMessages(['stage' => 'Tahap tidak ditemukan.']);
        }

        if ($stage->is($lead->stage)) {
            return $lead;
        }

        $changes = $this->requiredDetails($lead, $stage, $details);

        return DB::transaction(function () use ($lead, $stage, $actor, $changes) {
            $from = $lead->stage;

            $lead->fill($changes);
            $lead->stage()->associate($stage);
            $lead->stage_entered_at = now();

            if (! $stage->isOpen() && $stage->default_action === null) {
                $lead->next_action = null;
                $lead->next_action_due_at = null;
            } else {
                $lead->next_action = $stage->default_action;
                $lead->next_action_due_at = $this->dueAt($lead, $stage);
            }

            $lead->save();

            $lead->activities()->create([
                'user_id' => $actor->getKey(),
                'type' => 'stage_changed',
                'body' => "Pindah dari {$from?->name} ke {$stage->name}",
                'meta' => ['from' => $from?->getKey(), 'to' => $stage->getKey()] + $changes,
            ]);

            return $lead;
        });
    }

    private function requiredDetails(Lead $lead, Stage $stage, array $details): array
    {
        return match ($stage->requirement) {
            StageRequirement::Survey => $this->survey($details),
            StageRequirement::Booking => $this->booking($lead, $details),
            StageRequirement::LostReason => $this->lostReason($details),
            null => [],
        };
    }

    private function survey(array $details): array
    {
        $at = filled($details['survey_at'] ?? null) ? CarbonImmutable::parse($details['survey_at']) : null;
        $location = trim((string) ($details['survey_location'] ?? ''));

        $errors = [];
        if ($at === null) {
            $errors['survey_at'] = 'Isi tanggal dan jam survei.';
        } elseif ($at->isPast()) {
            $errors['survey_at'] = 'Jadwal survei harus setelah sekarang.';
        }
        if ($location === '') {
            $errors['survey_location'] = 'Isi lokasi survei.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['survey_at' => $at, 'survey_location' => $location];
    }

    private function booking(Lead $lead, array $details): array
    {
        $unit = trim((string) ($details['unit'] ?? $lead->unit ?? ''));
        $value = (int) ($details['deal_value'] ?? $lead->deal_value ?? 0);

        $errors = [];
        if ($unit === '') {
            $errors['unit'] = 'Isi unit yang dibooking.';
        }
        if ($value <= 0) {
            $errors['deal_value'] = 'Isi nilai transaksi.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['unit' => $unit, 'deal_value' => $value];
    }

    private function lostReason(array $details): array
    {
        $reasonId = $details['lost_reason_id'] ?? null;

        if (! filled($reasonId) || ! LostReason::query()->whereKey($reasonId)->exists()) {
            throw ValidationException::withMessages(['lost_reason_id' => 'Pilih alasan gugur.']);
        }

        return ['lost_reason_id' => (int) $reasonId];
    }

    private function dueAt(Lead $lead, Stage $stage): ?CarbonImmutable
    {
        if ($stage->requirement === StageRequirement::Survey && $lead->survey_at !== null) {
            // Confirm the day before the visit, or now if the visit is under a day away.
            return CarbonImmutable::parse($lead->survey_at)->subDay()->max(now());
        }

        return $stage->default_due_hours === null ? null : now()->toImmutable()->addHours($stage->default_due_hours);
    }
}
