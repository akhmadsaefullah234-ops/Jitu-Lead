<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Billing\Subscriptions;
use App\Enums\Role;
use App\Enums\StageType;
use App\Filament\Admin\Pages\TrialEmailSettings as TrialEmailSettingsPage;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Tenancy\RegisterAgency;
use App\Filament\Widgets\WhatsAppPrompt;
use App\Mail\SubscriptionEnding;
use App\Mail\TrialEmail;
use App\Models\Lead;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TrialEmailLog;
use App\Models\User;
use App\Retention\PlatformLeads;
use App\Retention\TrialEmails;
use App\Retention\TrialEmailSettings;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TrialRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.jitulead.test', 'mail.from.address' => 'halo@jitulead.com', 'jitu.notify_signups' => false]);
        Mail::fake();
    }

    /** An agency that signed up on 1 October 2026 at 10:00 WIB; its 14-day trial ends on the 15th. */
    private function agencyOnTrial(?User $admin = null): Tenant
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Jakarta'));
        $tenant = $this->makeTenant('griya-baru', $admin ?? User::factory()->create(['name' => 'Budi', 'email' => 'budi@example.com']));
        $this->travelBack();

        return $tenant;
    }

    /** Runs the daily job as the scheduler would, at 09:00 WIB on that day of October 2026. */
    private function runOn(int $day): array
    {
        $this->travelTo(CarbonImmutable::parse(sprintf('2026-10-%02d 09:00', $day), 'Asia/Jakarta'));

        return app(TrialEmails::class)->run();
    }

    /** @return list<string> steps queued so far, in order */
    private function queuedSteps(): array
    {
        return Mail::queued(TrialEmail::class)->map(fn (TrialEmail $m) => $m->step)->values()->all();
    }

    private function salesTenant(): Tenant
    {
        $sales = $this->makeTenant('jitu-sales');
        config(['jitu.platform_sales_tenant' => 'jitu-sales']);

        return $sales;
    }

    private function salesLead(Tenant $agency): Lead
    {
        return Lead::withoutGlobalScopes()->with('stage')->findOrFail($agency->fresh()->sales_lead_id);
    }

    // --- 1. sign-up asks for WhatsApp and consent ---

    private function signUp(array $extra = [])
    {
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        config(['jitu.registration' => 'open']);

        return Livewire::test(Register::class)->fillForm($extra + [
            'name' => 'Sari', 'email' => 'sari@example.com', 'whatsapp' => '0812-3456-7890',
            'password' => 'rahasia-123-ok', 'passwordConfirmation' => 'rahasia-123-ok',
        ])->call('register');
    }

    public function test_sign_up_requires_an_indonesian_whatsapp_number_and_stores_it_normalized(): void
    {
        $this->signUp(['whatsapp' => ''])->assertHasFormErrors(['whatsapp' => 'required']);
        $this->signUp(['whatsapp' => '021 555 1234'])->assertHasFormErrors(['whatsapp']);
        $this->assertDatabaseMissing('users', ['email' => 'sari@example.com']);

        $this->signUp(['whatsapp' => '+62 812-3456-7890', 'contact_consent' => true])->assertHasNoFormErrors();

        $user = User::query()->where('email', 'sari@example.com')->firstOrFail();
        $this->assertSame('6281234567890', $user->whatsapp);
        $this->assertNotNull($user->contact_consent_at);
    }

    public function test_consent_is_optional_and_its_time_is_only_kept_when_given(): void
    {
        $this->signUp(['contact_consent' => false])->assertHasNoFormErrors();

        $user = User::query()->where('email', 'sari@example.com')->firstOrFail();
        $this->assertSame('6281234567890', $user->whatsapp);
        $this->assertNull($user->contact_consent_at);
    }

    public function test_the_sign_up_page_shows_the_whatsapp_field_and_the_consent_text(): void
    {
        config(['jitu.registration' => 'open']);

        $this->get('/app/register')->assertOk()
            ->assertSee('Nomor WhatsApp')
            ->assertSee('Saya bersedia dihubungi via WhatsApp/email tentang akun dan penawaran JITU LEAD');
    }

    public function test_the_owner_sees_whatsapp_and_consent_in_agencies_and_users(): void
    {
        $admin = User::factory()->create(['name' => 'Budi']);
        $admin->forceFill(['whatsapp' => '6281234567890', 'contact_consent_at' => now()])->save();
        $this->makeTenant('griya-baru', $admin);

        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListTenants::class)->assertSee('6281234567890')->assertSee('https://wa.me/6281234567890', false);
        Livewire::test(ListUsers::class)->assertSee('6281234567890')->assertSee('Setuju dihubungi');
    }

    // --- 1b. older accounts get a one-time banner ---

    public function test_an_account_without_whatsapp_is_asked_once_on_the_dashboard(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->adminOf($tenant);
        $this->actingInTenant($admin, $tenant);

        $this->assertTrue(WhatsAppPrompt::canView());

        Livewire::test(WhatsAppPrompt::class)->set('whatsapp', '12345')->call('save')->assertHasErrors('whatsapp');
        $this->assertNull($admin->fresh()->whatsapp);

        Livewire::test(WhatsAppPrompt::class)->set('whatsapp', '0812 1111 2222')->set('consent', true)->call('save')->assertHasNoErrors()->assertSet('done', true);

        $admin->refresh();
        $this->assertSame('6281211112222', $admin->whatsapp);
        $this->assertNotNull($admin->contact_consent_at);
        $this->assertFalse(WhatsAppPrompt::canView());
    }

    public function test_closing_the_banner_hides_it_for_good(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->adminOf($tenant);
        $this->actingInTenant($admin, $tenant);

        $this->get(route('filament.app.pages.dashboard', ['tenant' => $tenant->slug]))->assertOk()->assertSee('Lengkapi nomor WhatsApp Anda');

        Livewire::test(WhatsAppPrompt::class)->call('dismiss');

        $this->assertNull($admin->fresh()->whatsapp);
        $this->assertFalse(WhatsAppPrompt::canView());
        $this->get(route('filament.app.pages.dashboard', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee('Lengkapi nomor WhatsApp Anda');
    }

    // --- 2. the owner's own lead for each new agency ---

    private function registerAgency(bool $consent): Tenant
    {
        $user = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        $user->forceFill(['whatsapp' => '6281234567890', 'contact_consent_at' => $consent ? now() : null])->save();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(RegisterAgency::class)->fillForm(['name' => 'Griya Baru', 'slug' => 'griya-baru'])->call('register')->assertHasNoFormErrors();

        return Tenant::query()->where('slug', 'griya-baru')->firstOrFail();
    }

    public function test_a_new_agency_that_agreed_becomes_a_trial_lead_in_the_owners_agency(): void
    {
        $sales = $this->salesTenant();
        $agency = $this->registerAgency(consent: true);

        $lead = $this->salesLead($agency);
        $this->assertSame($sales->getKey(), $lead->tenant_id);
        $this->assertSame('Budi Santoso', $lead->name);
        $this->assertSame('budi@example.com', $lead->email);
        $this->assertSame('+6281234567890', $lead->phone);
        $this->assertSame(PlatformLeads::TRIAL, $lead->stage->name);
        $this->assertSame(StageType::Open, $lead->stage->type);
        $this->assertSame(PlatformLeads::SOURCE, $lead->source()->withoutGlobalScopes()->first()->name);
        $this->assertSame('Griya Baru', $lead->custom_fields['jitu']['agensi']);
        $this->assertSame('Tim Kecil (percobaan)', $lead->custom_fields['jitu']['paket']);
        $this->assertSame(now()->addDays(14)->format('Y-m-d'), $lead->custom_fields['jitu']['trial_berakhir']);

        // the three stages were added once to the owner's pipeline, after the existing ones
        $names = app(CurrentTenant::class)->run($sales, fn () => $sales->stages()->pluck('name')->all());
        $this->assertSame([PlatformLeads::TRIAL, PlatformLeads::SUBSCRIBED, PlatformLeads::DROPPED], array_slice($names, -3));
        $this->assertSame(count(ProvisionTenant::STAGES) + 3, count($names));
    }

    public function test_no_lead_without_consent_or_without_the_setting(): void
    {
        $this->salesTenant();
        $agency = $this->registerAgency(consent: false);
        $this->assertNull($agency->sales_lead_id);

        config(['jitu.platform_sales_tenant' => null]);
        $other = $this->makeTenant('griya-lain', User::factory()->create(['contact_consent_at' => now()]));
        app(PlatformLeads::class)->capture($other, $other->users()->first());
        $this->assertNull($other->fresh()->sales_lead_id);
    }

    public function test_the_lead_moves_to_subscribed_when_paid_and_follow_ups_stop(): void
    {
        $this->salesTenant();
        $agency = $this->registerAgency(consent: true);

        app(Subscriptions::class)->activate($agency, 'tim', 'monthly', 1);

        $lead = $this->salesLead($agency);
        $this->assertSame(PlatformLeads::SUBSCRIBED, $lead->stage->name);
        $this->assertSame(StageType::Won, $lead->stage->type);
        // follow-ups only run for leads in an open stage
        $this->assertFalse(Lead::withoutGlobalScopes()->open()->whereKey($lead->getKey())->exists());
        $this->assertStringContainsString('otomatis: paket aktif', $lead->activities()->withoutGlobalScopes()->where('type', 'stage_changed')->reorder('id', 'desc')->value('body'));
    }

    public function test_the_lead_moves_to_dropped_when_the_trial_ends_unpaid_and_back_on_an_extension(): void
    {
        $sales = $this->salesTenant();
        $agency = $this->registerAgency(consent: true);

        $this->travel(15)->days();
        app(Subscriptions::class)->dailyCheck();

        $lead = $this->salesLead($agency);
        $this->assertSame(PlatformLeads::DROPPED, $lead->stage->name);
        $this->assertSame(PlatformLeads::LOST_REASON, app(CurrentTenant::class)->run($sales, fn () => $lead->lostReason->label));

        $agency->currentSubscription()->update(['status' => Subscription::TRIAL, 'trial_ends_at' => now()->addDays(7)]);
        app(PlatformLeads::class)->sync($agency->fresh());
        $this->assertSame(PlatformLeads::TRIAL, $this->salesLead($agency)->stage->name);
        $this->assertNull($this->salesLead($agency)->lost_reason_id);
    }

    public function test_a_broken_sales_setup_never_breaks_the_sign_up(): void
    {
        config(['jitu.platform_sales_tenant' => 'tidak-ada']);

        $agency = $this->registerAgency(consent: true);

        $this->assertNull($agency->sales_lead_id);
    }

    // --- 3. the email series ---

    public function test_the_series_follows_the_trial_day_by_day_and_never_repeats(): void
    {
        $tenant = $this->agencyOnTrial();

        $this->assertSame(0, $this->runOn(1)['sent'], 'nothing on the sign-up day');
        $this->assertSame(1, $this->runOn(2)['sent']);
        $this->assertSame(0, $this->runOn(2)['sent'], 'a second run the same day sends nothing');
        $this->runOn(3);
        $this->runOn(4);
        $this->runOn(8);
        $this->runOn(12);
        $this->runOn(14);

        // the trial ends on the 15th: plan:check runs first, then the emails
        $this->travelTo(CarbonImmutable::parse('2026-10-16 01:00', 'Asia/Jakarta'));
        app(Subscriptions::class)->dailyCheck();
        $this->runOn(16);
        $this->runOn(20);
        $this->runOn(22);
        $this->runOn(23);

        $this->assertSame(['connect_whatsapp', 'ai_knowledge', 'usage_summary', 'ending_3', 'ending_1', 'ended', 'last_offer'], $this->queuedSteps());
        $this->assertSame(7, TrialEmailLog::query()->where('tenant_id', $tenant->getKey())->where('status', TrialEmailLog::SENT)->count());
    }

    public function test_trials_get_the_series_reminders_instead_of_the_old_email(): void
    {
        $this->agencyOnTrial();

        // inside the old reminder's 3 days, so without the series it would have gone out
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00', 'Asia/Jakarta'));
        $this->assertSame(0, app(Subscriptions::class)->dailyCheck()['reminded']);
        $this->runOn(12);

        Mail::assertNotSent(SubscriptionEnding::class);
        Mail::assertQueued(TrialEmail::class, fn (TrialEmail $m) => $m->step === 'ending_3'
            && $m->subjectLine === 'Masa percobaan Griya Baru berakhir 3 hari lagi'
            && str_contains($m->body, '15 Oktober 2026')
            && str_ends_with($m->url, '/app/griya-baru/subscription')
            && $m->button === 'Pilih paket dan bayar');
    }

    public function test_paid_subscriptions_still_get_the_old_reminder(): void
    {
        $tenant = $this->makeTenant();
        app(Subscriptions::class)->activate($tenant, 'tim', 'monthly', 1);
        $tenant->currentSubscription()->update(['current_period_end' => now()->addDays(2)]);

        $this->assertSame(1, app(Subscriptions::class)->dailyCheck()['reminded']);
        Mail::assertSent(SubscriptionEnding::class, 1);
        $this->assertSame(0, app(TrialEmails::class)->run()['sent'], 'a paying agency gets no trial emails');
    }

    public function test_switching_the_series_off_brings_back_the_old_trial_reminder(): void
    {
        PlatformSetting::write('trial_emails', ['enabled' => false]);
        $this->agencyOnTrial();

        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00', 'Asia/Jakarta'));
        $this->assertSame(1, app(Subscriptions::class)->dailyCheck()['reminded']);
        $this->assertSame(0, $this->runOn(12)['sent']);
        Mail::assertSent(SubscriptionEnding::class, 1);
    }

    public function test_the_usage_summary_counts_leads_and_messages(): void
    {
        $tenant = $this->agencyOnTrial();
        $this->makeLead($tenant);
        $this->makeLead($tenant);

        $this->runOn(8);

        Mail::assertQueued(TrialEmail::class, fn (TrialEmail $m) => $m->step === 'usage_summary'
            && $m->subjectLine === 'Seminggu bersama JITU LEAD: 2 lead, 0 pesan'
            && str_contains($m->body, 'masih 7 hari lagi'));
    }

    public function test_only_verified_admins_who_did_not_unsubscribe_get_it(): void
    {
        $tenant = $this->agencyOnTrial();
        $this->member($tenant, Role::Agent);
        $second = $this->member($tenant, Role::Admin);
        $unverified = $this->member($tenant, Role::Admin);
        $unverified->forceFill(['email_verified_at' => null])->save();
        $gone = $this->member($tenant, Role::Admin);
        $gone->forceFill(['trial_emails_unsubscribed_at' => now()])->save();

        $this->assertSame(2, $this->runOn(2)['sent']);

        $to = Mail::queued(TrialEmail::class)->map(fn (TrialEmail $m) => $m->to[0]['address'])->sort()->values()->all();
        $this->assertSame(collect(['budi@example.com', $second->email])->sort()->values()->all(), $to);
    }

    public function test_an_agency_that_paid_or_was_suspended_gets_nothing_more(): void
    {
        $tenant = $this->agencyOnTrial();
        $this->runOn(2);
        app(Subscriptions::class)->activate($tenant, 'tim', 'monthly', 1);
        $this->runOn(4);
        $this->runOn(12);

        $other = $this->makeTenant('griya-lain');
        $other->update(['status' => 'suspended']);
        $this->runOn(20);

        $this->assertSame(['connect_whatsapp'], $this->queuedSteps());
    }

    public function test_a_late_email_is_replaced_by_the_newer_one_and_tips_stop_after_the_trial(): void
    {
        $this->agencyOnTrial();

        // nothing ran until day 14: ending_3 (12th) is overtaken by ending_1 (14th), and the tips are past their window
        $this->runOn(14);
        $this->assertSame(['ending_1'], $this->queuedSteps());
        $this->assertSame(TrialEmailLog::SKIPPED, TrialEmailLog::query()->where('step', 'ending_3')->value('status'));
        $this->runOn(15);
        $this->assertSame(['ending_1'], $this->queuedSteps());
    }

    public function test_extending_the_trial_brings_the_ending_reminders_back(): void
    {
        $tenant = $this->agencyOnTrial();
        $this->runOn(12);
        $tenant->currentSubscription()->update(['trial_ends_at' => CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Jakarta')]);
        $this->runOn(22);

        $this->assertSame(['ending_3', 'ending_3'], $this->queuedSteps());
    }

    public function test_the_daily_limit_holds_the_rest_for_tomorrow(): void
    {
        config(['trial_emails.daily_limit' => 2]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Jakarta'));
        foreach (['satu', 'dua', 'tiga'] as $slug) {
            $this->makeTenant("griya-$slug");
        }

        $first = $this->runOn(2);
        $this->assertSame(2, $first['sent']);
        $this->assertTrue($first['limited']);

        $this->assertSame(1, $this->runOn(3)['sent'], 'the third agency gets its email the next day');
        $this->assertSame(3, collect($this->queuedSteps())->filter(fn ($s) => $s === 'connect_whatsapp')->count());
    }

    public function test_the_owner_can_switch_an_email_off_and_change_its_text(): void
    {
        PlatformSetting::write('trial_emails', ['steps' => [
            'connect_whatsapp' => ['enabled' => false],
            'last_offer' => ['subject' => 'Diskon 20% untuk {agensi}', 'body' => 'Halo {nama}, pakai kode HEMAT20.', 'button' => 'Ambil diskon'],
        ]]);
        $this->agencyOnTrial();

        $this->assertSame(0, $this->runOn(2)['sent']);

        $this->travelTo(CarbonImmutable::parse('2026-10-16 01:00', 'Asia/Jakarta'));
        app(Subscriptions::class)->dailyCheck();
        $this->runOn(22);

        Mail::assertQueued(TrialEmail::class, fn (TrialEmail $m) => $m->step === 'last_offer'
            && $m->subjectLine === 'Diskon 20% untuk Griya Baru' && $m->body === 'Halo Budi, pakai kode HEMAT20.' && $m->button === 'Ambil diskon');
    }

    public function test_names_are_escaped_in_the_body(): void
    {
        $tenant = $this->agencyOnTrial(User::factory()->create(['name' => '<b>Budi</b>']));

        $this->runOn(2);

        Mail::assertQueued(TrialEmail::class, fn (TrialEmail $m) => str_contains($m->body, '&lt;b&gt;Budi&lt;/b&gt;'));
    }

    public function test_the_email_is_branded_from_noreply_with_an_unsubscribe_link_and_header(): void
    {
        $tenant = $this->agencyOnTrial();
        $this->runOn(2);
        $mail = Mail::queued(TrialEmail::class)->first();

        $this->assertSame('noreply@jitulead.com', $mail->envelope()->from->address);
        $this->assertStringContainsString('/email/berhenti/', $mail->unsubscribeUrl);
        $this->assertSame('<'.$mail->unsubscribeUrl.'>', $mail->headers()->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);

        $mail->assertSeeInHtml('https://app.jitulead.test/images/email-logo.png', false);
        $mail->assertSeeInHtml('Berhenti menerima email ini');
        $mail->assertSeeInHtml(e($mail->unsubscribeUrl), false);
        $mail->assertSeeInHtml('Sambungkan WhatsApp');
        $mail->assertSeeInHtml('/app/griya-baru/wa-channels', false);
        $mail->assertSeeInText('Koneksi WhatsApp');
    }

    public function test_the_unsubscribe_link_asks_then_stops_the_emails(): void
    {
        $tenant = $this->agencyOnTrial();
        $admin = $this->adminOf($tenant);
        $url = TrialEmails::unsubscribeUrl($admin);

        $this->get($url)->assertOk()->assertSee('Berhenti menerima email ini');
        $this->assertNull($admin->fresh()->trial_emails_unsubscribed_at, 'opening the link alone changes nothing');

        // a mail app's one-click unsubscribe: a POST with no session or CSRF token
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('tidak akan lagi menerima');
        $this->assertNotNull($admin->fresh()->trial_emails_unsubscribed_at);

        $this->assertSame(0, $this->runOn(2)['sent']);
    }

    public function test_a_tampered_unsubscribe_link_is_refused(): void
    {
        $tenant = $this->agencyOnTrial();
        $other = User::factory()->create();
        $url = TrialEmails::unsubscribeUrl($this->adminOf($tenant));

        $this->get(str_replace('/berhenti/'.$this->adminOf($tenant)->getKey(), '/berhenti/'.$other->getKey(), $url))->assertForbidden();
        $this->post('/email/berhenti/'.$other->getKey())->assertForbidden();
        $this->assertNull($other->fresh()->trial_emails_unsubscribed_at);
    }

    public function test_the_command_and_its_schedule(): void
    {
        $this->agencyOnTrial();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00', 'Asia/Jakarta'));

        $this->artisan('trial-emails:send')->expectsOutputToContain('Email antre: 1 ke 1 agensi')->assertSuccessful();

        $this->artisan('schedule:list')->expectsOutputToContain('trial-emails:send');
    }

    public function test_the_owner_edits_the_series_in_admin(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TrialEmailSettingsPage::class)
            ->assertSet('data.steps.ending_3.subject', 'Masa percobaan {agensi} berakhir {hari_tersisa} hari lagi')
            ->set('data.steps.ai_knowledge.enabled', false)
            ->set('data.steps.last_offer.body', 'Teks baru')
            ->set('data.steps.ended.button', '')
            ->call('save')
            ->assertHasNoErrors();

        $steps = TrialEmailSettings::steps();
        $this->assertFalse($steps['ai_knowledge']['enabled']);
        $this->assertTrue($steps['ending_3']['enabled']);
        $this->assertSame('Teks baru', $steps['last_offer']['body']);
        $this->assertSame('', trim($steps['ended']['button']));

        Livewire::test(TrialEmailSettingsPage::class)->call('resetTexts');
        $steps = TrialEmailSettings::steps();
        $this->assertFalse($steps['ai_knowledge']['enabled'], 'the switches stay');
        $this->assertSame(config('trial_emails.steps.last_offer.body'), $steps['last_offer']['body']);

        $this->get('/admin/trial-emails')->assertOk()->assertSee('noreply@jitulead.com');
    }

    // --- 4. privacy policy ---

    public function test_the_privacy_policy_explains_the_whatsapp_number_and_how_to_stop(): void
    {
        $this->get('/kebijakan-privasi')->assertOk()
            ->assertSee('nomor WhatsApp')
            ->assertSee('Nomor WhatsApp, email masa percobaan, dan cara berhenti')
            ->assertSee('Berhenti menerima email ini')
            ->assertSee('Berhenti menerima pesan WhatsApp');
    }
}
