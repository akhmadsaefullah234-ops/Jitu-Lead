<?php

namespace App\Filament\Pages;

use App\Actions\SendWhatsAppMessage;
use App\Enums\MessageStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\WaConversation;
use App\Models\WaTemplate;
use App\WhatsApp\RouteDecision;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\WhatsAppException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Chat with leads over WhatsApp (PRD F9). Agents see only the chats of leads
 * they own, the same rule as the lead list.
 */
class Inbox extends Page
{
    protected string $view = 'filament.pages.inbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Inbox';

    protected static ?string $title = 'Inbox WhatsApp';

    protected static ?int $navigationSort = 3;

    #[Url(as: 'lead')]
    public ?int $leadId = null;

    public string $draft = '';

    public function close(): void
    {
        $this->leadId = null;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = (int) static::visibleConversations()->sum('unread_count');

        return $count > 0 ? (string) $count : null;
    }

    private static function visibleConversations()
    {
        return WaConversation::query()->whereIn('lead_id', LeadResource::getEloquentQuery()->select('leads.id'));
    }

    /** The conversation being shown. Opening a lead that has no chat yet starts one. */
    private function current(): ?WaConversation
    {
        if ($this->leadId === null) {
            return null;
        }

        $lead = LeadResource::getEloquentQuery()->whereKey($this->leadId)->first();

        if ($lead === null || blank($lead->phone)) {
            return null;
        }

        return WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]);
    }

    public function open(int $leadId): void
    {
        $this->leadId = $leadId;
        $this->draft = '';
    }

    public function getViewData(): array
    {
        $conversation = $this->current();

        if ($conversation && $conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        $decision = $conversation ? app(RouteSelector::class)->decide($conversation) : null;

        return [
            'conversations' => static::visibleConversations()->with('lead:id,name,phone')->orderByDesc('last_message_at')->limit(100)->get(),
            'conversation' => $conversation?->load(['lead.stage', 'lead.owner', 'messages.channel']),
            'decision' => $decision,
            'leadUrl' => fn (Lead $lead) => LeadResource::getUrl('edit', ['record' => $lead]),
        ];
    }

    public function send(): void
    {
        $conversation = $this->current();

        if ($conversation === null) {
            return;
        }

        try {
            $message = app(SendWhatsAppMessage::class)($conversation, auth()->user(), $this->draft);
        } catch (WhatsAppException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        if ($message->status === MessageStatus::Failed) {
            Notification::make()->title('Pesan gagal terkirim')->body($message->error)->danger()->send();

            return;
        }

        $this->draft = '';
    }

    /** Only reachable when the free windows are closed and no gateway is connected. */
    public function sendPaidTemplateAction(): Action
    {
        return Action::make('sendPaidTemplate')
            ->label('Kirim template berbayar')
            ->color('warning')
            ->modalHeading('Kirim template berbayar')
            ->modalDescription('Jendela gratis sudah tutup dan tidak ada nomor gateway. Meta menagih biaya per template marketing yang dikirim.')
            ->modalSubmitActionLabel('Ya, kirim dan terima biayanya')
            ->schema([
                Select::make('template_id')->label('Template yang disetujui Meta')->required()
                    ->options(fn () => WaTemplate::query()->where('status', 'approved')->pluck('name', 'id')),
            ])
            ->visible(fn () => ($d = $this->decision()) && $d->isPaid())
            ->action(function (array $data) {
                $conversation = $this->current();
                $template = WaTemplate::query()->find($data['template_id']);

                try {
                    app(SendWhatsAppMessage::class)($conversation, auth()->user(), null, true, $template);
                } catch (WhatsAppException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    private function decision(): ?RouteDecision
    {
        $conversation = $this->current();

        return $conversation ? app(RouteSelector::class)->decide($conversation) : null;
    }
}
