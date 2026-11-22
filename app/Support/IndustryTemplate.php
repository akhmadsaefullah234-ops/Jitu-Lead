<?php

namespace App\Support;

use App\Ai\AiSettings;
use App\Ai\Facts\PaymentDefaults;
use App\Automation\DigitalStageAutomationSettings;
use App\Automation\DigitalStages;
use App\Automation\PropertyStageAutomationSettings;
use App\Automation\PropertyStages;
use App\Automation\RetailStages;
use App\Enums\StageType;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tracking\PropertyAdDefaults;
use Illuminate\Support\Facades\DB;

/**
 * Puts an industry's starting pipeline, lost reasons, lead sources, follow-ups and AI texts into an agency.
 * An agency without leads gets the template in full, replacing what it had. Once there are leads nothing is
 * removed: missing stages, reasons, sources and follow-ups are only added, so every lead keeps its stage.
 */
class IndustryTemplate
{
    public function __construct(private CurrentTenant $current) {}

    /** Switches the agency to the industry and applies its template; returns true when it was applied in full. */
    public function switch(Tenant $tenant, string $industry): bool
    {
        $from = $tenant->industry();
        $to = Industry::make($industry);

        return DB::transaction(function () use ($tenant, $from, $to) {
            $tenant->forceFill(['industry' => $to->key])->save();

            return $this->current->run($tenant, function () use ($tenant, $from, $to) {
                $full = ! Lead::withTrashed()->exists();
                $full ? $this->replace($tenant) : null;
                $this->add($tenant, $to);
                $this->aiTexts($tenant, $from, $to);
                $full ? $this->automation($tenant) : null;

                return $full;
            });
        });
    }

    /** Fills a brand new agency (ProvisionTenant). Must run with the agency as the current tenant. */
    public function fill(Tenant $tenant): void
    {
        $this->add($tenant, $tenant->industry());
        $tenant->forceFill([
            'ai_instructions' => $tenant->industry()->config('ai_instructions'),
            'ai_handoff_keywords' => implode(', ', $tenant->industry()->config('handoff_keywords')),
        ])->save();
        $this->automation($tenant);
    }

    /**
     * A new property agency (or one switched to property before it had leads) starts with Otomatisasi tahap on,
     * Jitu's ad-tracking flow, and Jitu's AI playbook and settings. An agency that already chose keeps its choice.
     */
    private function automation(Tenant $tenant): void
    {
        if (PropertyStageAutomationSettings::appliesTo($tenant) && ! PropertyStageAutomationSettings::of($tenant)->chosen()) {
            PropertyStageAutomationSettings::store($tenant, PropertyStageAutomationSettings::defaults());
        }

        // A digital-product agency starts with its own rules on (DigitalStageAutomation); nothing else below is for it.
        if (DigitalStageAutomationSettings::appliesTo($tenant)) {
            DigitalStageAutomationSettings::of($tenant)->chosen() ?: DigitalStageAutomationSettings::store($tenant, DigitalStageAutomationSettings::defaults());

            return;
        }

        // Pelacakan iklan with Jitu's property flow, waiting for a pixel and token; nothing goes out before.
        PropertyAdDefaults::provision($tenant);

        // Jitu's wording about paying (a follow-up, two stage actions, "KPR ditolak") follows the payment model once saved.
        PaymentDefaults::provision($tenant);

        // Jitu's playbook and AI settings, drafts until "Naikkan ke Auto"; an agency that already set its AI keeps it.
        if ($tenant->industry()->key === 'properti' && blank($tenant->ai_settings)) {
            $tenant->forceFill(['ai_settings' => AiSettings::NEW_PROPERTY])->save();
        }
    }

    private function replace(Tenant $tenant): void
    {
        $tenant->followUpRules()->delete();
        $tenant->stages()->delete();
        $tenant->lostReasons()->delete();
        $tenant->leadSources()->delete();
    }

