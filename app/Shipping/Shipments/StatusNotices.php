<?php

namespace App\Shipping\Shipments;

use App\Actions\SendWhatsAppMessage;
use App\Automation\StageAutomation;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Mail\ShipmentNeedsAction;
use App\Mail\ShipmentWaitsForBalance;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\ShipmentNotice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\ShipmentStatus;
use App\Support\CurrentTenant;
use App\Welcome\WelcomeText;
use App\WhatsApp\RouteSelector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * What a parcel status does (Pengaturan > Pengiriman > "Pesan status kiriman"):
 *
 * - picked_up, out_for_delivery (a COD text with the amount to prepare), delivery_failed and delivered send the
 *   buyer a WhatsApp when switched on; in_transit is off by default; awb_created is the existing tracking flow.
 * - Once per status per parcel (shipment_notices is unique on both). Between 21:00 and 05:00 (the agency's time)
 *   a message waits for 05:00, and an "out for delivery" whose day has passed is dropped.
 * - It goes on the lead's usual route (App\WhatsApp\RouteSelector), marked origin "shipping" ("· Status kiriman"
 *   in the Inbox), without a number introduction. A closed free window only leaves a note.
 * - delivery_failed also tells the lead's owner (bell and email) and puts "Perlu tindakan" on the chat;
 *   returning and returned never reach the buyer: a note and the owner is told. delivered moves the lead to
 *   Selesai through StageAutomation; returned does not cancel it, a person decides.
 */
class StatusNotices
{
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 5;

    public const VARIABLES = ['nama', 'produk', 'resi', 'kurir', 'link_lacak', 'alasan', 'cod_total', 'agensi'];

    /** Statuses a buyer may hear about, with their defaults. */
    public const DEFAULTS = [
        ShipmentStatus::PICKED_UP => ['enabled' => true, 'text' => 'Halo kak {nama} 😊 Paket {produk} kakak sudah diambil kurir {kurir} dan dalam perjalanan. Resi: {resi}. Lacak: {link_lacak}'],
        ShipmentStatus::IN_TRANSIT => ['enabled' => false, 'text' => 'Kak {nama}, paket {produk} kakak sedang dalam perjalanan bersama {kurir}. Resi: {resi}. Lacak: {link_lacak}'],
        ShipmentStatus::OUT_FOR_DELIVERY => ['enabled' => true,
            'text' => 'Kak {nama}, paket {produk} sedang diantar kurir hari ini 🛵 Pastikan ada yang menerima ya kak 🙏',
            'text_cod' => 'Kak {nama}, paket {produk} sedang diantar kurir hari ini 🛵 Mohon siapkan uang *{cod_total}* ya kak, dan pastikan ada yang menerima 🙏'],
        ShipmentStatus::DELIVERY_FAILED => ['enabled' => true, 'text' => 'Kak {nama}, kurir belum berhasil mengantar paket kakak ({alasan}). Boleh dibantu konfirmasi alamat lengkap atau nomor yang bisa dihubungi kurir ya kak 🙏'],
        ShipmentStatus::DELIVERED => ['enabled' => true, 'text' => 'Paket sudah diterima ya kak {nama} 😊 Terima kasih sudah belanja di {agensi}.'],
    ];

    public function __construct(private CurrentTenant $current, private RouteSelector $routes, private SendWhatsAppMessage $send) {}

    /** @return array<string, array{enabled: bool, text: string, text_cod?: string}> the agency's texts over the defaults */
    public static function settings(?CarrierAccount $account): array
    {
        $stored = (array) ($account?->messages ?? []);
        $out = [];

        foreach (self::DEFAULTS as $status => $default) {
            $mine = (array) ($stored[$status] ?? []);
            $out[$status] = [
                'enabled' => (bool) ($mine['enabled'] ?? $default['enabled']),
                'text' => filled($mine['text'] ?? null) ? (string) $mine['text'] : $default['text'],
            ] + (isset($default['text_cod']) ? ['text_cod' => filled($mine['text_cod'] ?? null) ? (string) $mine['text_cod'] : $default['text_cod']] : []);
        }

        return $out;
    }

