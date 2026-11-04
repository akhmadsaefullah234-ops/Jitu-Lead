<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Actions\RunFollowUps;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\Filament\Resources\LeadForms\Pages\EditLeadForm;
use App\LeadForms\FormFields;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WelcomeMessage;
use App\Support\CurrentTenant;
use App\Welcome\WelcomeSender;
use App\Welcome\WelcomeSettings;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A buyer who sends a lead form gets a WhatsApp welcome from the shop after the form's delay, even without
 * opening the chat: skipped when they already chatted, held outside service hours, once per lead and form,
 * on the lead owner's number, and noted on the lead whatever happens.
 */
class WelcomeMessageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private WaChannel $gw;

    private Property $bag;

    /** @var list<array{url: string, text: ?string}> */
    private array $outbox = [];

    private bool $refuse = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:14:00', 'Asia/Jakarta'));
        GatewayUrlGuard::resolveUsing(fn (string $host) => in_array($host, ['gw.example.com', 'gw2.example.com'], true) ? ['93.184.216.34'] : []);
        $n = 0;
        Http::fake(['*' => function (Request $request) use (&$n) {
            if ($this->refuse) {
                return Http::response(['error' => 'Nomor tidak terdaftar di WhatsApp'], 422);
            }
            $this->outbox[] = ['url' => $request->url(), 'text' => $request['text'] ?? $request['message'] ?? null];

            return Http::response(['id' => 'm'.++$n]);
        }]);

        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->shop->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->gw = $this->gateway('GW', '+6281100000002', 'gw.example.com', 1);
        $this->bag = $this->in(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 349000]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function gateway(string $name, string $phone, string $host, int $position): WaChannel
    {
        return $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => $name, 'phone' => $phone, 'status' => WaChannelStatus::Connected, 'position' => $position,
            'credentials' => ['base_url' => "https://$host", 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function form(array $welcome = ['active' => true, 'delay' => 2], string $slug = 'order'): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Order Tas', 'slug' => $slug, 'status' => 'active', 'design' => ['title' => 'Order', 'color' => '#dc2626'], 'property_id' => $this->bag->getKey(),
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'warna', 'type' => 'select', 'label' => 'Warna', 'options' => ['Hitam', 'Biru'], 'maps_to' => 'variant'],
                ['key' => 'jumlah', 'type' => 'number', 'label' => 'Jumlah', 'maps_to' => 'quantity'],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
            'welcome' => $welcome,
        ]));
    }

    private function submit(string $slug = 'order', string $phone = '081234567890', array $extra = []): Lead
    {
        $this->postJson("/f/quicker-sport/$slug", ['f' => ['nama' => 'Rina Kartika', 'wa' => $phone, 'warna' => 'Hitam', 'jumlah' => 2]] + $extra)->assertOk();

        return $this->in(fn () => Lead::query()->where('phone', '+62'.substr($phone, 1))->firstOrFail());
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Jakarta'));
        $this->artisan('welcome:send')->assertSuccessful();
    }

    /** @return list<WaMessage> */
    private function messages(Lead $lead): array
    {
        return $this->in(fn () => WaMessage::query()->whereHas('conversation', fn ($q) => $q->where('lead_id', $lead->getKey()))->orderBy('id')->get()->all());
    }

    private function welcome(Lead $lead): WelcomeMessage
    {
        return $this->in(fn () => WelcomeMessage::query()->where('lead_id', $lead->getKey())->sole());
    }

    /** @return list<string> */
    private function activities(Lead $lead): array
    {
        return $this->in(fn () => $lead->activities()->reorder()->orderBy('id')->whereIn('type', ['welcome', 'whatsapp_out'])->pluck('body')->all());
    }

    private function inbound(string $from, string $text): void
    {
        $body = json_encode(['event' => 'message', 'id' => 'in'.uniqid(), 'from' => $from, 'type' => 'text', 'text' => $text, 'timestamp' => now()->timestamp]);
        $this->call('POST', "/webhooks/whatsapp/gateway/{$this->gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json', 'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();
    }

    public function test_an_active_form_sends_the_welcome_after_its_delay_on_the_gateway_with_the_intro_first(): void
    {
        $this->form();
        $lead = $this->submit();

        $this->assertSame([], $this->messages($lead), 'nothing goes out before the delay');
        $this->at('2026-10-12 10:15:00');
        $this->assertSame([], $this->messages($lead));

        $this->at('2026-10-12 10:16:00');
        $messages = $this->messages($lead);

        $this->assertCount(2, $messages);
        $this->assertTrue($messages[0]->is_intro, 'a gateway number that never wrote to the buyer introduces the agency first');
        $this->assertSame('welcome', $messages[1]->origin);
        $this->assertSame(MessageStatus::Sent, $messages[1]->status);
        $this->assertSame($this->gw->getKey(), $messages[1]->channel_id);
        $this->assertSame(
            'Halo kak Rina 😊 Terima kasih sudah mengisi pesanan Tas Badminton di Quicker Sport. Ini ringkasannya ya kak: Tas Badminton (Hitam) x2. Mohon dicek, kalau sudah sesuai balas *OK* ya kak, pesanan langsung kami proses 🙏',
            $messages[1]->body,
        );
        $this->assertSame(WelcomeMessage::SENT, $this->welcome($lead)->status);
        $this->assertContains('Pesan sambutan WhatsApp terkirim lewat gateway', $this->activities($lead));

        $this->at('2026-10-12 10:30:00');
        $this->assertCount(2, $this->messages($lead), 'never twice');
    }

    public function test_the_welcome_is_skipped_when_the_buyer_chats_within_the_delay(): void
    {
        $this->form();
        $lead = $this->submit();

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:15:00', 'Asia/Jakarta'));
        $this->inbound('6281234567890', 'Kak saya mau tanya ongkir');
        $this->at('2026-10-12 10:17:00');

        $this->assertSame([], collect($this->messages($lead))->where('direction', 'out')->all());
        $this->assertSame(WelcomeMessage::SKIPPED, $this->welcome($lead)->status);
        $this->assertContains('Pesan sambutan WhatsApp dilewati: pembeli sudah chat', $this->activities($lead));
    }

    public function test_outside_service_hours_the_welcome_waits_until_five_in_the_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 22:30:00', 'Asia/Jakarta'));
        $this->form();
        $lead = $this->submit();

        $this->at('2026-10-12 22:33:00');
        $welcome = $this->welcome($lead);
        $this->assertSame(WelcomeMessage::PENDING, $welcome->status);
        $this->assertTrue($welcome->due_at->equalTo(Carbon::parse('2026-10-13 05:00:00', 'Asia/Jakarta')));
        $this->assertSame([], $this->messages($lead));
        $this->assertContains('Pesan sambutan WhatsApp ditunda sampai 05:00 (di luar jam layanan)', $this->activities($lead));

        $this->at('2026-10-13 03:00:00');
        $this->assertSame([], $this->messages($lead));

        $this->at('2026-10-13 05:00:00');
        $this->assertSame(WelcomeMessage::SENT, $this->welcome($lead)->status);
        $this->assertSame('welcome', collect($this->messages($lead))->last()->origin);
    }

    public function test_the_same_number_sending_the_form_twice_gets_one_welcome(): void
    {
        $this->form();
        $lead = $this->submit();
        $this->submit();

        $this->assertSame(1, $this->in(fn () => WelcomeMessage::query()->count()));
        $this->at('2026-10-12 10:20:00');
        $this->submit();
        $this->at('2026-10-12 10:30:00');

        $this->assertCount(1, collect($this->messages($lead))->where('is_intro', false)->where('origin', 'welcome'));
    }

    public function test_a_returning_lead_on_another_form_is_welcomed_only_without_messages_in_the_last_day(): void
    {
        $this->form();
        $this->form(slug: 'order-2');
        $lead = $this->submit();
        $this->at('2026-10-12 10:16:00');

        // Another form, an hour later: the shop wrote to this number less than 24 hours ago.
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:16:00', 'Asia/Jakarta'));
        $this->submit('order-2');
        $this->at('2026-10-12 11:20:00');

        $second = $this->in(fn () => WelcomeMessage::query()->where('lead_id', $lead->getKey())->latest('id')->first());
        $this->assertTrue($second->returning);
        $this->assertSame(WelcomeMessage::SKIPPED, $second->status);
        $this->assertContains('Pesan sambutan WhatsApp dilewati: nomor ini sudah dihubungi dalam 24 jam terakhir', $this->activities($lead));

        // A returning lead who has not heard from the shop in a day is welcomed.
        $this->form(slug: 'order-3');
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'Asia/Jakarta'));
        $this->submit('order-3');
        $this->at('2026-10-13 12:05:00');
        $this->assertSame(WelcomeMessage::SENT, $this->in(fn () => WelcomeMessage::query()->where('lead_id', $lead->getKey())->latest('id')->value('status')));
    }

    public function test_the_variables_are_filled_from_the_order_and_the_product_text_is_used_when_the_form_has_none(): void
    {
        $this->bag->forceFill(['welcome_text' => 'Halo {nama}, pesanan {produk} dari {agensi}: {ringkasan}. Total {total}. Salam, {agen}.'])->save();
        $this->in(fn () => $this->adminOf($this->shop)->forceFill(['name' => 'Dewi'])->save());
        $this->form(['active' => true, 'delay' => 1, 'text' => '']);
        $lead = $this->submit();
        $this->at('2026-10-12 10:15:00');

        $this->assertSame(
            'Halo Rina, pesanan Tas Badminton dari Quicker Sport: Tas Badminton (Hitam) x2. Total Rp698.000 (ongkir kami hitungkan ya kak). Salam, Dewi.',
            collect($this->messages($lead))->last()->body,
        );
    }

    public function test_the_welcome_goes_out_on_the_lead_owners_number(): void
    {
        $second = $this->gateway('GW Dewi', '+6281100000009', 'gw2.example.com', 2);
        $this->in(fn () => $this->adminOf($this->shop)->forceFill(['whatsapp' => '081100000009'])->save());
        $this->form();
        $lead = $this->submit();
        $this->assertSame($this->adminOf($this->shop)->getKey(), $lead->owner_id);

        $this->at('2026-10-12 10:16:00');

        $this->assertSame([$second->getKey(), $second->getKey()], array_map(fn (WaMessage $m) => $m->channel_id, $this->messages($lead)));
        $this->assertStringStartsWith('https://gw2.example.com', end($this->outbox)['url']);
    }

    public function test_a_bad_number_or_a_refused_message_is_noted_as_failed_and_never_retried(): void
    {
        $this->form();
        $lead = $this->in(fn () => Lead::create(['name' => 'Salah', 'phone' => '+62215551234', 'stage_id' => Stage::query()->where('type', 'open')->orderBy('position')->value('id')]));
        $welcome = $this->in(fn () => app(WelcomeSender::class)->schedule(LeadForm::query()->firstOrFail(), null, $lead, true));
        $this->at('2026-10-12 10:16:00');

        $this->assertSame(WelcomeMessage::FAILED, $welcome->refresh()->status);
        $this->assertContains('Pesan sambutan WhatsApp gagal: nomor +62215551234 bukan nomor WhatsApp yang valid', $this->activities($lead));

        $this->refuse = true;
        $rina = $this->submit();
        $this->at('2026-10-12 10:17:00');
        $this->at('2026-10-12 10:30:00');
        $this->at('2026-10-12 11:30:00');

        $this->assertSame(WelcomeMessage::FAILED, $this->welcome($rina)->status);
        $this->assertSame(1, $this->welcome($rina)->attempts);
        $this->assertCount(1, $this->messages($rina), 'the intro was refused once and nothing was tried again');
        $this->assertSame([], $this->outbox);
    }

    public function test_a_number_sending_many_welcomes_waits_a_minute(): void
    {
        $this->form();
        $lead = $this->submit();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:16:00', 'Asia/Jakarta'));
        $this->in(function () {
            $other = WaConversation::create(['lead_id' => Lead::create(['name' => 'X', 'phone' => '+6281200000001', 'stage_id' => Stage::query()->where('type', 'open')->value('id')])->getKey(), 'phone' => '+6281200000001']);
            foreach (range(1, WelcomeSender::RATE_PER_MINUTE) as $i) {
                $other->messages()->create(['channel_id' => $this->gw->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'x', 'origin' => 'welcome', 'status' => MessageStatus::Sent, 'external_id' => "r$i", 'sent_at' => now()->subSeconds(30)]);
            }
        });

        $this->at('2026-10-12 10:16:00');
        $this->assertSame(WelcomeMessage::PENDING, $this->welcome($lead)->status);
        $this->assertSame([], $this->messages($lead));

        $this->at('2026-10-12 10:17:00');
        $this->assertSame(WelcomeMessage::SENT, $this->welcome($lead)->status);
    }

    public function test_a_follow_up_due_within_two_hours_of_the_welcome_waits_and_the_welcome_is_no_reply(): void
    {
        $this->in(fn () => FollowUpRule::create([
            'name' => 'Tas H+0', 'stage_id' => Stage::query()->where('type', 'open')->orderBy('position')->value('id'), 'property_id' => $this->bag->getKey(),
            'delay_days' => 0, 'send_time' => '11:00', 'body' => 'Masih minat {produk}?', 'active' => true,
        ]));
        $this->form();
        $lead = $this->submit();
        $this->at('2026-10-12 10:16:00');

        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00:00', 'Asia/Jakarta'));
        app(RunFollowUps::class)($this->shop);
        $this->assertSame([], $this->in(fn () => FollowUpLog::query()->pluck('status')->all()), 'held, not skipped');

        Carbon::setTestNow(Carbon::parse('2026-10-12 12:17:00', 'Asia/Jakarta'));
        app(RunFollowUps::class)($this->shop);
        $this->assertSame(['sent'], $this->in(fn () => FollowUpLog::query()->pluck('status')->all()), 'the welcome is not the buyer writing back');
        $this->assertSame('Masih minat Tas Badminton?', collect($this->messages($lead))->last()->body);
    }

    public function test_new_shop_forms_start_on_and_old_forms_and_property_forms_stay_off(): void
    {
        $this->assertTrue((bool) $this->in(fn () => LeadForm::create(['name' => 'Baru', 'slug' => 'baru', 'fields' => []]))->welcome['active']);

        $old = $this->form();
        $old->forceFill(['welcome' => null])->save();
        $this->assertFalse(WelcomeSettings::for($old->refresh())->active);
        $this->submit();
        $this->assertSame(0, $this->in(fn () => WelcomeMessage::query()->count()));

        $agency = $this->makeTenant('griya-prima');
        $this->assertFalse((bool) app(CurrentTenant::class)->run($agency, fn () => LeadForm::create(['name' => 'Survei', 'slug' => 'survei', 'fields' => []]))->welcome['active']);
        $this->assertStringContainsString('jadwalkan survei', WelcomeSettings::builtIn($agency));
    }

    public function test_a_landing_page_can_follow_the_form_use_its_own_text_or_switch_the_welcome_off(): void
    {
        $form = $this->form(['active' => false]);
        $page = fn (array $welcome, string $slug) => $this->in(fn () => LandingPage::create(['title' => 'Promo', 'slug' => $slug, 'status' => 'published', 'blocks' => [], 'welcome' => $welcome]));

        $custom = $page(['mode' => 'custom', 'delay' => 1, 'text' => 'Halo {nama}, terima kasih dari halaman promo.'], 'promo');
        $lead = $this->submit(extra: ['page' => $custom->getKey()]);
        $this->at('2026-10-12 10:15:00');
        $this->assertSame('Halo Rina, terima kasih dari halaman promo.', collect($this->messages($lead))->last()->body);

        $form->forceFill(['welcome' => ['active' => true, 'delay' => 2]])->save();
        $off = $page(['mode' => 'off'], 'promo-2');
        $this->submit(phone: '081234567899', extra: ['page' => $off->getKey()]);
        $this->assertSame(1, $this->in(fn () => WelcomeMessage::query()->count()));
    }

    public function test_the_settings_are_edited_on_the_form_landing_page_and_shown_in_the_inbox(): void
    {
        $form = $this->form(['active' => false]);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(EditLeadForm::class, ['record' => $form->getKey()])
            ->assertSee('Pesan sambutan WhatsApp')
            ->fillForm(['welcome.active' => true, 'welcome.delay' => 5, 'welcome.text' => 'Halo {nama} 😊'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['active' => true, 'delay' => 5, 'text' => 'Halo {nama} 😊'], array_intersect_key($form->refresh()->welcome, array_flip(['active', 'delay', 'text'])));

        $page = LandingPage::create(['title' => 'Promo', 'slug' => 'promo', 'status' => 'draft', 'blocks' => []]);
        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
            ->fillForm(['welcome.mode' => 'off'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('off', $page->refresh()->welcome['mode']);

        $lead = $this->submit();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:20:00', 'Asia/Jakarta'));
        $this->artisan('welcome:send');
        $this->assertSame('welcome', collect($this->messages($lead))->last()->origin);
        Livewire::test(Inbox::class, ['leadId' => $lead->getKey()])->assertSee('Sambutan otomatis');
    }
}
