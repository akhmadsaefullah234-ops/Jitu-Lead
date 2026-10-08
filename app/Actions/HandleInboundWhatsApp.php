<?php

namespace App\Actions;

use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Stage;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\InboundMessage;
use App\WhatsApp\StatusUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns what a provider reports into CRM data: a chat from a new number
 * becomes a lead and goes through the assignment rules (PRD F9).
 */
class HandleInboundWhatsApp
{
    public function __construct(private AssignLead $assign, private CurrentTenant $current) {}

    /**
     * Must run with the channel's tenant as the current tenant.
     */
    public function message(WaChannel $channel, InboundMessage $incoming): ?WaMessage
    {
        if (WaMessage::query()->where('channel_id', $channel->getKey())->where('external_id', $incoming->externalId)->exists()) {
            return null; // Providers retry deliveries; each message is stored once.
        }

        try {
            return DB::transaction(function () use ($channel, $incoming) {
                $lead = Lead::query()->where('phone', $incoming->from)->latest('id')->first() ?? $this->createLead($incoming);

                $conversation = WaConversation::query()->firstOrCreate(
                    ['lead_id' => $lead->getKey()],
                    ['phone' => $incoming->from],
                );

                $message = $conversation->messages()->create([
                    'channel_id' => $channel->getKey(),
                    'direction' => 'in',
                    'type' => $incoming->type,
                    'body' => $incoming->body,
                    'media' => $incoming->media,
                    'status' => MessageStatus::Delivered,
                    'external_id' => $incoming->externalId,
                    'sent_at' => $incoming->sentAt,
                ]);

                $conversation->forceFill([
                    'last_inbound_at' => $incoming->sentAt,
                    'last_inbound_channel_id' => $channel->getKey(),
                    'last_message_at' => $incoming->sentAt,
                    'unread_count' => $conversation->unread_count + 1,
                ]);

                if ($incoming->referral !== null) {
                    // A new ad click starts a new window, which opens once the agent replies.
                    $conversation->forceFill([
                        'ad_entry_at' => $incoming->sentAt,
                        'free_window_ends_at' => null,
                        'ad_data' => $incoming->referral,
                    ]);
                }

                $conversation->save();

                $lead->activities()->create([
                    'type' => 'whatsapp_in',
                    'body' => 'Chat WhatsApp masuk',
                    'meta' => ['message_id' => $message->getKey()],
                ]);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // The same delivery arrived twice at once.
        }
    }

    public function status(WaChannel $channel, StatusUpdate $update): void
    {
        $message = WaMessage::query()->where('channel_id', $channel->getKey())->where('external_id', $update->externalId)->first();

        if ($message === null) {
            return;
        }

        if ($update->status === MessageStatus::Failed) {
            $message->update(['status' => MessageStatus::Failed, 'error' => $update->error]);

            return;
        }

        if ($update->status->rank() > $message->status->rank()) {
            $message->update(['status' => $update->status]);
        }
    }

    public function session(WaChannel $channel, string $state): void
    {
        $state === 'connected' ? $channel->markConnected() : $channel->markDisconnected($state === 'qr_required' ? 'Perlu scan QR ulang.' : 'Sesi terputus.');
    }

    private function createLead(InboundMessage $incoming): Lead
    {
        $fromAd = $incoming->referral !== null;
        $source = LeadSource::query()->firstOrCreate(
            ['name' => $fromAd ? 'Iklan Meta' : 'WhatsApp'],
            ['type' => $fromAd ? 'ads' : 'whatsapp'],
        );

        $stage = Stage::query()->where('type', 'open')->orderBy('position')->firstOrFail();

        $lead = Lead::create([
            'name' => $incoming->contactName ?: $incoming->from,
            'phone' => $incoming->from,
            'lead_source_id' => $source->getKey(),
            'stage_id' => $stage->getKey(),
            'owner_id' => $this->assign->nextAgent()?->getKey(),
            'interest' => 'warm',
            'next_action' => $stage->default_action,
            'next_action_due_at' => $stage->default_due_hours === null ? null : now()->addHours($stage->default_due_hours),
            'custom_fields' => $fromAd ? ['iklan' => $incoming->referral] : null,
        ]);

        $lead->activities()->create(['type' => 'created', 'body' => 'Lead dibuat dari chat WhatsApp']);

        return $lead;
    }
}
