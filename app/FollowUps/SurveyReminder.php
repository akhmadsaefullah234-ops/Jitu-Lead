<?php

namespace App\FollowUps;

use App\Ai\PropertyClientFacts;
use App\Enums\StageRequirement;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The WhatsApp reminder a property client gets the day before their survey (H-1 at the rule's sending time), sent
 * by RunFollowUps like any follow-up. One per survey date: a new date gets its own reminder. It is not sent when
 * the survey was cancelled (no date), the lead left the survey stage, or the date was set after the reminder was
 * due (the client was just told). Runs inside the tenant.
 */
class SurveyReminder
{
    public const KIND = 'survey_reminder';

    public const DEFAULT_BODY = "Halo kak {nama} 😊 Mengingatkan jadwal survei {tanggal_survei} pukul {jam_survei} di {lokasi_survei}.\n\nSampai bertemu ya kak. Bila ada perubahan, balas pesan ini saja 🙏";

    /**
     * The tenant's rule, made switched off the first time it is needed (like the starter follow-ups), so nothing new
     * goes to an agency's clients until it is turned on in Follow-up.
     */
    public static function rule(): FollowUpRule
    {
        return self::current() ?? FollowUpRule::query()->create(self::DEFAULTS);
    }

    /** The tenant's rule, when it was made. */
    public static function current(): ?FollowUpRule
    {
        return FollowUpRule::query()->where('kind', self::KIND)->first();
    }

    /** The tenant's rule, or an unsaved one with the defaults: looking at the Follow-up page never creates it. */
    public static function orDefaults(): FollowUpRule
    {
        return self::current() ?? new FollowUpRule(self::DEFAULTS);
    }

    public const DEFAULTS = [
        'name' => 'Pengingat survei H-1', 'kind' => self::KIND, 'stage_id' => null,
        'delay_days' => 1, 'send_time' => '09:00', 'body' => self::DEFAULT_BODY, 'active' => false,
    ];

    /** When the reminder for this survey is due: delay_days before the survey's day, at the sending time. */
    public static function dueAt(FollowUpRule $rule, CarbonImmutable $surveyAt, string $timezone): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $rule->send_time));

        return $surveyAt->setTimezone($timezone)->startOfDay()->subDays(max(1, (int) $rule->delay_days))->setTime($hour, $minute);
    }

    /** @return Collection<int, Lead> leads whose reminder is due now */
    public static function due(FollowUpRule $rule, string $timezone, int $catchUpHours): Collection
    {
        $now = now();

        return Lead::query()->open()
            ->whereNotNull('phone')
            ->whereNotNull('survey_at')
            ->where('survey_at', '>', $now)
            ->where('survey_at', '<=', $now->copy()->addDays(max(1, (int) $rule->delay_days) + 2))
            ->when($rule->stage_id,
                fn (Builder $q) => $q->where('stage_id', $rule->stage_id),
                fn (Builder $q) => $q->whereHas('stage', fn (Builder $s) => $s->where('requirement', StageRequirement::Survey->value)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('follow_up_logs')
                ->whereColumn('follow_up_logs.lead_id', 'leads.id')
                ->where('follow_up_logs.follow_up_rule_id', $rule->getKey())
                ->whereColumn('follow_up_logs.survey_at', 'leads.survey_at'))
            ->with(['owner', 'property'])
            ->orderBy('id')
            ->get()
            ->filter(function (Lead $lead) use ($rule, $timezone, $now, $catchUpHours) {
                $dueAt = self::dueAt($rule, CarbonImmutable::instance($lead->survey_at), $timezone);
                $setBefore = $lead->survey_scheduled_at === null || $lead->survey_scheduled_at->lessThanOrEqualTo($dueAt);

                return $setBefore && $dueAt->lessThanOrEqualTo($now) && $dueAt->addHours($catchUpHours)->greaterThanOrEqualTo($now);
            })
            ->values();
    }

    /** {tanggal_survei}, {jam_survei} and {lokasi_survei} for a lead, in the agency's time zone. */
    public static function values(Lead $lead): array
    {
        if ($lead->survey_at === null) {
            return ['tanggal_survei' => '', 'jam_survei' => '', 'lokasi_survei' => (string) $lead->survey_location];
        }

        $timezone = app(CurrentTenant::class)->get()?->timezone ?: (string) config('app.timezone');
        $local = CarbonImmutable::instance($lead->survey_at)->setTimezone($timezone)->locale('id');

        return [
            'tanggal_survei' => $local->translatedFormat('l, j F Y'),
            'jam_survei' => $local->format('H.i').' '.PropertyClientFacts::zone($timezone),
            'lokasi_survei' => (string) $lead->survey_location,
        ];
    }
}
