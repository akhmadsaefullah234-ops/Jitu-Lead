<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Ai\PropertyTools;
use App\Automation\PropertyStageAutomation;
use App\Automation\PropertyStageAutomationSettings;
use App\Automation\PropertyStages;
use App\Automation\StageAutomation;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\StageAutomationPage;
use App\Models\AiDraft;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A property agency's leads move through its pipeline by themselves (Pengaturan > Otomatisasi tahap) and its tasks
 * follow the chat. Every client here is made up; numbers start 0899000. "Now" is Monday 16 November 2026, 10.00 WITA.
 */
class PropertyStageAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    /** @var list<array> the model's answers, in order */
    private array $ai = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->tenant = $this->makeTenant('griya-uji');
        $this->tenant->forceFill(['timezone' => 'Asia/Makassar'])->save();
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Asisten', 'phone' => '+6289900000001',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik Kak 😊')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function tool(string $name, array $input = []): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => $name, 'input' => $input]]];
    }

    private function says(string $text, string $phone = '6289900001234'): ?WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), 'Klien Uji',
        )));
    }

    /** A reply typed in WhatsApp on the agency's phone. */
    private function phoneReply(string $text, string $phone = '6289900001234'): void
    {
        $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'out-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), null, fromMe: true,
        )));
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->with('stage')->orderBy('id')->firstOrFail());
    }

    private function step(string $key): Stage
    {
        return $this->in(fn () => (new PropertyStages)->stage($key));
    }

    private function autoNotes(): array
    {
        return $this->in(fn () => $this->lead()->activities()->reorder()->where('type', 'stage_changed')->where('body', 'like', 'Pindah otomatis%')->orderBy('id')->pluck('body')->all());
    }

    private function lastNote(): string
    {
        $notes = $this->autoNotes();

        return (string) end($notes);
    }

    private function aiOff(): void
    {
        $this->in(fn () => $this->channel->forceFill(['ai_mode' => AiMode::Off])->save());
    }

    // ---- Defaults ---------------------------------------------------------------------------------------------

    public function test_a_new_property_agency_starts_with_the_rules_on_and_an_existing_one_stays_off(): void
    {
        $settings = PropertyStageAutomationSettings::of($this->tenant->fresh());
        $this->assertTrue($settings->chosen());
        $this->assertTrue($settings->enabled('contacted'));
        $this->assertTrue($settings->enabled('tasks'));
        $this->assertFalse($settings->enabled('thanks'), 'the thank-you message waits for the agency');
        $this->assertSame(3, $settings->number('surveyed', 'hours'));
        $this->assertSame(14, $settings->number('silent', 'days_new'));

        // An agency made before these rules has nothing stored: nothing moves.
        $this->tenant->forceFill(['stage_automation' => null])->save();
        $this->aiOff();
        $this->phoneReply('Halo Kak, ada yang bisa dibantu?');
        $this->says('Halo, mau tanya rumahnya');
        $this->assertSame('Lead baru', $this->lead()->stage->name);
        $this->assertSame([], $this->autoNotes());
    }

    public function test_the_stages_are_found_by_name_or_requirement_and_keys_are_stored_for_new_agencies(): void
    {
        $this->assertSame('contacted', $this->step(PropertyStages::CONTACTED)->template_key);
        $this->assertSame('Jadwal survei', $this->step(PropertyStages::SURVEY)->name);

        $this->assertSame(
            ['survey' => 3, 'booking' => 4, 'new' => 1, 'contacted' => 2, 'closing' => 6, 'lost' => 7],
            PropertyStages::match([
                ['id' => 1, 'name' => 'Masuk', 'type' => 'open', 'requirement' => null],
                ['id' => 2, 'name' => 'Follow up', 'type' => 'open', 'requirement' => null],
                ['id' => 3, 'name' => 'Visit', 'type' => 'open', 'requirement' => 'survey'],
                ['id' => 4, 'name' => 'DP', 'type' => 'open', 'requirement' => 'booking'],
                ['id' => 5, 'name' => 'Arsip', 'type' => 'open', 'requirement' => null],
                ['id' => 6, 'name' => 'Akad', 'type' => 'won', 'requirement' => 'booking'],
                ['id' => 7, 'name' => 'Batal', 'type' => 'lost', 'requirement' => 'lost_reason'],
            ]),
        );
    }

    // ---- Rule 1 and the first task ----------------------------------------------------------------------------

    public function test_the_ai_answering_closes_the_first_task_and_a_client_reply_moves_the_lead_to_dihubungi(): void
    {
        $this->says('Halo kak, mau info rumahnya dong');
        $lead = $this->lead();
        $this->assertSame('Lead baru', $lead->stage->name, 'the first message alone is not two-way');
        $this->assertNull($lead->next_action, 'the AI answered, so "Hubungi" is done');
        $this->assertNull($lead->next_action_due_at);
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'task_auto_done')->where('body', 'like', '%Hubungi lewat WhatsApp%')->exists()));
        $this->assertSame(0, $this->in(fn () => Lead::query()->overdue()->count()));

        $this->says('Untuk tinggal kak');
        $this->assertSame('Dihubungi', $this->lead()->stage->name);
        $this->assertSame(['Pindah otomatis dari Lead baru ke Dihubungi: klien membalas pesan kita'], $this->autoNotes());
        $this->assertSame('Gali kebutuhan: tipe, lokasi, budget, cara bayar', $this->lead()->next_action, 'the new stage brings its own task');
    }

    public function test_a_lead_nobody_answered_keeps_its_task_and_a_reply_from_the_phone_closes_it(): void
    {
        $this->aiOff();
        $this->says('Halo kak');
        $this->says('Kak?');
        $this->assertSame('Lead baru', $this->lead()->stage->name, 'two messages without our answer are not two-way');
        $this->assertSame('Hubungi lewat WhatsApp atau telepon', $this->lead()->next_action);

        $this->phoneReply('Halo Kak, maaf baru balas');
        $this->assertNull($this->lead()->next_action);
        $this->says('Iya kak');
        $this->assertSame('Dihubungi', $this->lead()->stage->name);
    }

    // ---- Rule 2 -----------------------------------------------------------------------------------------------

    public function test_purpose_and_domicile_from_the_ai_after_two_replies_make_the_lead_terkualifikasi(): void
    {
        $this->says('Halo kak');
        $this->ai = [
            $this->tool(PropertyTools::NEEDS, ['tujuan' => 'hunian']),
            $this->text('Siap Kak, untuk tinggal ya 😊 Sekarang domisili di mana Kak?'),
        ];
        $this->says('Buat tinggal kak');
        $this->assertSame('Dihubungi', $this->lead()->stage->name, 'a purpose alone is not enough');
        $this->assertNull($this->lead()->next_action, '"Gali kebutuhan" is done once the purpose is written down');

        $this->ai = [
            $this->tool(PropertyTools::NEEDS, ['domisili' => 'Surabaya']),
            $this->text('Siap Kak, dari Surabaya ya 😊'),
        ];
        $this->says('Saya di Surabaya kak');

        $lead = $this->lead();
        $this->assertSame('Terkualifikasi', $lead->stage->name);
        $this->assertStringContainsString('Pindah otomatis dari Dihubungi ke Terkualifikasi: kebutuhan klien tercatat dan klien sudah membalas 2 kali', $this->lastNote());
    }

    public function test_an_agent_writing_the_needs_also_qualifies_but_one_reply_does_not(): void
    {
        $this->aiOff();
        $this->says('Halo kak');
        $this->phoneReply('Halo Kak, cari untuk apa?');
        $this->says('Investasi kak');
        $this->in(fn () => $this->lead()->forceFill(['purpose' => 'investasi', 'budget_max' => 900_000_000])->save());
        $this->assertSame('Dihubungi', $this->lead()->stage->name, 'only one answer from the client so far');

        $this->says('Budget sekitar 900 juta');
        $this->in(fn () => $this->lead()->forceFill(['domicile' => 'Malang'])->save());
        $this->assertSame('Terkualifikasi', $this->lead()->stage->name);
    }

    // ---- Rule 3 -----------------------------------------------------------------------------------------------

    public function test_a_client_cancelling_the_survey_in_the_chat_goes_back_to_terkualifikasi_with_a_task(): void
    {
        $this->says('Halo kak');
        $admin = $this->adminOf($this->tenant);
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::SURVEY), $admin,
            ['survey_at' => now()->addDays(3), 'survey_location' => 'Rumah contoh']));

        $this->ai = [$this->tool(PropertyTools::CANCEL, ['alasan' => 'ada acara keluarga']), $this->text('Baik Kak, jadwalnya kami batalkan dulu ya 🙏 Kabari saja kalau sudah siap.')];
        $this->says('Kak maaf, Sabtu saya nggak jadi survei ya, ada acara keluarga');

        $lead = $this->lead();
        $this->assertSame('Terkualifikasi', $lead->stage->name);
        $this->assertNull($lead->survey_at, 'no H-1 reminder for a cancelled survey');
        $this->assertSame(PropertyStageAutomation::CANCELLED_TASK, $lead->next_action);
        $this->assertStringContainsString('klien membatalkan survei lewat chat (AI): ada acara keluarga', $this->lastNote());
    }

    public function test_the_cancel_tool_is_refused_when_the_client_did_not_cancel_and_is_absent_when_the_rule_is_off(): void
    {
        $this->says('Halo kak');
        $admin = $this->adminOf($this->tenant);
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::SURVEY), $admin,
            ['survey_at' => now()->addDays(3), 'survey_location' => 'Rumah contoh']));

        $this->ai = [$this->tool(PropertyTools::CANCEL), $this->text('Siap Kak, sampai ketemu Sabtu 😊')];
        $this->says('Oke kak, sampai ketemu Sabtu');
        $this->assertSame('Jadwal survei', $this->lead()->stage->name);

        $this->in(function () {
            $settings = PropertyStageAutomationSettings::of($this->tenant)->toArray();
            $settings['survey_change']['enabled'] = false;
            PropertyStageAutomationSettings::store($this->tenant, PropertyStageAutomationSettings::clean($settings));
        });
        $tools = $this->in(fn () => array_column((new PropertyTools($this->tenant->fresh(), $this->lead()))->definitions(), 'name'));
        $this->assertNotContains(PropertyTools::CANCEL, $tools);
    }

    // ---- Rule 4 -----------------------------------------------------------------------------------------------

    public function test_three_hours_after_the_survey_the_lead_is_sudah_survei_with_the_offer_task(): void
    {
        $this->says('Halo kak');
        $admin = $this->adminOf($this->tenant);
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::SURVEY), $admin,
            ['survey_at' => now()->addHour(), 'survey_location' => 'Rumah contoh']));

        $this->travel(3)->hours();
        $this->artisan('stages:automate')->assertSuccessful();
        $this->assertSame('Jadwal survei', $this->lead()->stage->name, 'only 2 hours after the survey');

        $this->travel(1)->hours();
        $this->artisan('stages:automate')->expectsOutputToContain('griya-uji: sudah survei 1, gugur tanpa respons 0')->assertSuccessful();
        $lead = $this->lead();
        $this->assertSame('Sudah survei', $lead->stage->name);
        $this->assertSame('Kirim penawaran dan simulasi KPR', $lead->next_action);
        $this->assertStringContainsString('3 jam setelah jadwal survei 16 Nov 11:00', $this->lastNote());
        $this->assertFalse(collect($this->in(fn () => WaMessage::query()->pluck('body')->all()))->contains(fn ($b) => str_contains((string) $b, 'Terima kasih sudah menyempatkan survei')));
    }

    public function test_the_thank_you_message_goes_out_when_switched_on(): void
    {
        $this->in(function () {
            $settings = PropertyStageAutomationSettings::of($this->tenant)->toArray();
            $settings['thanks']['enabled'] = true;
            PropertyStageAutomationSettings::store($this->tenant, PropertyStageAutomationSettings::clean($settings));
        });
        $this->says('Halo kak');
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::SURVEY), $this->adminOf($this->tenant),
            ['survey_at' => now()->addHour(), 'survey_location' => 'Rumah contoh']));

        $this->travel(5)->hours();
        $this->artisan('stages:automate')->assertSuccessful();

        $last = $this->in(fn () => WaMessage::query()->where('direction', 'out')->latest('id')->first());
        $this->assertStringContainsString('Terima kasih sudah menyempatkan survei, Kak', $last->body);
        $this->assertSame(PropertyStageAutomation::ORIGIN, $last->origin);
        $this->assertSame('Kirim penawaran dan simulasi KPR', $this->lead()->next_action, 'a thank-you is not the offer');
    }

    // ---- Rule 5 -----------------------------------------------------------------------------------------------

    public function test_a_silent_client_is_gugur_after_the_follow_ups_and_comes_back_when_writing_again(): void
    {
        $this->says('Halo kak');
        $this->says('Mau tanya harga');
        $this->assertSame('Dihubungi', $this->lead()->stage->name);
        $this->in(fn () => FollowUpLog::create(['follow_up_rule_id' => FollowUpRule::query()->value('id'), 'lead_id' => $this->lead()->id, 'stage_id' => $this->lead()->stage_id, 'status' => 'sent']));

        $this->travel(9)->days();
        $this->artisan('stages:automate')->assertSuccessful();
        $this->assertSame('Dihubungi', $this->lead()->stage->name);

        $this->travel(2)->days();
        $this->artisan('stages:automate')->assertSuccessful();
        $lead = $this->in(fn () => Lead::query()->with('stage', 'lostReason')->orderBy('id')->firstOrFail());
        $this->assertSame('Gugur', $lead->stage->name);
        $this->assertSame('Tidak ada respons', $lead->lostReason->label);

        $this->says('Kak maaf baru sempat balas');
        $this->assertSame('Dihubungi', $this->lead()->stage->name);
        $this->assertStringContainsString('klien yang gugur menulis lagi', $this->lastNote());
    }

    public function test_a_client_waiting_for_us_or_a_lead_never_written_to_is_not_gugur(): void
    {
        $this->aiOff();
        $this->says('Halo kak');
        $this->in(fn () => Lead::create(['name' => 'Klien Formulir', 'phone' => '089900002222', 'stage_id' => $this->step(PropertyStages::NEW)->id]));

        $this->travel(30)->days();
        $this->artisan('stages:automate')->assertSuccessful();
        $this->assertSame(['Lead baru', 'Lead baru'], $this->in(fn () => Lead::query()->with('stage')->orderBy('id')->get()->pluck('stage.name')->all()));
    }

    // ---- Rule 6 and handoff tasks -------------------------------------------------------------------------------

    public function test_a_client_ready_to_book_is_handed_to_an_agent_with_a_task_and_stays_in_its_stage(): void
    {
        $this->says('Halo kak');
        $this->says('Kak, saya mau booking unit Tulip ya');

        $lead = $this->lead();
        $this->assertSame('Dihubungi', $lead->stage->name, 'Booking stays with people');
        $this->assertSame(PropertyStageAutomation::BOOKING_TASK, $lead->next_action);
        $this->assertTrue($lead->next_action_due_at->lte(now()));
        $this->assertSame(PropertyStageAutomation::BOOKING_REASON, $this->in(fn () => AiDraft::query()->latest('id')->value('reason')));
        $this->assertTrue(PropertyStageAutomation::wantsBooking('Oke kak saya siap bayar tanda jadi'));
        $this->assertFalse(PropertyStageAutomation::wantsBooking('Booking fee-nya berapa kak?'));
    }

    public function test_any_handoff_becomes_the_agents_task_with_its_reason(): void
    {
        $this->says('Halo kak');
        $this->says('Saya mau komplain soal pelayanan kemarin');

        $this->assertSame('Tanggapi chat yang diserahkan AI: Klien meminta bicara dengan agen atau menyebut hal sensitif', $this->lead()->next_action);
    }

    // ---- Agents, catch-up, settings, retail --------------------------------------------------------------------

    public function test_an_agents_move_stands_and_leads_never_move_back(): void
    {
        $this->says('Halo kak');
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::NEGOTIATION), $this->adminOf($this->tenant)));

        $this->ai = [$this->tool(PropertyTools::NEEDS, ['tujuan' => 'hunian', 'domisili' => 'Bandung']), $this->text('Siap Kak 😊')];
        $this->says('Untuk tinggal, saya di Bandung');
        $this->says('Oke kak');

        $this->assertSame('Negosiasi', $this->lead()->stage->name);
        $this->assertSame([], $this->autoNotes());
    }

    public function test_catch_up_lists_first_and_applies_only_when_asked(): void
    {
        $this->tenant->forceFill(['stage_automation' => null])->save();
        $this->aiOff();
        $this->says('Halo kak');
        $this->phoneReply('Halo Kak');
        $this->says('Mau tanya harga');
        $this->says('Halo', '6289900005555');

        $this->artisan('stages:catch-up', ['--tenant' => 'griya-uji'])->expectsOutputToContain('belum dinyalakan')->assertSuccessful();

        PropertyStageAutomationSettings::store($this->tenant, PropertyStageAutomationSettings::defaults());
        $this->artisan('stages:catch-up', ['--tenant' => 'griya-uji'])->expectsOutputToContain('1 perubahan akan diterapkan')->assertSuccessful();
        $this->assertSame('Lead baru', $this->lead()->stage->name, 'a preview changes nothing');

        $this->artisan('stages:catch-up', ['--tenant' => 'griya-uji', '--apply' => true])->expectsOutputToContain('1 perubahan diterapkan')->assertSuccessful();
        $this->assertSame('Dihubungi', $this->lead()->stage->name);
        $this->assertSame('Lead baru', $this->in(fn () => Lead::query()->with('stage')->latest('id')->first()->stage->name), 'the client nobody answered stays');
    }

    public function test_an_existing_agency_sees_the_rules_off_and_switches_them_on(): void
    {
        $this->tenant->forceFill(['stage_automation' => null])->save();
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $this->assertTrue(StageAutomationPage::canAccess());

        Livewire::test(StageAutomationPage::class)
            ->assertSee('belum menyala')
            ->assertSet('data.contacted.enabled', false)
            ->callAction('start')
            ->assertSet('data.contacted.enabled', true)
            ->fillForm(['silent.days_new' => 21, 'thanks.enabled' => true, 'thanks.message' => 'Terima kasih Kak {nama}'])
            ->call('save')->assertHasNoFormErrors();

        $settings = PropertyStageAutomationSettings::of($this->tenant->fresh());
        $this->assertSame(21, $settings->number('silent', 'days_new'));
        $this->assertTrue($settings->enabled('thanks'));
        $this->assertSame('Terima kasih Kak {nama}', $settings->thanksMessage());

        Livewire::test(StageAutomationPage::class)->callAction('catchUp')->assertHasNoActionErrors();
    }

    public function test_shops_and_property_agencies_never_run_each_others_rules(): void
    {
        $shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
        $lost = $this->in(fn () => Stage::query()->where('type', 'lost')->first(), $shop);
        $lead = $this->in(fn () => Lead::create(['name' => 'Pembeli Uji', 'phone' => '089900003333', 'stage_id' => $lost->id]), $shop);

        $this->in(fn () => app(PropertyStageAutomation::class)->inbound($lead, new WaMessage(['type' => 'text'])), $shop);
        $this->assertSame($lost->id, $this->in(fn () => $lead->fresh()->stage_id, $shop));
        $this->assertSame(['surveyed' => 0, 'silent' => 0], app(PropertyStageAutomation::class)->runScheduled($shop));
        $this->assertArrayNotHasKey(PropertyStageAutomationSettings::KEY, (array) $shop->fresh()->stage_automation);

        // The shop rules still see nothing in a property agency.
        $this->assertSame(['done' => 0, 'no_reply' => 0, 'no_payment' => 0], app(StageAutomation::class)->runScheduled($this->tenant));
    }
}
