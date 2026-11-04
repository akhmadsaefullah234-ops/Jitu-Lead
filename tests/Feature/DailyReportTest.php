<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\DailyReports;
use App\Mail\DailyReportMail;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ReportDelivery;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Reports\DailyReport;
use App\Reports\DailyReportSender;
use App\Reports\DashboardData;
use App\Reports\ReportSettings;
use App\Reports\WhatsAppVerification;
use App\Support\CurrentTenant;
use App\Support\Period;
use App\WhatsApp\GatewayUrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private User $owner;

    private WaChannel $platform;

    /** @var list<array{url: string, to: ?string, text: ?string}> */
    private array $outbox = [];

    private bool $refuse = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 5 November 2026, 07:00 WIB: the default send time; "Kemarin" is Wednesday 4 November.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 07:00:00', 'Asia/Jakarta'));
        Sleep::fake();
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Mail::fake();
        Http::fake(['*' => function (Request $request) {
            if ($this->refuse) {
                return Http::response(['error' => 'Gateway mati'], 503);
            }
            $this->outbox[] = ['url' => $request->url(), 'to' => $request['to'] ?? null, 'text' => $request['text'] ?? null];

            return Http::response(['id' => 'm'.count($this->outbox)]);
        }]);

        // The platform's own agency and the number reports are sent from.
        $platformOwner = User::factory()->create();
        $platform = app(ProvisionTenant::class)('JITU LEAD', 'jitu', $platformOwner);
        $this->membership($platform, $platformOwner)->forceFill(['report_settings' => ['enabled' => false]])->save();
        config(['jitu.platform_sales_tenant' => 'jitu']);
        $this->platform = app(CurrentTenant::class)->run($platform, fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Laporan', 'phone' => '6281100000000', 'status' => WaChannelStatus::Connected,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        config(['jitu.reports.channel' => $this->platform->getKey()]);

        $this->owner = $this->verifiedUser('6281211112222');
        $this->shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', $this->owner, 'retail');
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function verifiedUser(string $wa): User
    {
        $user = User::factory()->create();
        $user->forceFill(['whatsapp' => $wa, 'whatsapp_verified_number' => $wa, 'whatsapp_verified_at' => now()])->save();

        return $user;
    }

    private function membership(Tenant $tenant, User $user): Membership
    {
        return Membership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->firstOrFail();
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    private function leadAt(Tenant $tenant, string $at, array $attributes = []): Lead
    {
        $stage = Stage::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->orderBy('position')->first();
        $lead = $this->in($tenant, fn () => Lead::create($attributes + [
            'name' => 'Budi Pembeli Rahasia', 'phone' => '0812'.random_int(10000000, 99999999),
            'stage_id' => $stage->getKey(), 'owner_id' => $this->adminOf($tenant)->getKey(),
        ]));
        $lead->forceFill(['created_at' => CarbonImmutable::parse($at, 'Asia/Jakarta')])->saveQuietly();

        return $lead;
    }

    private function moveAt(Lead $lead, Stage $stage, string $at): void
    {
        $when = CarbonImmutable::parse($at, 'Asia/Jakarta');
        $from = $lead->stage_id;
        $lead->forceFill(['stage_id' => $stage->getKey(), 'stage_entered_at' => $when])->saveQuietly();
        $this->in(Tenant::find($lead->tenant_id), function () use ($lead, $from, $stage, $when) {
            $a = $lead->activities()->create(['type' => 'stage_changed', 'body' => 'Pindah', 'meta' => ['from' => $from, 'to' => $stage->getKey()]]);
            $a->forceFill(['created_at' => $when])->saveQuietly();
        });
    }

    private function retail(string $key): Stage
    {
        return $this->in($this->shop, fn () => app(RetailStages::class)->stage($key));
    }

    private function text(Tenant $tenant, User $user, string $kind = 'daily'): string
    {
        Cache::flush();

        return app(DailyReport::class)->build($tenant, $user, $kind)['text'];
    }

    private function runAt(string $wib): array
    {
        $this->travelTo(CarbonImmutable::parse($wib, 'Asia/Jakarta'));

        return app(DailyReportSender::class)->run();
    }

    private function rows(?User $user = null)
    {
        return ReportDelivery::query()->where('user_id', ($user ?? $this->owner)->getKey())->get();
    }

    public function test_the_report_shows_the_dashboard_numbers_for_yesterday(): void
    {
        $kaos = $this->in($this->shop, fn () => Property::create(['name' => 'Kaos Hitam', 'price_from' => 100_000, 'status' => 'available']));
        $form = $this->in($this->shop, fn () => LeadSource::create(['name' => 'Formulir order', 'type' => 'form']));

        $a = $this->leadAt($this->shop, '2026-11-04 09:00', ['property_id' => $kaos->getKey(), 'ad_id' => '120001', 'ad_title' => 'Promo Kaos', 'quantity' => 2]);
        $b = $this->leadAt($this->shop, '2026-11-04 23:30', ['property_id' => $kaos->getKey(), 'lead_source_id' => $form->getKey()]);
        $this->leadAt($this->shop, '2026-11-04 12:00');
        $this->leadAt($this->shop, '2026-11-03 10:00');           // the day before: the comparison
        $this->leadAt($this->shop, '2026-11-05 06:00');           // today: not in the report
        $this->moveAt($a, $this->retail(RetailStages::ORDER), '2026-11-04 10:00');
        $this->moveAt($a, $this->retail(RetailStages::SHIPPING), '2026-11-04 11:00');
        $this->moveAt($b, $this->retail(RetailStages::CANCELLED), '2026-11-04 23:50');

        $this->actingInTenant($this->owner, $this->shop);
        Cache::flush();
        $dashboard = app(DashboardData::class)->summary(Period::make('yesterday'));
        $text = $this->text($this->shop, $this->owner);

        $this->assertSame(3, $dashboard['new']['now']);
        $this->assertStringContainsString("*Lead baru: {$dashboard['new']['now']}* (hari sebelumnya {$dashboard['new']['before']}, naik 2)", $text);
        $this->assertStringContainsString("• Order masuk: {$dashboard['orders']['now']}", $text);
        $this->assertStringContainsString("• Dibayar / dikirim: {$dashboard['paid']['now']}", $text);
        $this->assertStringContainsString("• Batal: {$dashboard['cancelled']['now']}", $text);
        $this->assertSame(200_000, $dashboard['sales']['now']);
        $this->assertStringContainsString('• Nilai penjualan: *Rp 200.000*', $text);
        $this->assertStringContainsString('• Iklan CTWA: 1', $text);
        $this->assertStringContainsString('• Formulir / landing: 1', $text);
        $this->assertStringContainsString('• Kaos Hitam: 2', $text);
        $this->assertStringContainsString('• Lainnya: 1', $text);
        $this->assertStringContainsString('• Iklan terbaik: "Promo Kaos" (1 order, 1 lead)', $text);
        $this->assertStringNotContainsString('Selesai:', $text, 'Zero lines are left out.');
        $this->assertStringContainsString('Kemarin, Rabu 4 November 2026', $text);
        $this->assertStringContainsString('/app/toko-uji?periode=yesterday', $text);
        $this->assertStringNotContainsString('Budi', $text, 'No buyer names.');
        $this->assertDoesNotMatchRegularExpression('/0812\d{8}/', $text, 'No buyer numbers.');
    }

    public function test_property_and_retail_reports_show_their_own_lines(): void
    {
        $agent = User::factory()->create();
        $agency = app(ProvisionTenant::class)('Griya Prima', 'griya-prima', $agent);
        $this->leadAt($agency, '2026-11-04 09:00', ['survey_at' => CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Jakarta')]);
        $this->leadAt($this->shop, '2026-11-04 09:00');
        $this->moveAt($this->leadAt($this->shop, '2026-11-04 09:30'), $this->retail(RetailStages::ORDER), '2026-11-04 10:00');

        $property = $this->text($agency, $agent);
        $this->assertStringContainsString('🏠 *Properti*', $property);
        $this->assertStringContainsString('• Survei terjadwal: 1', $property);
        $this->assertStringNotContainsString('Penjualan', $property);
        $this->assertStringNotContainsString('Order masuk', $property);

        $shop = $this->text($this->shop, $this->owner);
        $this->assertStringContainsString('🛒 *Penjualan*', $shop);
        $this->assertStringContainsString('• Order masuk: 1', $shop);
        $this->assertStringNotContainsString('Survei', $shop);
    }

    public function test_an_agent_only_gets_their_own_leads_and_is_off_by_default(): void
    {
        $agent = $this->member($this->shop, Role::Agent);
        $this->leadAt($this->shop, '2026-11-04 09:00', ['owner_id' => $agent->getKey()]);
        $this->leadAt($this->shop, '2026-11-04 10:00');
        $this->leadAt($this->shop, '2026-11-04 11:00');

        $this->assertFalse(ReportSettings::member($this->membership($this->shop, $agent))['enabled']);
        $this->assertTrue(ReportSettings::member($this->membership($this->shop, $this->owner))['enabled']);

        $mine = $this->text($this->shop, $agent);
        $this->assertStringContainsString('*Lead baru: 1*', $mine);
        $this->assertStringContainsString('lead milik Anda', $mine);
        $this->assertStringContainsString('*Lead baru: 3*', $this->text($this->shop, $this->owner));

        // Off by default: only the owner is sent one; once switched on, the agent too.
        $this->runAt('2026-11-05 07:30');
        $this->assertCount(0, $this->rows($agent));
        $this->assertCount(1, $this->rows());

        ReportSettings::saveMember($this->membership($this->shop, $agent), ['enabled' => true]);
        $this->runAt('2026-11-05 07:40');
        $this->assertCount(1, $this->rows($agent));
    }

    public function test_send_time_follows_the_agency_time_zone_and_is_sent_once(): void
    {
        $this->shop->update(['timezone' => 'Asia/Makassar']); // WITA, one hour ahead of WIB

        $this->runAt('2026-11-05 05:55'); // 06:55 WITA
        $this->assertCount(0, $this->rows());

        config(['jitu.reports.spread_minutes' => 0]);
        $counts = $this->runAt('2026-11-05 06:00'); // 07:00 WITA
        $this->assertSame(1, $counts['queued']);
        $row = $this->rows()->sole();
        $this->assertSame('2026-11-05', $row->report_date->toDateString());
        $this->assertSame(ReportDelivery::SENT, $row->status);
        $this->assertCount(1, $this->outbox);
        $this->assertSame('6281211112222', $this->outbox[0]['to']);
        $this->assertStringContainsString('Kemarin, Rabu 4 November 2026', $this->outbox[0]['text']);

        // Every later run that day: nothing new.
        $this->runAt('2026-11-05 06:05');
        $this->runAt('2026-11-05 08:00');
        $this->assertCount(1, $this->rows());
        $this->assertCount(1, $this->outbox);

        // "Kemarin" in WITA: a lead at 23:30 WIB on the 4th is already the 5th there.
        $this->leadAt($this->shop, '2026-11-04 23:30');
        $this->assertStringContainsString('*Lead baru: 0*', $this->text($this->shop, $this->owner));
    }

    public function test_reports_are_spread_over_a_few_minutes(): void
    {
        $others = collect(range(1, 6))->map(function () {
            $u = $this->verifiedUser('62812'.random_int(10000000, 99999999));
            $this->shop->users()->attach($u, ['role' => Role::Admin->value, 'status' => 'active']);

            return $u;
        });

        app(DailyReportSender::class)->queue(CarbonImmutable::now());
        $due = ReportDelivery::query()->where('tenant_id', $this->shop->getKey())->pluck('due_at');

        $this->assertCount(7, $due);
        $this->assertTrue($due->every(fn ($d) => $d->between(now(), now()->addMinutes(10))));
        $this->assertGreaterThan(1, $due->map->format('H:i:s')->unique()->count(), 'Not all at the same moment.');
        $this->assertNotEmpty($others);
    }

    public function test_platform_number_down_or_failing_sends_email_instead(): void
    {
        config(['jitu.reports.spread_minutes' => 0]);
        $this->platform->forceFill(['status' => WaChannelStatus::Disconnected])->save();

        $this->runAt('2026-11-05 07:05');

        $row = $this->rows()->sole();
        $this->assertSame(ReportDelivery::EMAIL, $row->status);
        $this->assertStringContainsString('terputus', $row->error);
        $this->assertCount(0, $this->outbox);
        Mail::assertSent(DailyReportMail::class, fn (DailyReportMail $m) => $m->hasTo($this->owner->email)
            && str_contains($m->text, '*Lead baru: 0*') && str_contains($m->subjectLine, 'Toko Uji'));

        // Connected, but the send fails: email too, with the reason kept.
        $this->platform->forceFill(['status' => WaChannelStatus::Connected])->save();
        $this->refuse = true;
        $sample = app(DailyReportSender::class)->sample($this->shop, $this->owner);
        $this->assertSame(ReportDelivery::EMAIL, $sample->status);
        $this->assertStringContainsString('Gateway mati', $sample->error);
        Mail::assertSentCount(2);
    }

    public function test_an_unverified_number_gets_the_report_by_email(): void
    {
        config(['jitu.reports.spread_minutes' => 0]);
        $this->owner->forceFill(['whatsapp' => '6281299998888'])->save(); // changed after it was verified
        $this->assertFalse($this->owner->hasVerifiedWhatsapp());

        $this->runAt('2026-11-05 07:05');

        $this->assertSame(ReportDelivery::EMAIL, $this->rows()->sole()->status);
        $this->assertCount(0, $this->outbox);
    }

    public function test_locked_or_expired_agencies_are_skipped(): void
    {
        $this->shop->currentSubscription()->update(['status' => Subscription::TRIAL, 'trial_ends_at' => now()->subDays(30), 'current_period_end' => null]);
        $this->runAt('2026-11-05 07:30');
        $this->assertCount(0, $this->rows());

        // A paid plan that ran out: also nothing.
        $this->shop->currentSubscription()->update(['status' => Subscription::READ_ONLY, 'plan' => 'tim', 'current_period_end' => now()->subDays(5)]);
        $this->runAt('2026-11-05 07:35');
        $this->assertCount(0, $this->rows());

        // Paid again: sent.
        $this->shop->currentSubscription()->update(['status' => Subscription::ACTIVE, 'current_period_end' => now()->addMonth()]);
        $this->runAt('2026-11-05 07:40');
        $this->assertCount(1, $this->rows());
    }

    public function test_days_weekly_and_monthly(): void
    {
        config(['jitu.reports.spread_minutes' => 0]);
        $this->shop->currentSubscription()->update(['status' => Subscription::ACTIVE, 'plan' => 'tim', 'current_period_end' => now()->addYear()]);
        ReportSettings::saveAgency($this->shop, ['time' => '08:00', 'days' => 'mon_sat']);
        ReportSettings::saveMember($this->membership($this->shop, $this->owner), ['enabled' => true, 'weekly' => true, 'monthly' => true]);

        $this->runAt('2026-11-05 07:30');
        $this->assertCount(0, $this->rows(), 'Not yet 08:00.');

        // Sunday 8 November: Senin–Sabtu, so nothing.
        $this->runAt('2026-11-08 08:10');
        $this->assertCount(0, $this->rows());

        // Monday 9 November: daily and the weekly summary of 2–8 November.
        $this->runAt('2026-11-09 08:10');
        $this->assertEqualsCanonicalizing(['daily', 'weekly'], $this->rows()->pluck('kind')->all());
        $this->assertStringContainsString('*Ringkasan mingguan Toko Uji*', $this->outbox[1]['text'] ?? $this->outbox[0]['text']);
        $weekly = $this->rows()->firstWhere('kind', 'weekly');
        $this->assertSame('2 Nov – 8 Nov 2026', $weekly->period_label);

        // Tuesday 1 December: daily and last month.
        $this->runAt('2026-12-01 08:10');
        $this->assertSame('Bulan lalu', $this->rows()->firstWhere('kind', 'monthly')->period_label);

        // The member's own time wins over the agency's.
        ReportSettings::saveMember($this->membership($this->shop, $this->owner), ['enabled' => true, 'time' => '06:00']);
        $this->runAt('2026-12-02 06:05');
        $this->assertSame(1, $this->rows()->where('report_date', CarbonImmutable::parse('2026-12-02'))->count());
    }

    public function test_reports_are_rate_limited(): void
    {
        config(['jitu.reports.spread_minutes' => 0, 'jitu.reports.per_minute' => 2]);
        collect(range(1, 9))->each(function () {
            $u = $this->verifiedUser('62812'.random_int(10000000, 99999999));
            $this->shop->users()->attach($u, ['role' => Role::Admin->value, 'status' => 'active']);
        });

        $counts = $this->runAt('2026-11-05 07:05');

        $this->assertSame(10, $counts['queued']);
        $this->assertSame(8, $counts['sent'], 'At most 4 minutes of sending per run.');
        Sleep::assertSleptTimes(7);
        Sleep::assertSlept(fn ($d) => (int) $d->totalMilliseconds === 30_000, 7);

        $this->runAt('2026-11-05 07:10');
        $this->assertCount(10, $this->outbox);
    }

    public function test_whatsapp_number_verification(): void
    {
        $user = User::factory()->create(['email' => 'pemilik@example.com']);
        $verify = app(WhatsAppVerification::class);

        $this->assertNotNull($verify->send($user, '12345'), 'Not an Indonesian mobile number.');
        $this->assertNull($verify->send($user, '0813 1111 2222'));
        $this->assertSame('6281311112222', $this->outbox[0]['to']);
        $this->assertStringStartsWith('https://gw.example.com', $this->outbox[0]['url']);
        preg_match('/\*(\d{6})\*/', $this->outbox[0]['text'], $m);
        $code = $m[1];

        $this->assertSame('Kode salah.', $verify->verify($user, $code === '000000' ? '111111' : '000000'));
        $this->assertFalse($user->refresh()->hasVerifiedWhatsapp());
        $this->assertNull($verify->verify($user, $code));
        $this->assertTrue($user->refresh()->hasVerifiedWhatsapp());
        $this->assertSame('6281311112222', $user->whatsapp);
        $this->assertNotNull($verify->verify($user, $code), 'A code works once.');

        // Five wrong tries end the code.
        $verify->send($user, '0813 3333 4444');
        foreach (range(1, 5) as $i) {
            $verify->verify($user, 'x');
        }
        $this->assertNull($verify->pending($user));
        $this->assertSame('6281311112222', $user->refresh()->whatsapp, 'The old number stays until a new one is verified.');

        // Three codes per 10 minutes.
        $this->assertNull($verify->send($user, '0813 3333 4444'));
        $this->assertStringContainsString('Terlalu sering', (string) $verify->send($user, '0813 3333 4444'));

        // Platform number down: no code, and it says the report goes by email.
        $other = User::factory()->create();
        $this->platform->forceFill(['status' => WaChannelStatus::NeedsRelink])->save();
        $this->assertStringContainsString('email', (string) $verify->send($other, '0813 5555 6666'));
    }

    public function test_settings_page_sample_and_history(): void
    {
        $this->actingInTenant($this->owner, $this->shop);

        Livewire::test(DailyReports::class)
            ->assertOk()
            ->assertSee('Terverifikasi')
            ->assertSee('Bawaan agensi')
            ->set('mine.weekly', true)
            ->set('agency.time', '06:30')
            ->call('save')
            ->callAction('sample')
            ->assertSee('Contoh')
            ->assertSee('Terkirim lewat WhatsApp');

        $this->assertTrue(ReportSettings::member($this->membership($this->shop, $this->owner))['weekly']);
        $this->assertSame('06:30', ReportSettings::agency($this->shop->refresh())['time']);
        $this->assertSame(ReportDelivery::SAMPLE, $this->rows()->sole()->kind);
        $this->assertStringContainsString('Laporan harian Toko Uji', $this->outbox[0]['text']);

        // Agents see their own settings and history, not the agency's defaults.
        $agent = $this->member($this->shop, Role::Agent);
        $this->actingInTenant($agent, $this->shop);
        Livewire::test(DailyReports::class)->assertOk()->assertDontSee('Bawaan agensi')->assertSee('Belum terverifikasi')
            ->assertSee('Belum ada laporan terkirim');
    }

    public function test_email_text_keeps_the_whatsapp_formatting(): void
    {
        $md = DailyReportMail::toMarkdown("📊 *Laporan harian*\n_Per produk:_\n• A & B: 2\n\n🔗 Dasbor: https://x");

        $this->assertStringContainsString('**Laporan harian**', $md);
        $this->assertStringContainsString('*Per produk:*', $md);
        $this->assertStringContainsString('A &amp; B', $md);
        $this->assertStringNotContainsString('https://x', $md, 'The link is the button.');
    }
}