    /** @return list<string> variables in $text this message does not know, as written ("kupon") */
    public static function unknown(string $text): array
    {
        preg_match_all('/\{\s*([a-z0-9_:]+)\s*\}/i', $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($v) => ! in_array(strtolower($v), self::VARIABLES, true))));
    }

    /** A parcel moved to $status at $at: book the buyer's message, tell staff, move the stage. */
    public function statusChanged(Shipment $shipment, string $status, Carbon $at): void
    {
        $lead = $shipment->lead;
        $label = ShipmentStatus::label($status);
        $lead->activities()->create(['type' => 'note', 'body' => 'Status kiriman '.($shipment->awb ?? $shipment->client_order_no).': '.$label.($shipment->status_reason ? ' ('.$shipment->status_reason.')' : '').'.']);

        if (in_array($status, [ShipmentStatus::RETURNING, ShipmentStatus::RETURNED], true)) {
            $lead->activities()->create(['type' => 'note', 'body' => $status === ShipmentStatus::RETURNED
                ? 'Paket sudah kembali ke toko. Hubungi pembeli lalu putuskan: kirim ulang atau batalkan pesanan. Lead tidak dipindah otomatis.'
                : 'Paket sedang dikembalikan ke toko. Pembeli tidak diberi tahu otomatis.']);
            $this->alertStaff($shipment, $label, 'Pesan ke pembeli tidak dikirim otomatis. Periksa dan putuskan langkah berikutnya.');

            return;
        }

        if ($status === ShipmentStatus::DELIVERY_FAILED) {
            $this->alertStaff($shipment, 'Paket gagal diantar, perlu tindakan', $shipment->status_reason ?: 'Kurir belum berhasil mengantar paket.', mail: true);
        }

        if ($status === ShipmentStatus::DELIVERED) {
            app(StageAutomation::class)->parcelDelivered($lead);
        }

        $setting = self::settings(Carriers::account($this->current->get()))[$status] ?? null;

        if ($setting === null || ! $setting['enabled']) {
            return;
        }

        try {
            $notice = ShipmentNotice::query()->create([
                'shipment_id' => $shipment->getKey(), 'status' => $status, 'state' => ShipmentNotice::PENDING, 'event_at' => $at->copy()->setTimezone(config('app.timezone')), 'due_at' => $this->dueAt(now()),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($notice->due_at->lte(now())) {
            $this->deliver($notice);
        }
    }

    /** Now, or 05:00 when it is the quiet hours in the agency's time zone. */
    public function dueAt(Carbon $at): Carbon
    {
        $local = $at->copy()->timezone($this->timezone());

        if ($local->hour >= self::QUIET_FROM) {
            return $local->copy()->addDay()->setTime(self::QUIET_UNTIL, 0)->setTimezone(config('app.timezone'));
        }

        if ($local->hour < self::QUIET_UNTIL) {
            return $local->copy()->setTime(self::QUIET_UNTIL, 0)->setTimezone(config('app.timezone'));
        }

        return $at->copy();
    }

    /** Sends one due message, or skips it with a note. Runs inside the tenant. */
    public function deliver(ShipmentNotice $notice): void
    {
        $claimed = ShipmentNotice::query()->whereKey($notice->getKey())->where('state', ShipmentNotice::PENDING)->update(['state' => 'sending', 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $notice->refresh();
        $shipment = $notice->shipment()->with('lead')->first();
        $lead = $shipment?->lead;
        $skip = fn (string $why) => $notice->forceFill(['state' => ShipmentNotice::SKIPPED, 'note' => $why])->save();

        if ($shipment === null || $lead === null || $shipment->state === Shipment::CANCELLED) {
            $skip('Kiriman dibatalkan');

            return;
        }

        $tz = $this->timezone();

        if ($notice->status === ShipmentStatus::OUT_FOR_DELIVERY && ! $notice->event_at->copy()->timezone($tz)->isSameDay(now()->timezone($tz))) {
            $skip('Hari pengantaran sudah lewat');

            return;
        }

        // A later status already told the buyer more than this one would.
        $later = ShipmentNotice::query()->where('shipment_id', $shipment->getKey())->where('id', '!=', $notice->getKey())->where('state', ShipmentNotice::SENT)->pluck('status')
            ->contains(fn ($s) => ShipmentStatus::rank($s) > ShipmentStatus::rank($notice->status));

        if ($later || ShipmentStatus::rank($shipment->status) > ShipmentStatus::rank($notice->status) && $shipment->status === ShipmentStatus::DELIVERED && $notice->status !== ShipmentStatus::DELIVERED) {
            $skip('Status kiriman sudah berlanjut');

            return;
        }

        $setting = self::settings(Carriers::account($this->current->get()))[$notice->status] ?? null;

        if ($setting === null || ! $setting['enabled']) {
            $skip('Pesan status ini dimatikan');

            return;
        }

        $text = $this->render($shipment->is_cod && isset($setting['text_cod']) ? $setting['text_cod'] : $setting['text'], $shipment, $lead);
        $conversation = filled($lead->phone) ? WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]) : null;
        $decision = $conversation ? $this->routes->decide($conversation) : null;
        $actor = $this->actor($lead);

        if ($actor === null || $decision === null || ! $decision->canSend() || $decision->isPaid()) {
            $why = $decision?->isPaid() ? 'jendela chat gratis sudah tutup' : ($decision?->note ?: 'lead belum punya nomor WhatsApp');
            $skip($why);
            $lead->activities()->create(['type' => 'note', 'body' => 'Pesan status kiriman "'.ShipmentStatus::label($notice->status).'" tidak terkirim: '.$why.'.']);

            return;
        }

        try {
            $message = $this->send->from('shipping')($conversation, $actor, $text);
        } catch (Throwable $e) {
            $notice->forceFill(['state' => ShipmentNotice::FAILED, 'note' => mb_substr($e->getMessage(), 0, 250)])->save();

            return;
        }

        $notice->forceFill([
            'state' => $message->status === MessageStatus::Failed ? ShipmentNotice::FAILED : ShipmentNotice::SENT,
            'wa_message_id' => $message->getKey(), 'note' => $message->status === MessageStatus::Failed ? mb_substr((string) $message->error, 0, 250) : null,
        ])->save();
    }

    public function render(string $text, Shipment $shipment, Lead $lead): string
    {
        $items = app(Shipments::class)->items($lead);
        $tenant = $this->current->get();

        return WelcomeText::fill($text, [
            'nama' => explode(' ', trim((string) $lead->name))[0] ?: (string) $lead->name,
            'produk' => implode(', ', array_map(fn ($i) => $i['name'], $items)) ?: ($lead->property?->name ?? ''),
            'resi' => (string) $shipment->awb,
            'kurir' => (string) $shipment->courier,
            'link_lacak' => filled($shipment->awb) ? StageAutomation::trackingUrl($shipment->courier, $shipment->awb) : '',
            'alasan' => (string) ($shipment->status_reason ?: 'alamat atau penerima tidak ditemukan'),
            'cod_total' => $shipment->is_cod && $shipment->cod_amount ? 'Rp'.number_format((int) $shipment->cod_amount, 0, ',', '.') : '',
            'agensi' => (string) $tenant?->name,
        ]);
    }

    /** Bell notification (and optionally email) to the lead's owner, else the admins. */
    /** A notice about a lead without a parcel yet (automatic resi): its owner, else the admins, in the bell. */
    public function alertLead(Lead $lead, string $title, string $body): void
    {
        $tenant = $this->current->get();
        $people = $this->staff($tenant, $lead);

        try {
            Notification::make()->title($title.': '.$lead->name)->body($body)->warning()
                ->actions([Action::make('open')->label('Buka lead')->url(LeadResource::getUrl('edit', ['record' => $lead], panel: 'app', tenant: $tenant))])
                ->sendToDatabase($people);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function alertStaff(Shipment $shipment, string $title, string $body, bool $mail = false): void
    {
        $tenant = $this->current->get();
        $lead = $shipment->lead;
        $people = $this->staff($tenant, $lead);

        if ($people->isEmpty()) {
            return;
        }

        try {
            Notification::make()->title($title.': '.$lead->name)->body($body.' Resi: '.($shipment->awb ?: '-'))->warning()
                ->actions([Action::make('open')->label('Buka chat')->url(Inbox::getUrl(['lead' => $lead->getKey()], panel: 'app', tenant: $tenant))])
                ->sendToDatabase($people);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $mail) {
            return;
        }

        foreach ($people as $person) {
            try {
                Mail::to($person->email)->send(new ShipmentNeedsAction($tenant, $lead, $shipment, $title, $body));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** The agency's admins (whoever owns the lead), in the bell and by email: for the balance, which only an admin can fix. */
    public function alertAdmins(Shipment $shipment, string $title, string $body, bool $mail = false): void
    {
        $tenant = $this->current->get();
        $lead = $shipment->lead;
        $people = $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->get();

        if ($people->isEmpty()) {
            return;
        }

        try {
            Notification::make()->title($title.': '.$lead->name)->body($body)->warning()
                ->actions([
                    Action::make('open')->label('Buka Pengiriman')->url(ShipmentResource::getUrl('index', ['tab' => 'balance'], panel: 'app', tenant: $tenant)),
                    Action::make('lead')->label('Buka lead')->url(LeadResource::getUrl('edit', ['record' => $lead], panel: 'app', tenant: $tenant)),
                ])
                ->sendToDatabase($people);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $mail) {
            return;
        }

        foreach ($people as $person) {
            try {
                Mail::to($person->email)->send(new ShipmentWaitsForBalance($tenant, $lead, $shipment, $title, $body));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function staff(Tenant $tenant, Lead $lead)
    {
        $owner = $lead->owner_id ? $tenant->activeUsers()->where('users.id', $lead->owner_id)->get() : collect();

        return $owner->isNotEmpty() ? $owner : $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->get();
    }

    private function actor(Lead $lead): ?User
    {
        return $lead->owner ?? $this->current->get()?->activeUsers()->wherePivot('role', Role::Admin->value)->first();
    }

    private function timezone(): string
    {
        return $this->current->get()?->timezone ?: 'Asia/Jakarta';
    }
}
