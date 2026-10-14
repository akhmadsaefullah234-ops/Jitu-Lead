<?php

namespace App\Actions;

use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Media\Library;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Support\CurrentTenant;
use App\WhatsApp\Providers;
use App\WhatsApp\RouteDecision;
use App\WhatsApp\RouteKind;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\SendResult;
use App\WhatsApp\TemplateRenderer;
use App\WhatsApp\WhatsAppException;
use App\WhatsApp\WhatsAppProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends one message to a lead, choosing the number itself (PRD F9). The route is
 * decided here at send time, never taken from what the screen last showed, so a
 * window that closed meanwhile cannot slip a paid message through.
 */
class SendWhatsAppMessage
{
    public const MAX_LENGTH = 4096;

    public function __construct(
        private RouteSelector $routes,
        private Providers $providers,
        private TemplateRenderer $renderer,
        private CurrentTenant $current,
    ) {}

    /**
     * @param  bool  $confirmPaid  The agent agreed that Meta bills this message.
     */
    public function __invoke(WaConversation $conversation, User $actor, ?string $text = null, bool $confirmPaid = false, ?WaTemplate $template = null): WaMessage
    {
        $this->assertWritable();

        $decision = $this->routes->decide($conversation);

        if (! $decision->canSend()) {
            throw new WhatsAppException($decision->note);
        }

        $message = $decision->isPaid()
            ? $this->sendPaidTemplate($conversation, $actor, $decision, $confirmPaid, $template)
            : $this->sendText($conversation, $actor, $decision, $text);

        return $this->finish($conversation, $actor, $message);
    }

    /**
     * Sends a picture or file from the private disk, with an optional caption. Only inside a chat window:
     * WhatsApp does not let a business open a conversation with a file.
     *
     * @param  string  $path  Path on the local (private) disk.
     * @param  string  $name  The file name the customer sees.
     */
    public function sendFile(WaConversation $conversation, User $actor, string $path, string $name, ?string $caption = null): WaMessage
    {
        $this->assertWritable();

        $decision = $this->routes->decide($conversation);

        if (! $decision->canSend()) {
            throw new WhatsAppException($decision->note);
        }

        if ($decision->isPaid()) {
            throw new WhatsAppException('Lampiran hanya bisa dikirim saat jendela chat terbuka. Tunggu klien membalas dulu.');
        }

        $name = trim(basename(str_replace('\\', '/', $name)));
        $caption = filled($caption) ? trim((string) $caption) : null;
        $disk = Storage::disk('local');

        if (! Library::accepts($name, $disk->exists($path) ? $disk->mimeType($path) : null)) {
            throw new WhatsAppException('Jenis file tidak didukung. Gunakan JPG, PNG, PDF, Word, Excel, PowerPoint, CSV, atau TXT.');
        }

        if ($disk->size($path) > Library::MAX_BYTES) {
            throw new WhatsAppException('File terlalu besar, maksimal 10 MB.');
        }

        if ($caption !== null && mb_strlen($caption) > 1024) {
            throw new WhatsAppException('Keterangan terlalu panjang, maksimal 1024 karakter.');
        }

        $bytes = (string) $disk->get($path);
        $type = Library::whatsAppType($name);
        $mime = Library::sendMime($name);

        // The chat keeps its own copy, so deleting a library item never breaks an old conversation.
        $kept = 'wa-media/'.$this->current->id().'/'.Str::uuid().'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $disk->put($kept, $bytes);

        $message = $this->deliver(
            $conversation, $actor, $decision, fn (WhatsAppProvider $p) => $p->sendMedia($conversation->phone, $type, $mime, $name, $bytes, $caption),
            $caption, $type, ['mime_type' => $mime, 'path' => $kept, 'filename' => $name, 'bytes' => strlen($bytes)],
        );

        if ($message->status === MessageStatus::Failed) {
            $disk->delete($kept);
            $message->forceFill(['media' => null])->save();
        }

        return $this->finish($conversation, $actor, $message);
    }

    private function assertWritable(): void
    {
        if ($this->current->get() && PlanLimits::for($this->current->get())->readOnly()) {
            throw new WhatsAppException('Langganan hanya-baca, pesan tidak bisa dikirim. Perpanjang paket di menu Langganan.');
        }
    }

    private function finish(WaConversation $conversation, User $actor, WaMessage $message): WaMessage
    {
        $conversation->forceFill(['last_message_at' => $message->sent_at, 'unread_count' => 0])->save();

        $conversation->lead->activities()->create([
            'user_id' => $actor->getKey(),
            'type' => 'whatsapp_out',
            'body' => $message->status === MessageStatus::Failed
                ? 'Chat WhatsApp gagal terkirim'
                : 'Chat WhatsApp terkirim lewat '.($message->channel?->isOfficial() ? 'nomor resmi' : 'gateway'),
            'meta' => ['message_id' => $message->getKey(), 'paid' => $message->is_paid],
        ]);

        return $message;
    }

    private function sendText(WaConversation $conversation, User $actor, RouteDecision $decision, ?string $text): WaMessage
    {
        $text = trim((string) $text);

        if ($text === '') {
            throw new WhatsAppException('Tulis pesan dulu.');
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new WhatsAppException('Pesan terlalu panjang, maksimal '.self::MAX_LENGTH.' karakter.');
        }

        return $this->deliver($conversation, $actor, $decision, fn (WhatsAppProvider $p) => $p->sendText($conversation->phone, $text), $text);
    }