    /** Adds what the template has and the agency lacks, matched by name. */
    private function add(Tenant $tenant, Industry $industry): void
    {
        $have = $tenant->stages()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        foreach ($industry->config('stages') as $template) {
            [$name, $type, $requirement, $action, $hours] = $template;

            if (! in_array(mb_strtolower($name), $have, true)) {
                $tenant->stages()->create([
                    'name' => $name, 'position' => 0, 'type' => $type, 'requirement' => $requirement,
                    'default_action' => $action, 'default_due_hours' => $hours,
                ]);
            }
        }
        $this->orderStages($tenant, $industry);
        $this->markTemplateStages($tenant, $industry);

        $reasons = $tenant->lostReasons()->pluck('label')->map(fn ($n) => mb_strtolower($n))->all();
        foreach ($industry->config('lost_reasons') as $label) {
            in_array(mb_strtolower($label), $reasons, true) ?: $tenant->lostReasons()->create(['label' => $label]);
        }

        $sources = $tenant->leadSources()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        foreach ($industry->config('lead_sources') as [$name, $type]) {
            in_array(mb_strtolower($name), $sources, true) ?: $tenant->leadSources()->create(['name' => $name, 'type' => $type]);
        }

        foreach ($industry->config('follow_ups') as [$name, $stageName, $days, $body]) {
            $stageId = $tenant->stages()->where('name', $stageName)->value('id');

            if (! $tenant->followUpRules()->where('name', $name)->where('stage_id', $stageId)->exists()) {
                // Switched off until the agency has checked the wording
                $tenant->followUpRules()->create(['name' => $name, 'stage_id' => $stageId, 'delay_days' => $days, 'body' => $body, 'active' => false]);
            }
        }
    }

    /**
     * Open stages first, then won, then lost. Within each group the stages the agency already had keep their
     * order and come before the ones just added, which follow the template's order.
     */
    private function orderStages(Tenant $tenant, Industry $industry): void
    {
        $template = array_map(fn (array $s) => mb_strtolower($s[0]), $industry->config('stages'));
        $group = fn (StageType $t) => match ($t) {
            StageType::Open => 0, StageType::Won => 1, StageType::Lost => 2
        };

        $stages = $tenant->stages()->orderBy('position')->orderBy('id')->get()
            ->sortBy(fn ($s) => [$group($s->type), $s->position === 0 ? 1 : 0, $s->position === 0 ? array_search(mb_strtolower($s->name), $template, true) : $s->position])
            ->values();

        foreach ($stages as $i => $stage) {
            $stage->position === $i + 1 ?: $stage->forceFill(['position' => $i + 1])->save();
        }
    }

    /**
     * Remembers which step of the template each stage is (stages.template_key), so automatic moves still find
     * "Menunggu pembayaran" or "Terkualifikasi" after the agency renames it. Stages that already have a key keep it.
     */
    private function markTemplateStages(Tenant $tenant, Industry $industry): void
    {
        if (! in_array($industry->key, ['retail', 'properti', 'digital'], true)) {
            return;
        }

        $stages = $tenant->stages()->orderBy('position')->orderBy('id')->get();
        $taken = $stages->pluck('template_key')->filter()->all();
        $rows = $stages->whereNull('template_key')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type->value, 'requirement' => $s->requirement?->value])->values()->all();
        $found = match ($industry->key) {
            'retail' => RetailStages::match($rows),
            'digital' => DigitalStages::match($rows),
            default => PropertyStages::match($rows),
        };

        foreach ($found as $key => $id) {
            in_array($key, $taken, true) ?: $stages->firstWhere('id', $id)->forceFill(['template_key' => $key])->save();
        }
    }

    /** The AI texts follow the industry unless the agency wrote its own. */
    private function aiTexts(Tenant $tenant, Industry $from, Industry $to): void
    {
        $changes = [];

        if (blank($tenant->ai_instructions) || trim($tenant->ai_instructions) === trim($from->config('ai_instructions'))) {
            $changes['ai_instructions'] = $to->config('ai_instructions');
        }
        if (blank($tenant->ai_handoff_keywords) || trim($tenant->ai_handoff_keywords) === implode(', ', $from->config('handoff_keywords'))) {
            $changes['ai_handoff_keywords'] = implode(', ', $to->config('handoff_keywords'));
        }

        $changes === [] ?: $tenant->forceFill($changes)->save();
    }
}
