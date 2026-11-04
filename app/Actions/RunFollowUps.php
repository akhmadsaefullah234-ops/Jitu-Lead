<?php

namespace App\Actions;

use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\FollowUps\AskProduct;
use App\FollowUps\GeneralFollowUps;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use App\WhatsApp\RouteKind;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\TemplateRenderer;
use App\WhatsApp\WhatsAppException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sends the automatic follow-ups that have come due for one agency.
 *
 * A rule fires once per lead and stage, H+N days after the lead entered that
 * stage, at the rule's sending time. A lead whose product has its own active
 * rules for its stage gets only that product's sequence; every other lead gets
 * the general one (rules without a product), never both. A lead whose product
 * changes mid-sequence picks up the new sequence from where it is: a step at or
 * before one already sent (or skipped) in this stage is not sent. When the
 * general follow-ups are not used (App\FollowUps\GeneralFollowUps, e.g. a
 * shop with many products), they are skipped and a lead without a product
 * gets the one "Tanya produk" message (App\FollowUps\AskProduct) instead. A follow-up due within two hours
 * of the form's WhatsApp welcome (App\Welcome) waits for those two hours. It stays quiet when the lead has written
 * back since or when only a paid Meta template could carry the message; those
 * leads are looked at again on the next run until the catch-up window closes.
 */
class RunFollowUps
{
    /** A follow-up later than this past its sending time is dropped, e.g. after an outage. */
    public const CATCH_UP_HOURS = 24;

    /** A follow-up due this soon after the form's WhatsApp welcome waits until this much time has passed. */
    public const AFTER_WELCOME_HOURS = 2;

    public function __construct(
        private CurrentTenant $current,
        private SendWhatsAppMessage $send,
        private RouteSelector $routes,
        private TemplateRenderer $renderer,
        private SendLibraryItem $sendItem,
    ) {}

    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function __invoke(Tenant $tenant, int $limit = 50): array
    {
        return $this->current->run($tenant, function () use ($tenant, $limit) {
            $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

            // A read-only subscription stops automatic follow-ups.
            if (PlanLimits::for($tenant)->readOnly()) {
                return $result;
            }

            $touched = [];
            $timezone = $tenant->timezone ?: config('app.timezone');

            // A shop with many products follows up per product; the general rules are then skipped (not changed)
            // and leads without a product get the one "Tanya produk" message instead.
            $general = GeneralFollowUps::for($tenant)->applies();

            if (! $general) {
                AskProduct::rule();
            }

            $rules = FollowUpRule::query()->where('active', true)
                ->when(
                    $general,
                    fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', AskProduct::KIND)),
                    fn (Builder $q) => $q->where(fn ($q) => $q->whereNotNull('property_id')->orWhere('kind', AskProduct::KIND)),
                )
                ->orderBy('delay_days')->orderBy('send_time')->get();

            foreach ($rules as $rule) {
                foreach ($this->due($rule, $timezone) as $lead) {
                    if ($limit <= $result['sent'] + $result['failed']) {
                        return $result;
                    }

                    // One automatic message per lead per run; the next rule waits for its turn.
                    if (isset($touched[$lead->getKey()])) {
                        continue;
                    }

                    $outcome = $this->handle($tenant, $rule, $lead);

                    if ($outcome !== null) {
                        $result[$outcome]++;
                        $touched[$lead->getKey()] = true;
                    }
                }
            }

            return $result;
        });
    }

