<?php

namespace App\Reports;

use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Enums\WaChannelType;
use App\Models\ConversionEvent;
use App\Models\Stage;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Support\CurrentTenant;
use App\Support\Period;
use App\WhatsApp\BusinessLabels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on "AI dan Survei" (property agencies) for one period, limited like the Dashboard to the leads the
 * viewer may see (App\Reports\DashboardData::leads). Counted with aggregate queries; kept CACHE_SECONDS. Runs
 * inside the tenant.
 *
 * - New conversation: a chat whose first message from the customer came in the period.
 * - Replied to the second message: of those, the chats where the customer wrote again after our first answer
 *   (AI or agent), so the conversation kept going past the opening question.
 * - Survey by AI: a survey the AI booked from the chat (lead_activities "ai_survey"): sent by the AI itself
 *   (Otomatis) or sent by an agent from the AI's suggestion in the Inbox (Saran). Time to survey: from the lead
 *   coming in to that booking.
 * - Handover: the AI passing the chat to an agent (lead_activities "ai_handoff"), with its reason.
 * - Per stage: the Meta event the stage sends (Pelacakan, "Event dari pipeline") and how many went out or failed in
 *   the period, and the WhatsApp Business label it puts on the chat and how many were put on.
 */
class AiSurveyData
{
    public const CACHE_SECONDS = 60;

    public function __construct(private DashboardData $dashboard) {}

    /** @return array<string, mixed> */
    public function summary(Period $period): array
    {
        $viewer = (app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false) ? 'all' : 'u'.auth()->id();

        return Cache::remember('ai-survey:'.$this->dashboard->tenant()->getKey().':'.$viewer.':'.$period->key(), self::CACHE_SECONDS,
            fn () => $this->compute($period));
    }

