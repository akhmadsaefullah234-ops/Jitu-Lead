<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Stage;
use App\Support\PhoneNumber;

/**
 * Puts a lead from outside the app (web form, import) into the first open
 * stage and gives it to the next agent in line. A person who is already a lead,
 * by phone or email, is not added twice; the new contact is logged on theirs.
 */
class RegisterLead
{
    public function __construct(private AssignLead $assign) {}

    /**
     * @param  array{name: string, phone?: ?string, email?: ?string, note?: ?string}  $data
     * @return array{0: Lead, 1: bool} the lead and whether it was newly created
     */
    public function __invoke(array $data, string $sourceName, string $sourceType, ?int $ownerId = null): array
    {
        $phone = PhoneNumber::normalize($data['phone'] ?? null);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;

        $existing = Lead::query()
            ->where(fn ($q) => $q->when($phone, fn ($q) => $q->orWhere('phone', $phone))->when($email, fn ($q) => $q->orWhere('email', $email)))
            ->when($phone === null && $email === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('id')->first();

        if ($existing !== null) {
            $existing->activities()->create([
                'type' => 'note',
                'body' => "Menghubungi lagi lewat {$sourceName}".($note ? ": {$note}" : ''),
            ]);

            return [$existing, false];
        }

        $source = LeadSource::query()->firstOrCreate(['name' => $sourceName], ['type' => $sourceType]);
        $stage = Stage::query()->where('type', 'open')->orderBy('position')->firstOrFail();

        $lead = Lead::create([
            'name' => trim($data['name']),
            'phone' => $phone,
            'email' => $email,
            'lead_source_id' => $source->getKey(),
            'stage_id' => $stage->getKey(),
            'owner_id' => $ownerId ?? $this->assign->nextAgent()?->getKey(),
            'interest' => 'warm',
            'next_action' => $stage->default_action,
            'next_action_due_at' => $stage->default_due_hours === null ? null : now()->addHours($stage->default_due_hours),
        ]);

        $lead->activities()->create(['type' => 'created', 'body' => "Lead masuk dari {$sourceName}"]);

        if ($note !== null) {
            $lead->activities()->create(['type' => 'note', 'body' => $note]);
        }

        return [$lead, true];
    }
}
