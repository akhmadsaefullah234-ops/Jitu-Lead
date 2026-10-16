<?php

namespace Tests\Feature;

use App\Billing\Subscriptions;
use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignRunner;
use App\Enums\Role;
use App\Filament\Admin\Resources\EmailCampaigns\Pages\ManageEmailCampaigns;
use App\Jobs\SendCampaignEmail;
use App\Mail\TrialEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Models\Tenant;
use App\Models\TrialEmailLog;
use App\Models\User;
use App\Retention\DailyEmailQuota;
use App\Retention\TrialEmails;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class EmailCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.jitulead.test', 'mail.from.address' => 'halo@jitulead.com', 'jitu.notify_signups' => false]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Asia/Jakarta'));
    }

    /** An agency whose admin agreed to be contacted. */
    private function agency(string $slug, bool $consent = true): Tenant
    {
        $admin = User::factory()->create(['name' => 'Admin '.$slug, 'email' => "$slug@example.com"]);
        $admin->forceFill(['contact_consent_at' => $consent ? now() : null])->save();

        return $this->makeTenant($slug, $admin);
    }

    private function campaign(array $attributes = []): EmailCampaign
    {
        return EmailCampaign::create($attributes + [
            'name' => 'Promo November',
            'segment' => 'trial_active',
            'steps' => [
                ['subject' => 'Halo {nama}', 'body' => 'Promo untuk **{agensi}**.', 'button' => 'Lihat promo', 'url' => 'https://jitulead.com/promo'],
                ['subject' => 'Pengingat promo', 'body' => 'Promo hampir habis.', 'button' => null, 'url' => null],
            ],
            'interval_days' => 2,
            'starts_at' => CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Jakarta'),
            'stop_when_subscribed' => true,
            'status' => EmailCampaign::RUNNING,
        ]);
    }

    private function runAt(string $when): array
    {
        $this->travelTo(CarbonImmutable::parse($when, 'Asia/Jakarta'));

        return app(CampaignRunner::class)->run();
    }

    /** @return list<string> "step:email" of every campaign email sent */
    private function sent(): array
    {
        return Mail::sent(TrialEmail::class)->map(fn (TrialEmail $m) => substr($m->step, strrpos($m->step, '-') + 1).':'.$m->to[0]['address'])->sort()->values()->all();
    }

    // --- audience ---

    public function test_segments_only_include_people_who_agreed_and_can_be_reached(): void
    {
        $trial = $this->agency('trial-aktif');
        $this->agency('tanpa-izin', consent: false);
        $unverified = $this->agency('belum-verifikasi');
        $this->adminOf($unverified)->forceFill(['email_verified_at' => null])->save();
        $gone = $this->agency('berhenti');
        $this->adminOf($gone)->forceFill(['trial_emails_unsubscribed_at' => now()])->save();

        $expired = $this->agency('trial-habis');
        $expired->currentSubscription()->update(['trial_ends_at' => now()->subDay()]);

        $paying = $this->agency('pelanggan');
        app(Subscriptions::class)->activate($paying, 'tim', 'monthly', 1);

        $agent = $this->member($trial, Role::Agent);
        $agent->forceFill(['contact_consent_at' => now()])->save();

        $emails = fn (string $segment) => CampaignAudience::query($segment)->orderBy('email')->pluck('email')->all();

        $this->assertSame(['trial-aktif@example.com'], $emails('trial_active'));
        $this->assertSame(['trial-habis@example.com'], $emails('trial_expired'));
        $this->assertSame(['pelanggan@example.com'], $emails('subscribers'));
        $this->assertSame(collect([$agent->email, 'pelanggan@example.com', 'trial-aktif@example.com', 'trial-habis@example.com'])->sort()->values()->all(), $emails('all'));
        $this->assertSame(1, CampaignAudience::count('trial_active'));
    }

    // --- sending ---

    public function test_the_series_starts_on_time_follows_the_interval_and_never_repeats(): void
    {
        $this->agency('griya-satu');
        $this->agency('griya-dua');
        $campaign = $this->campaign();

        $this->assertSame(0, $this->runAt('2026-11-02 08:50')['queued'], 'not before the start time');
        $this->assertSame(2, $this->runAt('2026-11-02 09:00')['queued']);
        $this->assertSame(0, $this->runAt('2026-11-02 09:10')['queued'], 'a second run sends nothing again');
        $this->assertSame(0, $this->runAt('2026-11-03 09:10')['queued'], 'the second email waits for the interval');
        $last = $this->runAt('2026-11-04 09:10');
        $this->assertSame(2, $last['queued']);
        $this->assertSame(1, $last['finished'], 'nothing left to send');

        $this->assertSame(['1:griya-dua@example.com', '1:griya-satu@example.com', '2:griya-dua@example.com', '2:griya-satu@example.com'], $this->sent());
        $this->assertSame(EmailCampaign::FINISHED, $campaign->fresh()->status);
        $this->assertSame(['sent' => 4, 'failed' => 0, 'unsubscribed' => 0, 'waiting' => 0], $campaign->fresh()->stats());
    }

    public function test_the_email_is_filled_in_branded_and_carries_the_campaign_unsubscribe_link(): void
    {
        $this->agency('griya-satu');
        $this->campaign();
        $this->runAt('2026-11-02 09:00');

        $mail = Mail::sent(TrialEmail::class)->first();
        $this->assertSame('Halo Admin griya-satu', $mail->subjectLine);
        $this->assertSame('Promo untuk **Griya Satu**.', $mail->body);
        $this->assertSame('noreply@jitulead.com', $mail->envelope()->from->address);
        $this->assertStringContainsString('kampanye=', $mail->unsubscribeUrl);
        $mail->assertSeeInHtml('https://app.jitulead.test/images/email-logo.png', false);
        $mail->assertSeeInHtml('Lihat promo');
        $mail->assertSeeInHtml('https://jitulead.com/promo', false);
        $mail->assertSeeInHtml('Berhenti menerima email ini');
        $mail->assertSeeInHtml('bersedia dihubungi tentang akun dan penawaran');
    }

    public function test_the_audience_is_fixed_at_the_start(): void
    {
        $this->agency('griya-satu');
        $this->campaign();
        $this->runAt('2026-11-02 09:00');

        $this->agency('griya-baru');
        $this->runAt('2026-11-04 09:10');

        $this->assertSame(['1:griya-satu@example.com', '2:griya-satu@example.com'], $this->sent());
    }

    public function test_it_stops_for_people_who_subscribed_or_unsubscribed(): void
    {
        $paid = $this->agency('griya-bayar');
        $quit = $this->agency('griya-berhenti');
        $this->agency('griya-tetap');
        $campaign = $this->campaign();
        $this->runAt('2026-11-02 09:00');

        app(Subscriptions::class)->activate($paid, 'tim', 'monthly', 1);
        $this->post(TrialEmails::unsubscribeUrl($this->adminOf($quit), $campaign->getKey()))->assertOk();

        $this->runAt('2026-11-04 09:10');

        $this->assertSame(['1:griya-bayar@example.com', '1:griya-berhenti@example.com', '1:griya-tetap@example.com', '2:griya-tetap@example.com'], $this->sent());
        $this->assertSame(1, $campaign->fresh()->stats()['unsubscribed']);
        $this->assertSame('Sudah berlangganan', $campaign->sends()->where('step', 1)->where('user_id', $this->adminOf($paid)->getKey())->value('error'));
    }

    public function test_without_the_option_subscribers_keep_getting_the_series(): void
    {
        $paid = $this->agency('griya-bayar');
        $this->campaign(['stop_when_subscribed' => false]);
        $this->runAt('2026-11-02 09:00');
        app(Subscriptions::class)->activate($paid, 'tim', 'monthly', 1);
        $this->runAt('2026-11-04 09:10');

        $this->assertSame(['1:griya-bayar@example.com', '2:griya-bayar@example.com'], $this->sent());
    }

    public function test_pause_and_stop(): void
    {
        $this->agency('griya-satu');
        $campaign = $this->campaign();
        $this->runAt('2026-11-02 09:00');

        $campaign->update(['status' => EmailCampaign::PAUSED]);
        $this->assertSame(0, $this->runAt('2026-11-04 09:10')['queued']);

        $campaign->update(['status' => EmailCampaign::RUNNING]);
        $this->assertSame(1, $this->runAt('2026-11-04 09:20')['queued'], 'resumes where it was');

        $other = $this->campaign(['name' => 'Kedua']);
        $other->sends()->create(['step' => 0, 'user_id' => User::query()->first()->getKey(), 'status' => EmailCampaignSend::PENDING]);
        CampaignRunner::stop($other);
        $this->assertSame(0, $this->runAt('2026-11-04 09:30')['queued']);
        $this->assertSame(EmailCampaignSend::CANCELLED, $other->sends()->value('status'));
    }

    public function test_the_daily_cap_is_shared_with_the_trial_emails(): void
    {
        config(['trial_emails.daily_limit' => 3]);
        foreach (['satu', 'dua', 'tiga'] as $slug) {
            $this->agency("griya-$slug");
        }
        $tenant = Tenant::query()->first();
        TrialEmailLog::create(['tenant_id' => $tenant->getKey(), 'step' => 'connect_whatsapp', 'anchor' => now()->toDateString(), 'recipients' => 1]);
        $this->campaign();

        $first = $this->runAt('2026-11-02 09:00');
        $this->assertSame(2, $first['queued']);
        $this->assertTrue($first['limited']);
        $this->assertSame(0, DailyEmailQuota::remaining());

        $this->assertSame(1, $this->runAt('2026-11-03 09:00')['queued'], 'the rest goes the next day');
        $this->assertSame(2, DailyEmailQuota::remaining());
    }

    public function test_a_failed_send_is_counted(): void
    {
        $this->agency('griya-satu');
        $campaign = $this->campaign();
        $send = $campaign->sends()->create(['step' => 0, 'user_id' => User::query()->first()->getKey(), 'status' => EmailCampaignSend::QUEUED, 'queued_at' => now()]);

        (new SendCampaignEmail($send->getKey()))->failed(new RuntimeException('Resend: rate limit'));

        $this->assertSame(1, $campaign->fresh()->stats()['failed']);
        $this->assertSame('Resend: rate limit', $send->fresh()->error);
    }

    public function test_the_command_and_its_schedule(): void
    {
        $this->agency('griya-satu');
        $this->campaign();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Jakarta'));

        $this->artisan('campaigns:send')->expectsOutputToContain('Email kampanye antre: 1')->assertSuccessful();
        $this->artisan('schedule:list')->expectsOutputToContain('campaigns:send');
    }

    // --- admin screen ---

    private function actAsOwner(): User
    {
        $owner = User::factory()->create(['email' => 'pemilik@jitulead.com']);
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $owner;
    }

    public function test_the_owner_creates_tests_runs_pauses_and_stops_a_campaign(): void
    {
        $this->agency('griya-satu');
        $this->actAsOwner();

        Livewire::test(ManageEmailCampaigns::class)
            ->callAction('create', [
                'name' => 'Diskon akhir tahun',
                'segment' => 'trial_active',
                'starts_at' => '2026-11-05 10:00:00',
                'interval_days' => 3,
                'stop_when_subscribed' => true,
                'steps' => [['subject' => 'Diskon 20%', 'body' => 'Pakai kode **HEMAT20**', 'button' => 'Pilih paket', 'url' => 'https://jitulead.com/harga']],
            ])
            ->assertHasNoActionErrors();

        $campaign = EmailCampaign::query()->firstOrFail();
        $this->assertSame(EmailCampaign::DRAFT, $campaign->status);
        $this->assertSame('2026-11-05 10:00', $campaign->starts_at->timezone('Asia/Jakarta')->format('Y-m-d H:i'));
        $this->assertCount(1, $campaign->emails());

        $page = Livewire::test(ManageEmailCampaigns::class)
            ->assertSee('Diskon akhir tahun')->assertSee('Perkiraan 1 orang');

        $page->callTableAction('test', $campaign);
        Mail::assertSent(TrialEmail::class, fn (TrialEmail $m) => $m->hasTo('pemilik@jitulead.com') && $m->subjectLine === '[UJI] Diskon 20%');
        $this->assertSame(0, $campaign->sends()->count(), 'a test send is not part of the campaign');

        $page->callTableAction('run', $campaign);
        $this->assertSame(EmailCampaign::RUNNING, $campaign->fresh()->status);
        $page->callTableAction('pause', $campaign);
        $this->assertSame(EmailCampaign::PAUSED, $campaign->fresh()->status);
        $page->callTableAction('stop', $campaign);
        $this->assertSame(EmailCampaign::STOPPED, $campaign->fresh()->status);
    }

    public function test_a_series_has_one_to_five_emails_and_a_button_needs_a_link(): void
    {
        $this->actAsOwner();
        $step = ['subject' => 'S', 'body' => 'B', 'button' => null, 'url' => null];
        $base = ['name' => 'X', 'segment' => 'all', 'starts_at' => '2026-11-05 10:00:00', 'interval_days' => 2, 'stop_when_subscribed' => false];

        Livewire::test(ManageEmailCampaigns::class)->callAction('create', $base + ['steps' => array_fill(0, 6, $step)])->assertHasActionErrors(['steps']);
        Livewire::test(ManageEmailCampaigns::class)->callAction('create', $base + ['steps' => [['button' => 'Klik', 'url' => null] + $step]])->assertHasActionErrors();
        $this->assertSame(0, EmailCampaign::query()->count());
    }

    public function test_only_super_admins_open_the_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/email-campaigns')->assertForbidden();

        $this->actAsOwner();
        $this->get('/admin/email-campaigns')->assertOk()->assertSee('Kampanye email');
    }
}