    /** @return array<string, mixed> */
    private function compute(Period $period): array
    {
        $tenantId = $this->dashboard->tenant()->getKey();
        $visible = $this->dashboard->leads()->reorder()->select('leads.id');
        [$from, $to] = $period->bounds();

        // Chats of visible leads with the first message from the customer in the period, and whether they wrote again after our first answer.
        $firsts = DB::table('wa_conversations as c')->where('c.tenant_id', $tenantId)->whereIn('c.lead_id', clone $visible)
            ->select('c.id')
            ->selectRaw("(select min(m.sent_at) from wa_messages m where m.conversation_id = c.id and m.direction = 'in') as first_in");
        $answered = "(select min(o.sent_at) from wa_messages o where o.conversation_id = x.id and o.direction = 'out'
            and o.status <> ? and o.is_intro = false and o.sent_at >= x.first_in)";
        $chats = DB::query()->fromSub($firsts, 'x')->whereBetween('x.first_in', [$from, $to])
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when {$answered} is not null then 1 else 0 end) as answered", [MessageStatus::Failed->value])
            ->selectRaw("sum(case when exists (select 1 from wa_messages r where r.conversation_id = x.id and r.direction = 'in'
                and r.sent_at > {$answered}) then 1 else 0 end) as replied", [MessageStatus::Failed->value])
            ->first();

        $activities = fn (string $type) => $period->apply(DB::table('lead_activities as a'), 'a.created_at')
            ->where('a.tenant_id', $tenantId)->where('a.type', $type)->whereIn('a.lead_id', clone $visible);

        $surveys = $activities('ai_survey')->join('leads as l', 'l.id', '=', 'a.lead_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when a.body like '%(AI)%' then 1 else 0 end) as auto")
            ->selectRaw('avg(greatest(0, extract(epoch from (a.created_at - l.created_at)))) as seconds')
            ->first();

        $handoffs = $activities('ai_handoff')->selectRaw('a.body, count(*) as n')->groupBy('a.body')->get()
            ->groupBy(fn ($r) => self::reason((string) $r->body))->map(fn ($g) => (int) $g->sum('n'))->sortKeys()->sortDesc();

        $total = (int) ($chats->total ?? 0);
        $surveyTotal = (int) ($surveys->total ?? 0);

        return [
            'conversations' => $total,
            'answered' => (int) ($chats->answered ?? 0),
            'replied' => (int) ($chats->replied ?? 0),
            'replied_rate' => DashboardData::rate((int) ($chats->replied ?? 0), (int) ($chats->answered ?? 0)),
            'surveys' => $surveyTotal,
            'surveys_auto' => (int) ($surveys->auto ?? 0),
            'surveys_suggested' => $surveyTotal - (int) ($surveys->auto ?? 0),
            'survey_rate' => DashboardData::rate($surveyTotal, $total),
            'hours_to_survey' => $surveys?->seconds === null ? null : round((float) $surveys->seconds / 3600, 1),
            'handoffs' => (int) $handoffs->sum(),
            'handoff_reasons' => $handoffs->take(8)->all(),
            'stages' => $this->stages($period),
        ];
    }

    /**
     * The handover reason without its prefix and without detail after a colon ("Pelanggan minta bicara dengan
     * agen: ..." and "... : ..." count as one reason).
     */
    public static function reason(string $body): string
    {
        $failed = str_starts_with($body, 'AI gagal menjawab');
        $text = trim((string) preg_replace('/^(AI menyerahkan chat ke agen|AI gagal menjawab):?\s*/u', '', $body));
        $text = trim(explode(':', $text, 2)[0]);
        $text = mb_strimwidth($text, 0, 80, '…');

        if ($failed) {
            return 'AI gagal menjawab'.($text !== '' ? ' ('.$text.')' : '');
        }

        return $text !== '' ? $text : 'Tanpa alasan';
    }

    /**
     * Each open and won stage: leads that entered it, the Meta event it sends and its count, the label it puts on.
     *
     * @return list<array<string, mixed>>
     */
    private function stages(Period $period): array
    {
        // The agency's mapping, or the one a new property agency starts with before it saves Pelacakan; none on plans without pixels.
        $allowed = PlanLimits::current()?->feature('pixels') ?? true;
        $pipeline = $allowed ? (TrackingSetting::current()?->pipeline() ?? TrackingSetting::starting()) : null;
        $eventOf = $pipeline !== null && $pipeline['enabled'] ? $pipeline['stages'] : [];

        $events = $period->apply(ConversionEvent::query(), 'created_at')->where('trigger', 'stage')->reorder()
            ->selectRaw("event_name, sum(case when results->'meta'->>'ok' = 'true' then 1 else 0 end) as sent,
                sum(case when results->'meta'->>'ok' = 'false' then 1 else 0 end) as failed, count(*) as total")
            ->groupBy('event_name')->get()->keyBy('event_name');

        $channel = WaChannel::query()->ofType(WaChannelType::Gateway)->orderBy('position')->orderBy('id')->first();
        $labels = $channel ? BusinessLabels::settings($channel) : null;
        $labelOf = [];

        foreach ($labels !== null && $labels['enabled'] ? $labels['rules'] : [] as $rule) {
            foreach ($rule['stages'] as $stageId) {
                $labelOf[$stageId] ??= $rule['name'];
            }
        }

        $put = $period->apply(WaLabelEvent::query(), 'created_at')->reorder()->selectRaw('label, status, count(*) as n')
            ->groupBy('label', 'status')->get()->groupBy('label')
            ->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n));

        $counted = [];

        return Stage::query()->orderBy('position')->orderBy('id')->get()
            ->reject(fn (Stage $s) => $s->type->value === 'lost')
            ->map(function (Stage $s) use ($period, $eventOf, $events, $labelOf, $put, &$counted) {
                $event = $eventOf[$s->getKey()] ?? null;
                $label = $labelOf[$s->getKey()] ?? null;
                // A label goes on a chat once, whichever of its stages came first: counted on its first stage only.
                $first = $label !== null && ! in_array($label, $counted, true);
                $counted[] = $label;
                $labelCount = fn (array $statuses) => $first ? array_sum(array_map(fn ($st) => (int) ($put[$label][$st] ?? 0), $statuses)) : null;

                return [
                    'name' => $s->name,
                    'entered' => $this->dashboard->entered($period, [$s->getKey()])->count(),
                    'event' => $event,
                    'event_sent' => $event ? (int) ($events[$event]->sent ?? 0) : null,
                    'event_failed' => $event ? (int) ($events[$event]->failed ?? 0) : null,
                    'event_waiting' => $event ? (int) (($events[$event]->total ?? 0) - ($events[$event]->sent ?? 0) - ($events[$event]->failed ?? 0)) : null,
                    'label' => $label,
                    'label_shared' => $label !== null && ! $first,
                    'label_sent' => $labelCount(['sent']),
                    'label_open' => $labelCount(['pending', 'waiting_label']),
                    'label_failed' => $labelCount(['failed']),
                ];
            })->values()->all();
    }
}
