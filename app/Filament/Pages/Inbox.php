<?php

namespace App\Filament\Pages;

use App\Actions\GenerateAiReply;
use App\Actions\LearnFromReply;
use App\Actions\SendLibraryItem;
use App\Actions\SendWhatsAppMessage;
use App\Automation\StageAutomation;
use App\Automation\StageAutomationSettings;
use App\Catalog\ProductMatcher;
use App\Enums\MessageStatus;
use App\Filament\Concerns\NotifiesShipmentFormErrors;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Support\ShipmentActions;
use App\Media\Library;
use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\WaConversation;
use App\Models\WaTemplate;
use App\Regions\RegionDirectory;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Regions;
use App\Shipping\Shipping;
use App\Shipping\ShippingQuote;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\WhatsApp\RouteDecision;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\WhatsAppException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

/**
 * Chat with leads over WhatsApp (PRD F9). Agents see only the chats of leads
 * they own, the same rule as the lead list.
 */
class Inbox extends Page
{
    use NotifiesShipmentFormErrors;

    protected string $view = 'filament.pages.inbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Inbox';

    protected static ?string $title = 'Inbox WhatsApp';

    protected static ?int $navigationSort = 3;

    #[Url(as: 'lead')]
    public ?int $leadId = null;

    public string $draft = '';