    /**
     * Tries the numbers the route allows, one after the other, until one accepts the message.
     *
     * @param  callable(WhatsAppProvider): SendResult  $send
     * @param  array<string, mixed>|null  $media
     */
    private function deliver(WaConversation $conversation, User $actor, RouteDecision $decision, callable $send, ?string $body, string $type = 'text', ?array $media = null): WaMessage
    {
        $candidates = $decision->isGateway()
            ? ($decision->kind === RouteKind::FollowUpGateway ? $this->routes->gatewayFallbacks($decision->channel) : collect([$decision->channel]))
            : collect([$decision->channel]);

        $lastFailure = null;

        foreach ($candidates as $channel) {
            $provider = $this->providers->for($channel);

            // A number that has never written to this client first introduces the agency.
            $needsIntro = $decision->kind === RouteKind::FollowUpGateway
                && ! $conversation->messages()->where('channel_id', $channel->getKey())->exists();

            if ($needsIntro) {
                $intro = $this->renderer->render($channel->introText(), $conversation->lead, $actor, $this->current->get()->name)['text'];
                $result = $provider->sendText($conversation->phone, $intro);

                if (! $result->ok) {
                    $lastFailure = $this->failed($conversation, $actor, $channel, $intro, $result, intro: true);

                    continue;
                }

                $this->record($conversation, $actor, $channel, $intro, $result, intro: true);
            }

            $result = $send($provider);

            if (! $result->ok) {
                $lastFailure = $this->failed($conversation, $actor, $channel, $body, $result, type: $type, media: $media);

                continue;
            }

            $message = $this->record($conversation, $actor, $channel, $body, $result, type: $type, media: $media);
            $this->openFreeWindowIfEligible($conversation, $channel);

            return $message;
        }

        return $lastFailure;
    }

    private function sendPaidTemplate(WaConversation $conversation, User $actor, RouteDecision $decision, bool $confirmPaid, ?WaTemplate $template): WaMessage
    {
        if (! $confirmPaid) {
            throw new WhatsAppException('Pesan ini berbayar. Konfirmasi biaya Meta dulu sebelum mengirim.');
        }

        if ($template === null || $template->status !== 'approved') {
            throw new WhatsAppException('Pilih template yang sudah disetujui Meta.');
        }

        $channel = $decision->channel;
        $rendered = $this->renderer->render($template->body, $conversation->lead, $actor, $this->current->get()->name);

        $result = $this->providers->for($channel)->sendTemplate($conversation->phone, $template->name, $template->language, $rendered['parameters']);

        if (! $result->ok) {
            return $this->failed($conversation, $actor, $channel, $rendered['text'], $result, templateName: $template->name, paid: true);
        }

        return $this->record($conversation, $actor, $channel, $rendered['text'], $result, templateName: $template->name, paid: true);
    }

    private function record(WaConversation $conversation, User $actor, WaChannel $channel, ?string $body, SendResult $result, bool $intro = false, ?string $templateName = null, bool $paid = false, string $type = 'text', ?array $media = null): WaMessage
    {
        return $conversation->messages()->create([
            'channel_id' => $channel->getKey(),
            'user_id' => $actor->getKey(),
            'direction' => 'out',
            'type' => $templateName ? 'template' : $type,
            'body' => $body,
            'media' => $media,
            'template_name' => $templateName,
            'is_intro' => $intro,
            'is_paid' => $paid,
            'status' => MessageStatus::Sent,
            'external_id' => $result->externalId,
            'sent_at' => now(),
        ])->load('channel');
    }

    private function failed(WaConversation $conversation, User $actor, WaChannel $channel, ?string $body, SendResult $result, bool $intro = false, ?string $templateName = null, bool $paid = false, string $type = 'text', ?array $media = null): WaMessage
    {
        if ($result->channelProblem) {
            $channel->markFailed((string) $result->error);
        }

        return $conversation->messages()->create([
            'channel_id' => $channel->getKey(),
            'user_id' => $actor->getKey(),
            'direction' => 'out',
            'type' => $templateName ? 'template' : $type,
            'body' => $body,
            'media' => $media,
            'template_name' => $templateName,
            'is_intro' => $intro,
            'is_paid' => $paid,
            'status' => MessageStatus::Failed,
            'error' => $result->error,
            'sent_at' => now(),
        ])->load('channel');
    }

    /**
     * Replying to a Click-to-WhatsApp ad within 24 hours opens 72 hours of free
     * messages on the official number.
     */
    private function openFreeWindowIfEligible(WaConversation $conversation, WaChannel $channel): void
    {
        if (! $channel->isOfficial() || $conversation->ad_entry_at === null || $conversation->freeWindowOpen()) {
            return;
        }

        if ($conversation->free_window_ends_at !== null) {
            return; // This ad's window was already used; a new ad click resets it.
        }

        if ($conversation->ad_entry_at->copy()->addHours(config('whatsapp.free_entry_reply_deadline_hours'))->isFuture()) {
            $conversation->forceFill(['free_window_ends_at' => now()->addHours(config('whatsapp.free_entry_window_hours'))])->save();
        }
    }
}
