<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Automation\StageAutomation;
use App\Console\Commands\AuditDoneParcelsCommand;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\StageAutomationPage;
use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\ParcelFollowUp;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\ParcelFollowUps;
use App\Shipping\Shipments\ParcelFollowUpSettings;
use App\Shipping\Shipments\StatusNotices;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Follow-up harian paket gagal antar (App\Shipping\Shipments\ParcelFollowUps) and the "Selesai" rule that skips undelivered parcels. */
class ParcelFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 07:00 in Jakarta (UTC+7).
        $this->travelTo(Carbon::parse('2026-11-25 07:00', 'Asia/Jakarta'));
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->shop->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->inShop(fn () => Property::create(['name' => 'Sepatu Lari', 'price_from' => 250000, 'weight_grams' => 800]));

        config(['whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake(['gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n])]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inShop(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->shop, $fn);
    }

    private function enable(array $settings = [], ?Tenant $tenant = null): void
    {
        $tenant ??= $this->shop;
        $tenant->forceFill(['parcel_follow_up' => ParcelFollowUpSettings::clean($settings + [
            'enabled' => true, 'hour' => 10, 'days' => [1, 2, 3, 4, 5, 6, 7], 'limit' => 7,
            'texts' => ['A {nama} {resi}', 'B {nama} {kurir}', 'C {nama} {alasan}'],
            'returning' => false, 'returning_texts' => ['R1 {nama}', 'R2 {nama}', 'R3 {nama}'],
        ], $tenant)])->save();
        $this->refreshTenant($tenant);
    }

    private function refreshTenant(Tenant $tenant): void
    {
        $tenant->refresh();

        if ($tenant->is($this->shop)) {
            $this->shop = $tenant;
        }
    }

    private function channel(?Tenant $tenant = null): WaChannel
    {
        return $this->inShop(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]), $tenant);
    }

    private function inbound(string $text, string $phone = '6281234567890', ?Tenant $tenant = null): WaMessage
    {
        $channel = $this->channel($tenant);

        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), 'Sari Dewi',
        )), $tenant);
    }

    /** A buyer who chatted, in "Dikemas & dikirim", with a parcel on its way. */
    private function lead(string $phone = '6281234567890', ?Tenant $tenant = null): Lead
    {
        $this->inbound('Halo kak, pesan sepatu', $phone, $tenant);

        return $this->inShop(function () use ($phone) {
            $lead = Lead::query()->where('phone', PhoneNumber::normalize($phone))->firstOrFail();
            $lead->forceFill(['stage_id' => (new RetailStages)->stage(RetailStages::SHIPPING)->id, 'stage_entered_at' => now(), 'shipped_at' => now(), 'owner_id' => $lead->owner_id ?? $this->adminOf(app(CurrentTenant::class)->get())->id])->save();
            Shipment::query()->create([
                'lead_id' => $lead->id, 'provider' => 'everpro', 'sequence' => 1, 'client_order_no' => 'JL-'.$lead->tenant_id.'-'.$lead->id.'-1',
                'awb' => 'JNE'.$lead->id.'00', 'courier' => 'JNE REG', 'state' => Shipment::SHIPPED, 'status' => ShipmentStatus::OUT_FOR_DELIVERY, 'awb_notified_at' => now(),
            ]);

            return $lead->fresh();
        }, $tenant);
    }

    private function parcel(Lead $lead): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->where('lead_id', $lead->id)->firstOrFail(), Tenant::find($lead->tenant_id));
    }

    /** The courier reports $status now (as tracking would): the parcel row changes and StatusNotices runs. */
    private function courier(Lead $lead, string $status, string $reason = 'alamat tidak ditemukan'): void
    {
        $tenant = Tenant::find($lead->tenant_id);
        $this->inShop(function () use ($lead, $status, $reason) {
            $shipment = Shipment::query()->where('lead_id', $lead->id)->firstOrFail();
            $shipment->forceFill(['status' => $status, 'status_reason' => $reason, 'final_at' => in_array($status, ShipmentStatus::FINAL, true) ? now() : null,
                'state' => $status === ShipmentStatus::CANCELLED ? Shipment::CANCELLED : $shipment->state])->save();
            app(StatusNotices::class)->statusChanged($shipment, $status, now());
        }, $tenant);
    }

    private function runFollowUps(?Tenant $tenant = null): array
    {
        $tenant ??= $this->shop;
        $this->refreshTenant($tenant);

        return app(ParcelFollowUps::class)->run($tenant->fresh());
    }

    /** @return list<string> bodies of the follow-ups sent (status messages excluded) */
    private function followUps(?Lead $lead = null): array
    {
        return $this->inShop(fn () => WaMessage::query()->where('direction', 'out')->where('origin', 'shipping')
            ->when($lead, fn ($q) => $q->whereHas('conversation', fn ($c) => $c->where('lead_id', $lead->id)))
            ->orderBy('id')->pluck('body')->filter(fn ($b) => preg_match('/^[ABCR]\d? /', (string) $b))->values()->all());
    }

    private function row(Lead $lead): ParcelFollowUp
    {
        return $this->inShop(fn () => ParcelFollowUp::query()->where('lead_id', $lead->id)->firstOrFail());
    }

    private function at(string $local): void
    {
        $this->travelTo(Carbon::parse($local, 'Asia/Jakarta'));
    }

    // ---- Sending -----------------------------------------------------------------------------------------------

    public function test_off_by_default_nothing_is_sent(): void
    {
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->at('2026-11-26 10:05');

        $this->assertFalse(ParcelFollowUpSettings::of($this->shop)->enabled);
        $this->assertSame(['sent' => 0, 'stopped' => 0, 'blocked' => 0], $this->runFollowUps());
        $this->assertSame([], $this->followUps());
        $this->assertSame(1, $this->row($lead)->cycle, 'The cycle is still recorded, for when it is switched on.');
    }

    public function test_the_first_follow_up_goes_the_next_day_at_the_send_hour_once_a_day_with_rotating_texts(): void
    {
        $this->enable();
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->assertSame(1, $this->inShop(fn () => WaMessage::query()->where('direction', 'out')->where('origin', 'shipping')->count()), 'The usual "Gagal diantar" status message.');

        // Same day, after the send hour: no second message today.
        $this->at('2026-11-25 10:30');
        $this->runFollowUps();
        $this->assertSame([], $this->followUps());

        // Next day, before the hour: nothing; at the hour: the first text.
        $this->at('2026-11-26 09:55');
        $this->runFollowUps();
        $this->assertSame([], $this->followUps());
        $this->at('2026-11-26 10:05');
        $this->assertSame(1, $this->runFollowUps()['sent']);
        $this->assertSame(['A Sari JNE'.$lead->id.'00'], $this->followUps());

        // The scheduler runs again (twice) the same day: still one.
        $this->runFollowUps();
        Artisan::call('shipping:parcel-follow-ups');
        $this->assertCount(1, $this->followUps());

        $this->at('2026-11-27 10:00');
        $this->runFollowUps();
        $this->at('2026-11-28 11:00');
        $this->runFollowUps();
        $this->at('2026-11-29 10:20');
        $this->runFollowUps();
        $this->assertSame(['A Sari JNE'.$lead->id.'00', 'B Sari JNE REG', 'C Sari alamat tidak ditemukan', 'A Sari JNE'.$lead->id.'00'], $this->followUps());
        $this->assertSame(4, $this->row($lead)->sent_count);
        $this->assertSame(4, $this->inShop(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('body', 'like', 'Follow-up paket gagal antar ke-%')->count()));
    }

    public function test_send_hour_follows_the_agency_time_zone_and_send_days(): void
    {
        $this->shop->forceFill(['timezone' => 'Asia/Makassar'])->save();
        $this->enable(['hour' => 14, 'days' => [4]]); // Thursdays only, 14.00 WITA
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);

        $this->travelTo(Carbon::parse('2026-11-26 13:50', 'Asia/Makassar')); // Thursday
        $this->runFollowUps();
        $this->assertSame([], $this->followUps(), '13.50 WITA is before the hour (though 12.50 in Jakarta).');
        $this->travelTo(Carbon::parse('2026-11-26 14:01', 'Asia/Makassar'));
        $this->runFollowUps();
        $this->assertCount(1, $this->followUps());

        $this->travelTo(Carbon::parse('2026-11-27 14:01', 'Asia/Makassar')); // Friday: not a send day
        $this->runFollowUps();
        $this->assertCount(1, $this->followUps());

        // After the catch-up window the day is over.
        $this->travelTo(Carbon::parse('2026-12-03 18:10', 'Asia/Makassar'));
        $this->runFollowUps();
        $this->assertCount(1, $this->followUps());
        $this->travelTo(Carbon::parse('2026-12-03 17:30', 'Asia/Makassar'));
        $this->runFollowUps();
        $this->assertCount(2, $this->followUps());
    }

    public function test_at_least_20_hours_after_the_status_message(): void
    {
        $this->enable();
        $this->at('2026-11-25 16:00');
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);

        $this->at('2026-11-26 10:05'); // 18 hours later
        $this->runFollowUps();
        $this->assertSame([], $this->followUps());
        $this->at('2026-11-26 12:05'); // 20 hours, still in the window (tried once today already? no: the gap check comes first)
        $this->runFollowUps();
        $this->assertCount(1, $this->followUps());
    }

    public function test_the_hour_is_kept_between_9_and_17(): void
    {
        $this->assertSame(9, ParcelFollowUpSettings::of(new Tenant(['parcel_follow_up' => ['hour' => 6]]))->hour);
        $this->assertSame(17, ParcelFollowUpSettings::of(new Tenant(['parcel_follow_up' => ['hour' => 22]]))->hour);
        $this->assertSame(10, ParcelFollowUpSettings::of(new Tenant)->hour);
        $this->assertSame(7, ParcelFollowUpSettings::of(new Tenant)->limit);
        $this->assertCount(3, ParcelFollowUpSettings::of(new Tenant(['parcel_follow_up' => ['texts' => ['Satu saja']]]))->texts, 'Topped up to three variations.');
    }

    // ---- Stopping ----------------------------------------------------------------------------------------------

    public function test_a_reply_from_the_buyer_stops_it_tells_the_owner_and_marks_the_chat_until_someone_answers(): void
    {
        $this->enable();
        $lead = $this->lead();
        $owner = User::find($lead->owner_id);
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->at('2026-11-26 10:05');
        $this->runFollowUps();
        $before = $owner->notifications()->count();

        $this->at('2026-11-26 15:00');
        $this->inbound('Maaf kak, besok saya di rumah');
        $this->at('2026-11-27 10:05');
        $this->assertSame(1, $this->runFollowUps()['stopped']);
        $this->assertCount(1, $this->followUps());
        $row = $this->row($lead);
        $this->assertSame([ParcelFollowUp::STOPPED, 'pembeli membalas'], [$row->state, $row->stop_reason]);
        $this->assertNotNull($row->attention_at);
        $this->assertSame($before + 1, $owner->notifications()->count());
        $this->assertTrue($this->inShop(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('body', 'like', 'Follow-up paket gagal antar berhenti: pembeli membalas%')->exists()));

        $this->actingInTenant($owner, $this->shop);
        Livewire::test(Inbox::class)->assertSee('Perlu tindakan');
        Livewire::test(Inbox::class, ['leadId' => $lead->id])->set('draft', 'Baik kak, kami jadwalkan antar ulang')->call('send')->assertDontSee('Perlu tindakan');
        $this->assertNull($this->row($lead)->attention_at);

        $this->at('2026-11-28 10:05');
        $this->runFollowUps();
        $this->assertCount(1, $this->followUps(), 'Stopped stays stopped.');
    }

    public function test_moving_the_lead_to_batal_stops_it(): void
    {
        $this->enable();
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->inShop(fn () => $lead->forceFill(['stage_id' => (new RetailStages)->stage(RetailStages::CANCELLED)->id])->save());

        $this->at('2026-11-26 10:05');
        $this->runFollowUps();
        $this->assertSame([], $this->followUps());
        $this->assertStringStartsWith('lead dipindah ke', $this->row($lead)->stop_reason);
        $this->assertNull($this->row($lead)->attention_at);
    }

    public function test_delivered_returned_or_cancelled_stops_it(): void
    {
        $this->enable();

        foreach ([ShipmentStatus::DELIVERED => 'paket diterima pembeli', ShipmentStatus::RETURNED => 'paket sudah kembali ke toko', ShipmentStatus::CANCELLED => 'kiriman dibatalkan'] as $status => $reason) {
            $lead = $this->lead('62812000'.random_int(10000, 99999));
            $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
            $this->courier($lead, $status);
            $this->assertSame([ParcelFollowUp::STOPPED, $reason], [$this->row($lead)->state, $this->row($lead)->stop_reason], $status);
        }

        $this->at('2026-11-26 10:05');
        $this->runFollowUps();
        $this->assertSame([], $this->followUps());
    }

    public function test_a_person_can_stop_it_by_hand(): void
    {
        $this->enable();
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $admin = $this->adminOf($this->shop);
        $this->actingInTenant($admin, $this->shop);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])->callAction('stopParcelFollowUp')->assertNotified('Follow-up paket dihentikan');
        $this->assertSame('dihentikan oleh '.$admin->name, $this->row($lead)->stop_reason);

        $this->at('2026-11-26 10:05');
        $this->runFollowUps();
        $this->assertSame([], $this->followUps());
    }

    public function test_the_limit_stops_it_and_tells_the_owner_and_a_new_failure_keeps_the_total(): void
    {
        $this->enable(['limit' => 2]);
        $lead = $this->lead();
        $owner = User::find($lead->owner_id);
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->at('2026-11-26 10:05');
        $this->runFollowUps();

        // Out for delivery again, then failed again: a new cycle, the total still counts.
        $this->at('2026-11-26 13:00');
        $this->courier($lead, ShipmentStatus::OUT_FOR_DELIVERY);
        $this->at('2026-11-26 16:00');
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED, 'penerima tidak di tempat');
        $this->assertSame([2, 1], [$this->row($lead)->cycle, $this->row($lead)->sent_count]);

        $this->at('2026-11-27 12:30');
        $this->runFollowUps();
        $this->assertCount(2, $this->followUps());
        $before = $owner->notifications()->count();

        $this->at('2026-11-28 10:05');
        $this->runFollowUps();
        $this->assertCount(2, $this->followUps());
        $this->assertSame([ParcelFollowUp::STOPPED, 'batas 2 pesan tercapai'], [$this->row($lead)->state, $this->row($lead)->stop_reason]);
        $this->assertNotNull($this->row($lead)->attention_at);
        $this->assertSame($before + 1, $owner->notifications()->count());

        // A third failure does not reopen it: the limit is per parcel.
        $this->courier($lead, ShipmentStatus::OUT_FOR_DELIVERY);
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->assertSame(ParcelFollowUp::STOPPED, $this->row($lead)->state);
    }

    public function test_limit_zero_means_no_limit(): void
    {
        $this->enable(['limit' => 0]);
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);

        foreach (range(26, 30) as $day) {
            $this->at("2026-11-{$day} 10:05");
            $this->runFollowUps();
        }

        $this->assertCount(5, $this->followUps());
        $this->assertSame(ParcelFollowUp::ACTIVE, $this->row($lead)->state);
    }

    public function test_returning_takes_part_only_when_switched_on_with_its_own_texts(): void
    {
        $this->enable();
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->courier($lead, ShipmentStatus::RETURNING);
        $this->at('2026-11-26 10:05');
        $this->runFollowUps();
        $this->assertSame([], $this->followUps(), 'Returning is off.');

        $this->enable(['returning' => true]);
        $this->at('2026-11-27 10:05');
        $this->runFollowUps();
        $this->assertSame(['R1 Sari'], $this->followUps());
    }

    public function test_on_switching_on_only_failures_of_the_last_7_days_take_part_older_ones_are_listed(): void
    {
        $old = $this->lead('6281200000001');
        $this->at('2026-11-15 10:00');
        $this->courier($old, ShipmentStatus::DELIVERY_FAILED);
        $this->at('2026-11-23 10:00');
        $recent = $this->lead('6281200000002');
        $this->courier($recent, ShipmentStatus::DELIVERY_FAILED);
        // A parcel that failed before this code existed (no row yet).
        $this->inShop(fn () => ParcelFollowUp::query()->where('lead_id', $recent->id)->delete());

        $this->at('2026-11-25 08:00');
        $this->enable();
        $this->at('2026-11-25 10:05');
        $this->runFollowUps();

        $this->assertCount(1, $this->followUps($recent));
        $this->assertSame([], $this->followUps($old));
        $this->assertSame([$this->parcel($old)->id], $this->inShop(fn () => ParcelFollowUps::backlog(ParcelFollowUpSettings::of($this->shop))->map(fn ($r) => $r['shipment']->id)->all()));

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(StageAutomationPage::class)->assertSee('tidak ikut follow-up otomatis')->assertSee($old->name);
    }

    public function test_a_closed_window_with_only_a_paid_template_sends_nothing_and_says_why(): void
    {
        $this->enable();
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->inShop(function () {
            WaChannel::query()->update(['status' => WaChannelStatus::Disconnected]);
            WaChannel::create(['type' => WaChannelType::Official, 'name' => 'Resmi', 'phone' => '+6281100000009', 'status' => WaChannelStatus::Connected, 'position' => 2,
                'credentials' => ['phone_number_id' => '1', 'access_token' => 't']]);
        });

        $this->at('2026-11-27 10:05');
        $this->assertSame(1, $this->runFollowUps()['blocked']);
        $this->assertSame([], $this->followUps());
        $this->assertTrue($this->inShop(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('body', 'like', '%tidak terkirim: jendela chat gratis sudah tutup%')->exists()));
        $this->runFollowUps();
        $this->assertSame(1, $this->inShop(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('body', 'like', '%tidak terkirim%')->count()), 'One try per day.');
    }

    public function test_a_read_only_subscription_sends_nothing(): void
    {
        $this->enable();
        $lead = $this->lead();
        $this->courier($lead, ShipmentStatus::DELIVERY_FAILED);
        $this->shop->currentSubscription()->update(['plan' => 'tim', 'status' => Subscription::READ_ONLY, 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        $this->at('2026-11-26 10:05');
        $this->assertSame(['sent' => 0, 'stopped' => 0, 'blocked' => 0], $this->runFollowUps());
        $this->assertSame([], $this->followUps());
    }

    public function test_agencies_are_isolated(): void
    {
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $other->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->enable();
        $mine = $this->lead();
        $theirs = $this->lead('6281299990000', $other);
        $this->courier($mine, ShipmentStatus::DELIVERY_FAILED);
        $this->courier($theirs, ShipmentStatus::DELIVERY_FAILED);

        $this->at('2026-11-26 10:05');
        Artisan::call('shipping:parcel-follow-ups');

        $this->assertCount(1, $this->followUps($mine));
        $this->assertSame(0, app(CurrentTenant::class)->run($other, fn () => WaMessage::query()->where('origin', 'shipping')->where('body', 'like', 'A %')->count()), 'The other agency never switched it on.');
        $this->assertSame([], app(CurrentTenant::class)->run($other, fn () => ParcelFollowUps::attention([$mine->id])));
    }

    public function test_the_settings_page_saves_and_rejects_unknown_variables(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(StageAutomationPage::class)->assertSet('data.parcel.enabled', false)
            ->set('data.parcel.enabled', true)->set('data.parcel.texts', [['text' => 'Halo {nama} {kupon}'], ['text' => 'Dua {resi}'], ['text' => 'Tiga {kurir}']])
            ->call('save')->assertHasErrors();

        Livewire::test(StageAutomationPage::class)->set('data.parcel.enabled', true)->set('data.parcel.hour', 11)->set('data.parcel.limit', 0)
            ->assertSee('berisiko membuat nomor WhatsApp Anda dilaporkan atau diblokir')
            ->set('data.parcel.texts', [['text' => 'Satu {nama}'], ['text' => 'Dua {resi}'], ['text' => 'Tiga {kurir} {alasan}']])->call('save')->assertHasNoErrors();

        $settings = ParcelFollowUpSettings::of($this->shop->fresh());
        $this->assertTrue($settings->enabled);
        $this->assertSame([11, 0, ['Satu {nama}', 'Dua {resi}', 'Tiga {kurir} {alasan}']], [$settings->hour, $settings->limit, $settings->texts]);
        $this->assertNotNull($settings->enabledAt);
        $this->assertNotNull($this->shop->fresh()->stage_automation, 'The stage rules are saved beside it.');
    }

    // ---- The time-based "Selesai" rule -----------------------------------------------------------------------

    public function test_the_time_based_selesai_rule_skips_parcels_that_did_not_arrive(): void
    {
        $this->shop->forceFill(['stage_automation' => ['delivered' => ['enabled' => true, 'days_java' => 2, 'days_other' => 2]]])->save();
        $leads = [];

        foreach ([ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING, ShipmentStatus::RETURNED, ShipmentStatus::IN_TRANSIT] as $i => $status) {
            $lead = $this->lead('62812300000'.$i);
            $this->inShop(fn () => Shipment::query()->where('lead_id', $lead->id)->update(['status' => $status]));
            $this->inShop(fn () => $lead->forceFill(['shipped_at' => now()->subDays(3), 'shipping_province' => 'Jawa Barat', 'deal_value' => 100000])->save());
            $leads[$status] = $lead;
        }

        app(StageAutomation::class)->runScheduled($this->shop->fresh());

        $stage = fn (Lead $l) => $this->inShop(fn () => Lead::query()->with('stage')->find($l->id)->stage->name);
        $this->assertSame('Dikemas & dikirim', $stage($leads[ShipmentStatus::DELIVERY_FAILED]));
        $this->assertSame('Dikemas & dikirim', $stage($leads[ShipmentStatus::RETURNING]));
        $this->assertSame('Dikemas & dikirim', $stage($leads[ShipmentStatus::RETURNED]));
        $this->assertSame('Selesai', $stage($leads[ShipmentStatus::IN_TRANSIT]), 'Still on its way: the time rule applies as before.');
    }

    public function test_the_audit_command_lists_done_leads_with_undelivered_parcels_and_moves_back_only_with_apply(): void
    {
        $lead = $this->lead();
        $fine = $this->lead('6281299991111');
        $this->inShop(function () use ($lead, $fine) {
            $done = (new RetailStages)->stage(RetailStages::DONE);
            Shipment::query()->where('lead_id', $lead->id)->update(['status' => ShipmentStatus::DELIVERY_FAILED]);
            Shipment::query()->where('lead_id', $fine->id)->update(['status' => ShipmentStatus::DELIVERED, 'final_at' => now()]);

            foreach ([$lead, $fine] as $l) {
                app(MoveLeadToStage::class)($l->fresh('stage'), $done, null, ['unit' => 'Sepatu', 'deal_value' => 250000], '2 hari setelah nomor resi dikirim');
            }

            ConversionEvent::query()->create(['lead_id' => $lead->id, 'event_name' => 'Purchase', 'event_id' => 'lead-'.$lead->id.'-Purchase-x', 'trigger' => 'stage']);
        });

        $this->artisan('stages:audit-done', ['--tenant' => 'quicker-sport'])
            ->expectsOutputToContain('Purchase')->expectsOutputToContain('Hanya daftar')->assertSuccessful();
        $this->assertSame('Selesai', $this->inShop(fn () => Lead::query()->with('stage')->find($lead->id)->stage->name), 'Nothing moves without --apply.');
        $this->assertSame([$lead->id], $this->inShop(fn () => array_map(fn ($r) => $r['lead']->id, AuditDoneParcelsCommand::find())));

        $this->artisan('stages:audit-done', ['--tenant' => 'quicker-sport', '--apply' => true])->expectsOutputToContain('1 lead dipindah')->assertSuccessful();
        $this->assertSame('Dikemas & dikirim', $this->inShop(fn () => Lead::query()->with('stage')->find($lead->id)->stage->name));
        $this->assertSame('Selesai', $this->inShop(fn () => Lead::query()->with('stage')->find($fine->id)->stage->name));
    }
}