    /** The AI suggestion the agent loaded into the box, marked as used once sent. */
    public ?int $usedAiDraft = null;

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
        $this->usedAiDraft = null;
    }

    public function getViewData(): array
    {
        $conversation = $this->current();

        if ($conversation && $conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        $decision = $conversation ? app(RouteSelector::class)->decide($conversation) : null;

        $conversations = static::visibleConversations()->with(['lead:id,name,phone,owner_id,property_id', 'lead.owner:id,name', 'lead.property:id,name', 'latestMessage'])->orderByDesc('last_message_at')->limit(100)->get();

        return [
            'seesAll' => app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false,
            'conversations' => $conversations,
            // A parcel the courier could not deliver: a red "Perlu tindakan" on the chat.
            'needsAction' => ShipmentActions::available()
                ? Shipment::query()->whereIn('lead_id', $conversations->pluck('lead_id')->push($conversation?->lead_id)->filter())
                    ->where('status', ShipmentStatus::DELIVERY_FAILED)->whereNull('final_at')->pluck('lead_id')->flip()->all()
                : [],
            // A label still to print (Pengiriman menu): an amber "Belum dicetak" on the chat.
            'unprinted' => ShipmentActions::available()
                ? Shipment::query()->tab('unprinted')->whereIn('lead_id', $conversations->pluck('lead_id')->push($conversation?->lead_id)->filter())->pluck('lead_id')->flip()->all()
                : [],
            'conversation' => $conversation?->load(['lead.stage', 'lead.owner', 'lead.property', 'messages.channel']),
            'decision' => $decision,
            'aiMode' => $conversation?->lastInboundChannel?->ai_mode,
            'aiPausedUntil' => $conversation?->aiPaused() ? $conversation->ai_paused_until : null,
            'aiDraft' => $conversation ? AiDraft::query()->where('conversation_id', $conversation->getKey())->whereIn('status', [AiDraft::PENDING, AiDraft::HANDOFF])->latest('id')->first() : null,
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

        $conversation->pauseAiFor(config('whatsapp.ai_after_send_pause_seconds'));
        $this->markAiDraft($conversation, AiDraft::SENT);
        $this->applySuggestedOrder($conversation);
        app(LearnFromReply::class)($conversation, $message);
        $this->sendSuggestedAttachments($conversation);
        $this->draft = '';
        $this->usedAiDraft = null;
    }

    /** When the agent sent an AI suggestion that confirms an order, the order is written onto the lead now. */
    private function applySuggestedOrder(WaConversation $conversation): void
    {
        $draft = $this->usedAiDraft ? AiDraft::query()->where('conversation_id', $conversation->getKey())->find($this->usedAiDraft) : null;

        if (! $draft?->actions) {
            return;
        }

        $actions = app(GenerateAiReply::class)->applyActions($conversation->lead, $draft->actions, auth()->user(), auth()->user()->name.' (saran AI)');
        $draft->forceFill(['actions' => $actions])->save();

        foreach ($actions as $action) {
            $outcome = $action['outcome'] ?? [];
            if (($action['type'] ?? null) === 'product') {
                Notification::make()->title('Produk lead diperbarui')->body($outcome['pesan'] ?? null)->success()->send();

                continue;
            }
            ($outcome['status'] ?? null) === 'tercatat'
                ? Notification::make()->title('Pesanan dicatat di lead')->body($outcome['pesan'] ?? null)->success()->send()
                : Notification::make()->title('Pesanan belum tercatat')->body($outcome['pesan'] ?? null)->warning()->send();
        }
    }

    /** When the agent used an AI suggestion, the library items the AI picked go out right after the text. */
    private function sendSuggestedAttachments(WaConversation $conversation): void
    {
        $draft = $this->usedAiDraft ? AiDraft::query()->where('conversation_id', $conversation->getKey())->find($this->usedAiDraft) : null;

        foreach ($draft?->suggestedAssets() ?? [] as $asset) {
            try {
                $sent = app(SendLibraryItem::class)($conversation->refresh(), auth()->user(), $asset);

                if ($sent->status === MessageStatus::Failed) {
                    Notification::make()->title('Lampiran "'.$asset->title.'" gagal terkirim')->body($sent->error)->danger()->send();
                }
            } catch (WhatsAppException $e) {
                Notification::make()->title('Lampiran "'.$asset->title.'" tidak terkirim')->body($e->getMessage())->danger()->send();
            }
        }
    }

    /** Typing in the box means a person is on this chat: the AI waits, and returns on its own once they stop. */
    public function updatedDraft(): void
    {
        if (trim($this->draft) !== '') {
            $this->current()?->pauseAiFor(config('whatsapp.ai_typing_pause_seconds'));
        }
    }

    public function useAiDraft(int $id): void
    {
        $draft = AiDraft::query()->where('conversation_id', $this->current()?->getKey())->whereKey($id)->first();

        if ($draft?->body !== null) {
            $this->draft = $draft->body;
            $this->usedAiDraft = $draft->getKey();
        }
    }

    public function discardAiDraft(int $id): void
    {
        AiDraft::query()->where('conversation_id', $this->current()?->getKey())->whereKey($id)->update(['status' => AiDraft::DISCARDED]);
    }

    /** Once an agent has answered, any waiting AI suggestion for the chat is settled. */
    private function markAiDraft(WaConversation $conversation, string $status): void
    {
        AiDraft::query()->where('conversation_id', $conversation->getKey())
            ->whereIn('status', [AiDraft::PENDING, AiDraft::HANDOFF])
            ->update(['status' => $status]);
    }

    /** A picture or file from the media library or straight from the device, or a saved link. */
    public function sendAttachmentAction(): Action
    {
        return Action::make('sendAttachment')
            ->label('Lampiran')->icon(Heroicon::OutlinedPaperClip)->color('gray')
            ->iconButton()->size(Size::Large)->tooltip('Lampirkan dari pustaka media')
            ->modalHeading('Kirim lampiran')
            ->modalDescription('Pilih dari pustaka media (price list, foto rumah, denah, link Maps), atau unggah file baru.')
            ->modalSubmitActionLabel('Kirim')
            ->schema([
                Select::make('asset_id')->label('Dari pustaka media')->searchable()->live()
                    ->options(fn () => MediaFolder::groupedOptions())
                    ->helperText('Kosongkan bila ingin mengunggah file sendiri.'),
                FileUpload::make('upload')->label('Atau unggah file')->disk('local')->directory('wa-outbox')->visibility('private')
                    ->storeFileNamesIn('upload_name')->maxSize((int) (Library::MAX_BYTES / 1024))
                    ->rules([fn (): \Closure => MediaAssetResource::fileRule()])->downloadable(false)->openable(false)
                    ->visible(fn ($get) => blank($get('asset_id')))->required(fn ($get) => blank($get('asset_id')))
                    ->helperText('JPG, PNG, PDF, Word, Excel, PowerPoint, CSV, atau TXT. Maksimal 10 MB.'),
                Textarea::make('caption')->label('Keterangan (opsional)')->rows(2)->maxLength(1024),
            ])
            ->visible(fn () => ($d = $this->decision()) && $d->canSend() && ! $d->isPaid())
            ->action(function (array $data) {
                $conversation = $this->current();
                $asset = filled($data['asset_id'] ?? null) ? MediaAsset::query()->where('active', true)->find($data['asset_id']) : null;
                $upload = $data['upload'] ?? null;

                try {
                    $send = app(SendWhatsAppMessage::class);
                    $caption = filled($data['caption'] ?? null) ? $data['caption'] : null;

                    $message = match (true) {
                        $conversation === null => throw new WhatsAppException('Pilih percakapan dulu.'),
                        $asset !== null => app(SendLibraryItem::class)($conversation, auth()->user(), $asset, $caption),
                        is_string($upload) => $send->sendFile($conversation, auth()->user(), $upload, (string) ($data['upload_name'] ?? basename($upload)), $caption),
                        default => throw new WhatsAppException('Pilih item dari pustaka atau unggah file.'),
                    };
                } catch (WhatsAppException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                } finally {
                    if (is_string($upload) && $upload !== '') {
                        Storage::disk('local')->delete($upload);
                    }
                }

                if ($message->status === MessageStatus::Failed) {
                    Notification::make()->title('Lampiran gagal terkirim')->body($message->error)->danger()->send();

                    return;
                }

                $conversation->pauseAiFor(config('whatsapp.ai_after_send_pause_seconds'));
                $this->markAiDraft($conversation, AiDraft::SENT);
            });
    }

    /**
     * Prices the chat's order from the shop's zone table (the same one the AI uses) and puts the result in the
     * message box for the agent to check and send.
     */
    public function shippingQuoteAction(): Action
    {
        return Action::make('shippingQuote')
            ->label('Hitung ongkir')->icon(Heroicon::OutlinedTruck)->color('gray')->size('sm')
            ->modalHeading('Hitung ongkir')
            ->modalDescription('Dari tabel zona di Pengaturan > Pengiriman. Hasilnya masuk ke kotak pesan untuk Anda periksa sebelum dikirim.')
            ->modalSubmitActionLabel('Hitung')
            ->visible(fn () => $this->current() !== null && Shipping::available(app(CurrentTenant::class)->get()))
            ->fillForm(function () {
                $lead = $this->current()?->lead;

                return [
                    'items' => $lead?->order_items
                        ? array_map(fn (array $i) => ['product_id' => $i['product_id'] ?? null, 'varian' => $i['variant'] ?? null, 'jumlah' => $i['quantity'] ?? 1], $lead->order_items)
                        : [['product_id' => $lead?->property_id, 'varian' => $lead?->variant, 'jumlah' => $lead?->quantity ?: 1]],
                ];
            })
            ->schema([
                Repeater::make('items')->label('Produk')->addActionLabel('Tambah produk')->minItems(1)->defaultItems(1)->columns(3)->schema([
                    Select::make('product_id')->label('Produk')->required()->searchable()->columnSpan(3)
                        ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('varian')->label('Varian')->maxLength(60)->columnSpan(2),
                    TextInput::make('jumlah')->label('Jumlah')->numeric()->integer()->minValue(1)->maxValue(1000)->default(1)->required(),
                ]),
                TextInput::make('kota')->label('Kota/kabupaten tujuan')->required()->maxLength(80)->placeholder('Mis. Kab. Bandung')
                    ->datalist(fn () => RegionDirectory::cityNames()),
                Select::make('provinsi')->label('Provinsi')->options(Regions::options())->searchable()
                    ->helperText('Boleh dikosongkan untuk kota yang dikenal.'),
            ])
            ->action(function (array $data, Action $action) {
                $quote = Shipping::quote(app(CurrentTenant::class)->get(), $data['kota'] ?? null, $data['provinsi'] ?? null, $data['items'] ?? []);

                if (! $quote->ok()) {
                    Notification::make()->title($quote->status === ShippingQuote::NEEDS_ADMIN ? 'Perlu konfirmasi admin' : 'Ongkir belum bisa dihitung')
                        ->body($quote->message)->warning()->send();
                    $action->halt();
                }

                $this->draft = trim($this->draft) === '' ? $quote->summary() : rtrim($this->draft)."\n\n".$quote->summary();
                $this->updatedDraft();
                Notification::make()->title('Ongkir dihitung, zona '.$quote->zone)->body(nl2br(e($quote->summary())))->success()->persistent()->send();
            });
    }

    /**
     * The product the chat is about, shown as a badge the agent can change. The AI reads it on every reply, so a
     * change here steers the next answer; an agent's pick is not overwritten by a later ad click.
     */
    public function productAction(): Action
    {
        return Action::make('product')
            ->label(fn () => $this->current()?->lead?->property?->name ?? 'Pilih '.Industry::t('catalog_item'))
            ->icon(Heroicon::OutlinedTag)->color(fn () => $this->current()?->lead?->property_id ? 'primary' : 'gray')->size('sm')->badge()
            ->tooltip(fn () => ($lead = $this->current()?->lead)?->product_source ? 'Ditentukan dari '.(ProductMatcher::SOURCES[$lead->product_source] ?? $lead->product_source) : 'Ganti '.Industry::t('catalog_item').' yang ditanyakan')
            ->visible(fn () => $this->current() !== null && Property::query()->exists())
            ->modalHeading(fn () => ucfirst(Industry::t('catalog_item')).' yang ditanyakan')
            ->modalDescription('AI menjawab berdasarkan pilihan ini, termasuk harga, promo, dan pengetahuan khusus '.Industry::t('catalog_item').' tersebut.')
            ->modalSubmitActionLabel('Simpan')
            ->fillForm(fn () => ['property_id' => $this->current()?->lead?->property_id])
            ->schema([
                Select::make('property_id')->label(ucfirst(Industry::t('catalog_item')))->searchable()->placeholder('Belum diketahui')
                    ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->action(function (array $data) {
                $lead = $this->current()?->lead;
                $product = filled($data['property_id'] ?? null) ? Property::query()->find($data['property_id']) : null;

                if ($lead === null || $lead->property_id === $product?->getKey()) {
                    return;
                }

                ProductMatcher::set($lead, $product, 'agent', auth()->user()->name);
                Notification::make()->title($product ? 'Produk diganti ke '.$product->name : 'Produk dikosongkan')->success()->send();
            });
    }

    /** A shop agent checked the transfer (Pengaturan > Otomatisasi tahap, rule 3). */
    public function paymentReceivedAction(): Action
    {
        return Action::make('paymentReceived')
            ->label('Pembayaran diterima')->icon(Heroicon::OutlinedBanknotes)->color('success')->size('sm')
            ->visible(fn () => app(StageAutomation::class)->canConfirmPayment($this->current()?->lead))
            ->requiresConfirmation()
            ->modalHeading('Pembayaran sudah masuk?')
            ->modalDescription('Pastikan dana sudah ada di rekening. Lead pindah ke tahap Dikemas & dikirim.')
            ->action(function () {
                app(StageAutomation::class)->paymentReceived($this->current()->lead, auth()->user());
                Notification::make()->title('Pembayaran dicatat diterima')->success()->send();
            });
    }

    /** Courier and tracking number: kept on the lead, sent to the buyer, lead to "Dikemas & dikirim" (rule 4). */
    public function trackingAction(): Action
    {
        return Action::make('tracking')
            ->label('Nomor resi')->icon(Heroicon::OutlinedQrCode)->color('gray')->size('sm')
            ->visible(fn () => $this->current() !== null && app(StageAutomation::class)->applies())
            ->modalHeading('Nomor resi')
            ->modalDescription(fn () => StageAutomationSettings::of(app(CurrentTenant::class)->get())->enabled('tracking')
                ? 'Kurir, nomor resi, dan tautan lacak dikirim ke pembeli lewat WhatsApp, lalu lead pindah ke Dikemas & dikirim.'
                : 'Nomor resi disimpan di lead. Pengiriman otomatis ke pembeli sedang dimatikan di Pengaturan > Otomatisasi tahap.')
            ->modalSubmitActionLabel('Simpan')
            ->fillForm(fn () => ['courier' => $this->current()?->lead?->courier, 'tracking_number' => $this->current()?->lead?->tracking_number])
            ->schema([
                TextInput::make('courier')->label('Kurir')->required()->maxLength(60)->placeholder('JNE REG'),
                TextInput::make('tracking_number')->label('Nomor resi')->required()->maxLength(60)->regex('/^[A-Za-z0-9\-\s]{4,60}$/'),
            ])
            ->action(function (array $data) {
                $result = app(StageAutomation::class)->shipped($this->current()->lead, $data['courier'], $data['tracking_number'], auth()->user());
                Notification::make()->title($result)->{str_contains($result, 'belum') || str_contains($result, 'gagal') ? 'warning' : 'success'}()->send();
            });
    }

    public function createShipmentAction(): Action
    {
        return ShipmentActions::create(fn () => $this->current()?->lead)->size('sm');
    }

    public function linkShipmentAction(): Action
    {
        return ShipmentActions::link(fn () => $this->current()?->lead)->size('sm');
    }

    public function retryShipmentAction(): Action
    {
        return ShipmentActions::retry(fn () => $this->current()?->lead)->size('sm');
    }

    public function trackShipmentAction(): Action
    {
        return ShipmentActions::track(fn () => $this->current()?->lead)->size('sm');
    }

    public function shipmentLabelAction(): Action
    {
        return ShipmentActions::label(fn () => $this->current()?->lead)->size('sm');
    }

    public function cancelShipmentAction(): Action
    {
        return ShipmentActions::cancel(fn () => $this->current()?->lead)->size('sm');
    }

    public function cancelAutoShipmentAction(): Action
    {
        return ShipmentActions::cancelAuto(fn () => $this->current()?->lead)->size('xs')->link();
    }

    /** Only reachable when the free windows are closed and no gateway is connected. */
    /** In the chat header's ⋯ menu, with payment, tracking number and shipping cost. */
    public function openLeadAction(): Action
    {
        return Action::make('openLead')
            ->label('Buka lead')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn () => ($lead = $this->current()?->lead) ? LeadResource::getUrl('edit', ['record' => $lead]) : null)
            ->visible(fn () => $this->current()?->lead !== null);
    }

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
