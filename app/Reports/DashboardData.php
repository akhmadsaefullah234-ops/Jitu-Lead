<?php

namespace App\Reports;

use App\Automation\RetailStages;
use App\Enums\Interest;
use App\Enums\MessageStatus;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Filament\Resources\Leads\LeadResource;
use App\FollowUps\AskProduct;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the Dashboard for one period, counted with aggregate queries (never by loading the leads),
 * limited to the agency and to the leads the signed-in person may see (an agent: their own). Each block is kept
 * CACHE_SECONDS per agency, viewer scope and period. Runs inside the tenant.
 *
 * "Entered a stage in the period" means a stage move into it in the period (lead_activities "stage_changed"), or
 * the lead sitting in it with stages_entered_at in the period (created straight into it).
 */
class DashboardData
{
    public const CACHE_SECONDS = 60;

    /** Sales value of a lead's order without shipping: the agreed value, else the order lines, else product price x quantity. */
    public const ORDER_VALUE = "coalesce(leads.deal_value, (select sum((e->>'price')::numeric * greatest(1, coalesce((e->>'quantity')::int, 1)))
            from jsonb_array_elements(case when jsonb_typeof(leads.order_items::jsonb) = 'array' then leads.order_items::jsonb else '[]'::jsonb end) e
            where e->>'price' is not null), (select p.price_from from properties p where p.id = leads.property_id) * greatest(1, coalesce(leads.quantity, 1)), 0)";

    private ?array $roles = null;

    public function __construct(private CurrentTenant $current) {}

    public function tenant(): Tenant
    {
        return $this->current->get();
    }

    public function isRetail(): bool
    {
        return $this->tenant()->industry()->key === 'retail';
    }

    /** Leads the viewer may see. */
    public function leads(): Builder
    {
        return LeadResource::onlyVisibleLeads(Lead::query());
    }

    public function created(Period $period): Builder
    {
        return $period->apply($this->leads(), 'leads.created_at');
    }

    /** @param  list<int>  $stageIds */
    public function entered(Period $period, array $stageIds): Builder
    {
        return self::enteredIn($this->leads(), $period, $stageIds);
    }

    /**
     * Leads that entered one of $stageIds during $period: moved into it then, or created straight into it.
     *
     * @param  list<int>  $stageIds
     */
    public static function enteredIn(Builder $query, Period $period, array $stageIds): Builder
    {
        $stageIds = array_values(array_filter(array_map('intval', $stageIds)));
        [$from, $to] = $period->bounds();

        if ($stageIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $in = implode(',', $stageIds);

        return $query->where(fn (Builder $q) => $q
            ->whereExists(fn ($a) => $a->selectRaw('1')->from('lead_activities as sa')
                ->whereColumn('sa.lead_id', 'leads.id')->where('sa.type', 'stage_changed')
                ->whereBetween('sa.created_at', [$from, $to])
                ->whereRaw("(sa.meta->>'to')::bigint in ({$in})"))
            ->orWhere(fn ($s) => $s->whereIn('leads.stage_id', $stageIds)->whereBetween('leads.stage_entered_at', [$from, $to])));
    }

    /**
     * The stages each card counts, found by their role rather than their name.
     *
     * @return array<string, list<int>>
     */
    public function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }

        $stages = Stage::query()->orderBy('position')->orderBy('id')->get();
        $won = $stages->filter(fn (Stage $s) => $s->type === StageType::Won)->modelKeys();
        $lost = $stages->filter(fn (Stage $s) => $s->type === StageType::Lost)->modelKeys();

        if ($this->isRetail()) {
            $retail = app(RetailStages::class);
            $id = fn (string $key) => $retail->stage($key)?->getKey();

            return $this->roles = [
                'order' => array_values(array_filter([$id(RetailStages::ORDER)])),
                'paid' => array_values(array_filter([$id(RetailStages::SHIPPING)])),
                'done' => array_values(array_filter([$id(RetailStages::DONE)])) ?: $won,
                'cancelled' => array_values(array_filter([$id(RetailStages::CANCELLED)])) ?: $lost,
                'won' => $won,
                'lost' => $lost,
            ];
        }

        $survey = $stages->first(fn (Stage $s) => $s->requirement === StageRequirement::Survey && $s->type === StageType::Open);
        $surveyed = $survey ? $stages->first(fn (Stage $s) => $s->type === StageType::Open && $s->position > $survey->position) : null;

        return $this->roles = [
            'survey' => $survey ? [$survey->getKey()] : [],
            'surveyed' => $surveyed ? [$surveyed->getKey()] : [],
            'booking' => $stages->filter(fn (Stage $s) => $s->type === StageType::Open && $s->requirement === StageRequirement::Booking)->modelKeys(),
            'won' => $won,
            'lost' => $lost,
        ];
    }

    /**
     * The headline numbers for $period and the period before it.
     *
     * @return array<string, array{now: int|float|null, before: int|float|null}>
     */
    public function summary(Period $period): array
    {
        return $this->cached('summary', $period, function () use ($period) {
            $now = $this->figures($period);
            $before = $this->figures($period->previous());

            return array_map(fn ($key) => ['now' => $now[$key], 'before' => $before[$key]], array_combine(array_keys($now), array_keys($now)));
        });
    }

    /** @return array<string, int|float|null> */
    private function figures(Period $period): array
    {
        $roles = $this->roles();
        $new = $this->created($period)->count();

        $common = [
            'new' => $new,
            'ads' => LeadOrigin::fromAds($this->created($period))->count(),
            'hot' => $this->created($period)->where('interest', Interest::Hot->value)->count(),
            'response' => $this->responseMinutes($this->created($period)),
        ];

        if ($this->isRetail()) {
            $orders = $this->entered($period, $roles['order'])->count();
            $paidQuery = $this->entered($period, $roles['paid']);
            $paid = (clone $paidQuery)->count();
            $sales = (int) (clone $paidQuery)->sum(DB::raw(self::ORDER_VALUE));

            return $common + [
                'orders' => $orders,
                'paid' => $paid,
                'done' => $this->entered($period, $roles['done'])->count(),
                'cancelled' => $this->entered($period, $roles['cancelled'])->count(),
                'sales' => $sales,
                'average' => $paid > 0 ? (int) round($sales / $paid) : null,
            ];
        }

        $won = $this->entered($period, $roles['won']);

        return $common + [
            'surveys_planned' => $this->leads()->whereBetween('survey_at', $period->bounds())->count(),
            'surveys_done' => $this->entered($period, $roles['surveyed'])->count(),
            'bookings' => $this->entered($period, $roles['booking'])->count(),
            'closings' => (clone $won)->count(),
            'closing_value' => (int) (clone $won)->sum('deal_value'),
        ];
    }

    /** Average minutes from a lead's first WhatsApp message to the first answer (agent or AI), or null. */
    private function responseMinutes(Builder $leads): ?float
    {
        $first = (clone $leads)->join('wa_conversations as rc', 'rc.lead_id', '=', 'leads.id')
            ->reorder()->select('rc.id as cid')
            ->selectRaw("(select min(m.sent_at) from wa_messages m where m.conversation_id = rc.id and m.direction = 'in') as fi");

        $seconds = DB::query()->fromSub($first->toBase(), 'x')->whereNotNull('x.fi')
            ->selectRaw("avg(extract(epoch from ((select min(o.sent_at) from wa_messages o where o.conversation_id = x.cid and o.direction = 'out'
                and o.status <> ? and o.is_intro = false and o.sent_at >= x.fi) - x.fi))) as s", [MessageStatus::Failed->value])
            ->value('s');

        return $seconds === null ? null : round((float) $seconds / 60, 1);
    }

    /**
     * How far the leads that came in during the period got: for each open and won stage, how many were in it or
     * later (a stage move into it counts, so a lead that was cancelled after paying still counts as paid).
     *
     * @return list<array{id: int, name: string, count: int, type: string}>
     */
    public function funnel(Period $period): array
    {
        return $this->cached('funnel', $period, function () use ($period) {
            $stages = Stage::query()->orderBy('position')->orderBy('id')->get();
            $positions = $stages->mapWithKeys(fn (Stage $s) => [$s->getKey() => $s->type === StageType::Lost ? -1 : $s->position]);
            $case = 'case '.$positions->map(fn ($pos, $id) => 'when s_id = '.(int) $id.' then '.(int) $pos)->implode(' ').' else -1 end';

            // Every stage each lead has been in: its current one and every stage it was moved into.
            $reached = $this->created($period)->reorder()->select('leads.id')
                ->selectRaw("(select max({$case}) from (select leads.stage_id as s_id union all select (a.meta->>'to')::bigint from lead_activities a
                    where a.lead_id = leads.id and a.type = 'stage_changed') moves) as reached")
                ->selectRaw('(select type from stages where stages.id = leads.stage_id) as now_type');

            $rows = DB::query()->fromSub($reached->toBase(), 'r')->selectRaw('reached, now_type, count(*) as n')->groupBy('reached', 'now_type')->get();

            return $stages->reject(fn (Stage $s) => $s->type === StageType::Lost)->values()->map(fn (Stage $s) => [
                'id' => $s->getKey(),
                'name' => $s->name,
                'type' => $s->type->value,
                'count' => (int) $rows->filter(fn ($r) => (int) $r->reached >= $s->position)->sum('n'),
            ])->all();
        });
    }

    /** Leads of the period that are now in a lost stage (not part of the funnel). */
    public function lostOf(Period $period): int
    {
        return $this->cached('lost', $period, fn () => $this->created($period)->whereIn('stage_id', $this->roles()['lost'])->count());
    }

    /**
     * Per product: leads, Hot, orders, paid, done, sales value, conversion lead to paid.
     *
     * @return list<array<string, mixed>>
     */
    public function products(Period $period): array
    {
        return $this->cached('products', $period, function () use ($period) {
            $roles = $this->roles();
            $created = $this->created($period)->reorder()->groupBy('leads.property_id')->selectRaw('leads.property_id as pid, count(*) as leads, sum(case when interest = ? then 1 else 0 end) as hot', [Interest::Hot->value])
                ->get()->keyBy('pid');
            $count = fn (array $ids) => $this->entered($period, $ids)->reorder()->groupBy('leads.property_id')
                ->selectRaw('leads.property_id as pid, count(*) as n, sum('.self::ORDER_VALUE.') as v')->get()->keyBy('pid');

            $orders = $count($this->isRetail() ? $roles['order'] : $roles['booking']);
            $paid = $count($this->isRetail() ? $roles['paid'] : $roles['won']);
            $done = $count($this->isRetail() ? $roles['done'] : $roles['won']);

            $ids = $created->keys()->merge($orders->keys())->merge($paid->keys())->merge($done->keys())->unique()->values();
            $names = DB::table('properties')->whereIn('id', $ids->filter()->all())->pluck('name', 'id');

            return $ids->map(fn ($pid) => [
                'id' => $pid !== null && $pid !== '' ? (int) $pid : null,
                'name' => $pid ? ($names[$pid] ?? 'Produk terhapus') : 'Tanpa produk',
                'leads' => (int) ($created[$pid]->leads ?? 0),
                'hot' => (int) ($created[$pid]->hot ?? 0),
                'orders' => (int) ($orders[$pid]->n ?? 0),
                'paid' => (int) ($paid[$pid]->n ?? 0),
                'done' => (int) ($done[$pid]->n ?? 0),
                'value' => (int) ($paid[$pid]->v ?? 0),
                'rate' => self::rate((int) ($paid[$pid]->n ?? 0), (int) ($created[$pid]->leads ?? 0)),
            ])->sortByDesc(fn ($r) => [$r['id'] !== null, $r['leads'], $r['paid']])->values()->all();
        });
    }

    /**
     * The 10 ads with the most leads; leads without ad data summed as one "organic" row.
     *
     * @return list<array<string, mixed>>
     */
    public function ads(Period $period): array
    {
        return $this->cached('ads', $period, function () use ($period) {
            $roles = $this->roles();
            $ad = "coalesce(nullif(leads.ad_title, ''), nullif(leads.ad_id, ''))";
            $created = $this->created($period)->reorder()->groupByRaw($ad)->selectRaw("{$ad} as ad, count(*) as leads")->get()->keyBy('ad');
            $count = fn (array $ids) => $this->entered($period, $ids)->reorder()->groupByRaw($ad)
                ->selectRaw("{$ad} as ad, count(*) as n, sum(".self::ORDER_VALUE.') as v')->get()->keyBy('ad');
            $orders = $count($this->isRetail() ? $roles['order'] : $roles['booking']);
            $paid = $count($this->isRetail() ? $roles['paid'] : $roles['won']);

            $row = fn ($key, string $name) => [
                'ad' => $key, 'name' => $name, 'leads' => (int) ($created[$key]->leads ?? 0),
                'orders' => (int) ($orders[$key]->n ?? 0), 'paid' => (int) ($paid[$key]->n ?? 0), 'value' => (int) ($paid[$key]->v ?? 0),
            ];

            $named = $created->keys()->merge($orders->keys())->merge($paid->keys())->filter(fn ($k) => $k !== null && $k !== '')->unique()
                ->map(fn ($k) => $row($k, (string) $k))->sortByDesc(fn ($r) => [$r['leads'], $r['paid']])->take(10)->values();

            $organic = $row(null, 'Tanpa data iklan / organik');

            return $organic['leads'] + $organic['orders'] > 0 ? [...$named->all(), $organic] : $named->all();
        });
    }

    /** @return array<string, int> lead count per LeadOrigin group */
    public function sources(Period $period): array
    {
        return $this->cached('sources', $period, function () use ($period) {
            $rows = $this->created($period)->reorder()->selectRaw('('.LeadOrigin::sql().') as origin, count(*) as n')->groupBy('origin')->pluck('n', 'origin');

            return array_map(fn ($key) => (int) ($rows[$key] ?? 0), array_combine(array_keys(LeadOrigin::LABELS), array_keys(LeadOrigin::LABELS)));
        });
    }

    /** @return array<string, int> what the AI and the automatic messages did in the period */
    public function automation(Period $period): array
    {
        return $this->cached('automation', $period, function () use ($period) {
            $visible = $this->leads()->reorder()->select('leads.id');
            $activities = fn (string $type) => $period->apply(DB::table('lead_activities'), 'created_at')
                ->where('tenant_id', $this->tenant()->getKey())->where('type', $type)->whereIn('lead_id', clone $visible);
            $conversations = DB::table('wa_conversations')->where('tenant_id', $this->tenant()->getKey())->whereIn('lead_id', clone $visible)->select('id');
            $logs = fn (bool $ask) => $period->apply(DB::table('follow_up_logs as l'), 'l.created_at')->where('l.tenant_id', $this->tenant()->getKey())
                ->whereIn('l.lead_id', clone $visible)->join('follow_up_rules as r', 'r.id', '=', 'l.follow_up_rule_id')
                ->where(fn ($q) => $ask ? $q->where('r.kind', AskProduct::KIND) : $q->whereNull('r.kind')->orWhere('r.kind', '!=', AskProduct::KIND));
            $welcome = fn (string $status) => $period->apply(DB::table('welcome_messages'), 'updated_at')->where('tenant_id', $this->tenant()->getKey())
                ->whereIn('lead_id', clone $visible)->where('status', $status)->count();
            $events = $period->apply(DB::table('conversion_events'), 'created_at')->where('tenant_id', $this->tenant()->getKey());

            return [
                'ai_replies' => $activities('ai_reply')->count(),
                'drafts_waiting' => DB::table('ai_drafts')->where('tenant_id', $this->tenant()->getKey())->where('status', 'pending')->whereIn('conversation_id', clone $conversations)->count(),
                'handoffs' => $activities('ai_handoff')->count(),
                // Why the AI handed over (most frequent first), and the handovers nobody answered within 3 minutes.
                'handoff_reasons' => $activities('ai_handoff')->selectRaw('body, count(*) as n')->groupBy('body')->orderByDesc('n')->limit(6)->pluck('n', 'body')
                    ->mapWithKeys(fn ($n, $body) => [trim(preg_replace('/^AI menyerahkan chat ke agen:\s*/', '', (string) $body)) => (int) $n])->all(),
                'handoff_unanswered' => $period->apply(DB::table('ai_drafts as d'), 'd.created_at')->where('d.tenant_id', $this->tenant()->getKey())
                    ->where('d.status', 'handoff')->whereIn('d.conversation_id', clone $conversations)->where('d.created_at', '<=', now()->subMinutes(3))
                    ->join('wa_messages as m', 'm.id', '=', 'd.message_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wa_messages as r')->whereColumn('r.conversation_id', 'm.conversation_id')
                        ->where('r.direction', 'out')->where('r.status', '!=', MessageStatus::Failed->value)
                        ->whereColumn('r.sent_at', '>=', 'm.sent_at')->whereRaw("r.sent_at <= m.sent_at + interval '3 minutes'"))
                    ->count(),
                'voice_unanswered' => $period->apply(DB::table('wa_messages as v'), 'v.sent_at')->where('v.tenant_id', $this->tenant()->getKey())
                    ->whereIn('v.conversation_id', clone $conversations)->where('v.direction', 'in')->where('v.type', 'audio')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wa_messages as r')->whereColumn('r.conversation_id', 'v.conversation_id')
                        ->where('r.direction', 'out')->where('r.status', '!=', MessageStatus::Failed->value)->whereColumn('r.sent_at', '>=', 'v.sent_at'))
                    ->count(),
                'follow_ups_sent' => $logs(false)->where('l.status', 'sent')->count(),
                'follow_ups_skipped' => $logs(false)->where('l.status', 'skipped')->count(),
                'ask_sent' => $logs(true)->where('l.status', 'sent')->count(),
                'ask_answered' => $period->apply(DB::table('lead_activities'), 'created_at')->where('tenant_id', $this->tenant()->getKey())
                    ->whereIn('lead_id', clone $visible)->whereRaw("meta->>'product_source' = 'ask_reply'")->count(),
                'welcome_sent' => $welcome('sent'),
                'welcome_skipped' => $welcome('skipped'),
                'welcome_failed' => $welcome('failed'),
                'meta_sent' => (clone $events)->whereRaw("results->'meta'->>'ok' = 'true'")->count(),
                'meta_failed' => (clone $events)->whereRaw("results->'meta'->>'ok' = 'false'")->count(),
            ];
        });
    }

    /**
     * Per agent (only when the viewer sees the whole team and there is more than one person): leads, orders
     * (retail) or bookings, closings, and average first answer.
     *
     * @return list<array<string, mixed>>
     */
    public function agents(Period $period): array
    {
        $team = $this->tenant()->activeUsers()->orderBy('name')->get(['users.id', 'users.name']);

        if ($team->count() < 2 || ! ($this->current->roleOf(auth()->user())?->seesAllLeads() ?? false)) {
            return [];
        }

        return $this->cached('agents', $period, function () use ($period, $team) {
            $roles = $this->roles();
            $per = fn (Builder $q) => $q->reorder()->groupBy('leads.owner_id')->selectRaw('leads.owner_id as uid, count(*) as n')->pluck('n', 'uid');
            $leads = $per($this->created($period));
            $orders = $per($this->entered($period, $this->isRetail() ? $roles['order'] : $roles['booking']));
            $won = $per($this->entered($period, $roles['won']));

            return $team->map(fn ($u) => [
                'name' => $u->name,
                'leads' => (int) ($leads[$u->id] ?? 0),
                'orders' => (int) ($orders[$u->id] ?? 0),
                'won' => (int) ($won[$u->id] ?? 0),
                'response' => $this->responseMinutes($this->created($period)->where('leads.owner_id', $u->id)),
            ])->sortByDesc('leads')->values()->all();
        });
    }

    /** @return array{channels: Collection, overdue: int, unattended: ?int} right now, whatever the period */
    public function status(): array
    {
        $seesTeam = $this->current->roleOf(auth()->user())?->seesAllLeads() ?? false;

        return [
            'channels' => WaChannel::query()->orderBy('position')->orderBy('id')->get(['id', 'name', 'type', 'status', 'phone']),
            'overdue' => $this->leads()->overdue()->count(),
            'unattended' => $seesTeam ? Lead::query()->unattended($this->tenant()->leadWaitMinutes())->count() : null,
        ];
    }

    public static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function cached(string $block, Period $period, \Closure $compute): mixed
    {
        $viewer = ($this->current->roleOf(auth()->user())?->seesAllLeads() ?? false) ? 'all' : 'u'.auth()->id();

        return Cache::remember("dashboard:{$this->tenant()->getKey()}:{$viewer}:{$period->key()}:{$block}", self::CACHE_SECONDS, $compute);
    }
}