    /** @return Collection<int, Lead> */
    private function due(FollowUpRule $rule, string $timezone)
    {
        $anchor = 'coalesce(leads.stage_entered_at, leads.created_at)';
        $now = now();

        return Lead::query()
            ->open()
            ->whereNotNull('phone')
            ->when($rule->stage_id, fn (Builder $q) => $q->where('stage_id', $rule->stage_id))
            // "Tanya produk": once per lead, only while it has no product.
            ->when($rule->kind === AskProduct::KIND, fn (Builder $q) => $q->whereNull('property_id')
                ->whereNotExists(fn ($sent) => $sent->selectRaw('1')->from('follow_up_logs')
                    ->whereColumn('follow_up_logs.lead_id', 'leads.id')
                    ->where('follow_up_logs.follow_up_rule_id', $rule->getKey())))
            ->when(
                $rule->property_id,
                fn (Builder $q) => $q->where('property_id', $rule->property_id),
                // The general sequence only for leads whose product has no active rule of its own for this stage.
                fn (Builder $q) => $q->whereNotExists(fn ($own) => $own->selectRaw('1')->from('follow_up_rules as own')
                    ->whereColumn('own.tenant_id', 'leads.tenant_id')
                    ->whereColumn('own.property_id', 'leads.property_id')
                    ->where('own.active', true)
                    ->where(fn ($s) => $s->whereColumn('own.stage_id', 'leads.stage_id')->orWhereNull('own.stage_id'))),
            )
            // Loose bounds in SQL; the exact local-time check follows below.
            ->whereRaw("$anchor <= ?", [$now->copy()->subDays($rule->delay_days - 1)])
            ->whereRaw("$anchor >= ?", [$now->copy()->subDays($rule->delay_days + 2)])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('follow_up_logs')
                ->whereColumn('follow_up_logs.lead_id', 'leads.id')
                ->whereColumn('follow_up_logs.stage_id', 'leads.stage_id')
                ->where('follow_up_logs.follow_up_rule_id', $rule->getKey()))
            // Never backwards and never twice after a product change: a step of any sequence at the same point
            // or later was already handled for this lead in this stage.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('follow_up_logs')
                ->join('follow_up_rules as done', 'done.id', '=', 'follow_up_logs.follow_up_rule_id')
                ->whereColumn('follow_up_logs.lead_id', 'leads.id')
                ->whereColumn('follow_up_logs.stage_id', 'leads.stage_id')
                ->where('done.id', '!=', $rule->getKey())
                ->where(fn ($later) => $later->where('done.delay_days', '>', $rule->delay_days)
                    ->orWhere(fn ($same) => $same->where('done.delay_days', $rule->delay_days)->where('done.send_time', '>=', $rule->send_time))))
            ->with(['owner', 'property'])
            ->orderBy('id')
            ->get()
            ->filter(function (Lead $lead) use ($rule, $timezone, $now) {
                $dueAt = $rule->dueAt($lead->stageEnteredAt(), $timezone);

                return $dueAt->lessThanOrEqualTo($now) && $dueAt->addHours(self::CATCH_UP_HOURS)->greaterThanOrEqualTo($now);
            });
    }

    /** @return 'sent'|'failed'|'skipped'|null Null means the lead stays queued for a later run. */
    private function handle(Tenant $tenant, FollowUpRule $rule, Lead $lead): ?string
    {
        $conversation = WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]);

        if ($conversation->last_inbound_at !== null && $conversation->last_inbound_at->greaterThan($this->quietSince($lead))) {
            return $this->log($rule, $lead, 'skipped', 'Lead sudah membalas');
        }

        // Right after the form's WhatsApp welcome the follow-up waits, so the buyer never gets two in a row.
        // The welcome is the shop writing, not the buyer, so it never stops the sequence.
        if ($conversation->messages()->where('origin', 'welcome')->where('status', '!=', MessageStatus::Failed)
            ->where('sent_at', '>', now()->subHours(self::AFTER_WELCOME_HOURS))->exists()) {
            return null;
        }

        $decision = $this->routes->decide($conversation);

        if (! $decision->canSend() || $decision->kind === RouteKind::PaidTemplate) {
            return null;
        }

        $actor = $this->actorFor($tenant, $lead);

        if ($actor === null) {
            return null;
        }

        // The products a "Tanya produk" list shows, kept in its order so a reply "2" maps to the second.
        $listed = str_contains($rule->body, '{daftar_produk}') ? AskProduct::products()->modelKeys() : null;

        if ($listed === []) {
            return $this->log($rule, $lead, 'skipped', 'Belum ada produk aktif untuk ditawarkan');
        }

        $text = $this->renderer->render($rule->body, $lead, $actor, $tenant->name)['text'];
        $message = ($this->send)($conversation, $actor, $text);

        if ($message->status === MessageStatus::Failed) {
            return $this->log($rule, $lead, 'failed', (string) $message->error);
        }

        return $this->log($rule, $lead, 'sent', $rule->name.$this->attach($rule, $conversation, $actor), $listed);
    }

    /**
     * Since when a reply from the lead stops its follow-ups: entering the stage, or, for a lead that picked its
     * product by answering "Tanya produk" with a number, that answer (it starts the product's sequence).
     */
    private function quietSince(Lead $lead): CarbonInterface
    {
        $since = $lead->stageEnteredAt();

        if ($lead->product_source === 'ask_reply') {
            $picked = $lead->activities()->reorder()->where('meta->product_source', 'ask_reply')->latest('id')->value('created_at');
            $since = $picked !== null && $picked->greaterThan($since) ? $picked : $since;
        }

        return $since;
    }

    /** The rule's library item follows the text. A problem with it never undoes the message already sent. */
    private function attach(FollowUpRule $rule, WaConversation $conversation, User $actor): string
    {
        $asset = $rule->media_asset_id ? $rule->mediaAsset()->where('active', true)->first() : null;

        if ($asset === null) {
            return '';
        }

        try {
            $sent = ($this->sendItem)($conversation->refresh(), $actor, $asset);
        } catch (WhatsAppException $e) {
            return ' (lampiran gagal: '.$e->getMessage().')';
        }

        return $sent->status === MessageStatus::Failed ? ' (lampiran gagal: '.$sent->error.')' : ' + '.$asset->title;
    }

    private function actorFor(Tenant $tenant, Lead $lead): ?User
    {
        return $lead->owner ?? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->first();
    }

    /** @param  list<int>|null  $productIds  what a "Tanya produk" message listed, in order */
    private function log(FollowUpRule $rule, Lead $lead, string $status, ?string $note, ?array $productIds = null): string
    {
        FollowUpLog::query()->create([
            'product_ids' => $productIds,
            'follow_up_rule_id' => $rule->getKey(),
            'lead_id' => $lead->getKey(),
            'stage_id' => $lead->stage_id,
            'status' => $status,
            'note' => $note !== null ? mb_substr($note, 0, 250) : null,
        ]);

        return $status;
    }
}
