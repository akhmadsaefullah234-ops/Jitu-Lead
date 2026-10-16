<?php

namespace Tests\Feature;

use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Mail\BackupFailed;
use App\Mail\NewAgencyRegistered;
use App\Mail\PaymentReceived;
use App\Mail\SubscriptionEnding;
use App\Mail\SubscriptionRequested;
use App\Mail\SupportMessageReceived;
use App\Mail\WhatsAppNeedsRelink;
use App\Models\BackupRun;
use App\Models\SupportThread;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.jitulead.test']);
    }

    private function assertBranded(Mailable $mail, string $needle): void
    {
        $mail->assertSeeInHtml('https://app.jitulead.test/images/email-logo.png', false);
        $mail->assertSeeInHtml('jitulead.com', false);
        $mail->assertSeeInHtml('/kebijakan-privasi', false);
        $mail->assertSeeInHtml($needle);
        $mail->assertSeeInText($needle);
        $mail->assertSeeInText('jitulead.com');
        $mail->assertSeeInText('/kebijakan-privasi');
    }

    // --- 1. the shared theme ---

    public function test_the_logo_file_exists_as_a_png_and_the_button_is_brand_red(): void
    {
        $this->assertFileExists(public_path('images/email-logo.png'));
        $this->assertSame("\x89PNG", substr(file_get_contents(public_path('images/email-logo.png')), 0, 4));
        $this->assertStringContainsString('#d92b20', file_get_contents(resource_path('views/vendor/mail/html/themes/default.css')));
    }

    public function test_every_app_email_carries_the_logo_footer_and_a_text_version(): void
    {
        $tenant = $this->makeTenant();
        $owner = User::factory()->create();

        $run = BackupRun::create(['status' => BackupRun::FAILED, 'started_at' => now(), 'message' => 'pg_dump gagal', 'steps' => [['label' => 'Database', 'status' => 'gagal', 'note' => null]]]);
        $this->assertBranded(new BackupFailed($run), 'pg_dump gagal');

        $thread = SupportThread::create(['tenant_id' => $tenant->getKey(), 'user_id' => $owner->getKey(), 'subject' => 'Bantuan']);
        $message = $thread->addMessage("Halo admin\nTolong bantu <b>cepat</b>", false, $owner);
        $this->assertBranded(new SupportMessageReceived($thread, $message), 'Tolong bantu');

        $this->assertBranded(new NewAgencyRegistered('Griya Baru', ['Agensi' => 'Griya Baru', 'Email' => 'a@b.co'], 'https://app.jitulead.test/admin/tenants'), 'Griya Baru');
        $this->assertBranded(new SubscriptionRequested('Griya Baru', 'Tim', ['Agensi' => 'Griya Baru', 'Total' => 'Rp 199.000'], 'https://app.jitulead.test/admin'), 'Rp 199.000');
        $this->assertBranded(new PaymentReceived('Griya Baru', 'Tim', 'Rp 199.000', ['Metode' => 'qris'], 'https://app.jitulead.test/admin'), 'Pembayaran diterima: Griya Baru Tim Rp 199.000 via iPaymu');

        $sub = $tenant->currentSubscription();
        $this->assertBranded(new SubscriptionEnding($tenant, $sub), 'segera berakhir');

        $channel = $this->inTenantChannel($tenant);
        $this->assertBranded(new WhatsAppNeedsRelink($tenant, $channel), 'scan ulang');
    }

    private function inTenantChannel($tenant): WaChannel
    {
        return app(CurrentTenant::class)->run($tenant, fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    public function test_the_support_message_text_is_escaped_not_run_as_html(): void
    {
        $tenant = $this->makeTenant();
        $owner = User::factory()->create();
        $thread = SupportThread::create(['tenant_id' => $tenant->getKey(), 'user_id' => $owner->getKey(), 'subject' => 'Bantuan']);
        $message = $thread->addMessage('<script>alert(1)</script>', false, $owner);

        $html = (new SupportMessageReceived($thread, $message))->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_the_filament_and_laravel_account_emails_use_the_same_theme(): void
    {
        $user = User::factory()->create(['email' => 'budi@example.com']);

        $verify = new VerifyEmail;
        $verify->url = 'https://app.jitulead.test/app/email-verification/verify/1/abc';
        $reset = new ResetPassword('tok');

        foreach ([$verify->toMail($user), $reset->toMail($user)] as $mail) {
            $html = (string) $mail->render();
            $this->assertStringContainsString('https://app.jitulead.test/images/email-logo.png', $html);
            $this->assertStringContainsString('jitulead.com', $html);
            $this->assertStringContainsString('/kebijakan-privasi', $html);
            $this->assertStringContainsString('#d92b20', file_get_contents(resource_path('views/vendor/mail/html/themes/default.css')));
        }
    }

    // --- 2. wrong verification link ---

    private function verifyUrl(User $for, ?\DateTimeInterface $until = null): string
    {
        return URL::temporarySignedRoute('filament.app.auth.email-verification.verify', $until ?? now()->addHour(), ['id' => $for->getKey(), 'hash' => sha1($for->email)]);
    }

    private function unverified(string $email): User
    {
        return User::factory()->unverified()->create(['email' => $email]);
    }

    public function test_another_accounts_link_shows_a_clear_page_with_the_email_masked(): void
    {
        $target = $this->unverified('joko@gmail.com');
        $other = User::factory()->create(['email' => 'admin@platform.test']);

        $response = $this->actingAs($other)->get($this->verifyUrl($target));

        $response->assertStatus(403)->assertSee('Tautan ini untuk akun lain')->assertSee('j***@gmail.com')->assertSee('admin@platform.test')
            ->assertSee('Keluar dan masuk sebagai akun itu')->assertDontSee('joko@gmail.com');
        $this->assertNull($target->fresh()->email_verified_at);
    }

    public function test_the_right_account_still_verifies(): void
    {
        $user = $this->unverified('joko@gmail.com');

        $this->actingAs($user)->get($this->verifyUrl($user))->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_explains_and_offers_a_resend(): void
    {
        $user = $this->unverified('joko@gmail.com');

        $this->actingAs($user)->get($this->verifyUrl($user, now()->subMinute()))
            ->assertStatus(410)->assertSee('Tautan verifikasi tidak berlaku')->assertSee('Kirim ulang email verifikasi');
    }

    public function test_a_link_for_an_old_email_of_the_same_account_is_treated_as_invalid(): void
    {
        $user = $this->unverified('joko@gmail.com');
        $url = $this->verifyUrl($user);
        $user->forceFill(['email' => 'joko.baru@gmail.com'])->save();

        $this->actingAs($user)->get($url)->assertStatus(410)->assertSee('tidak berlaku');
    }

    public function test_a_guest_is_sent_to_login_and_the_link_is_remembered(): void
    {
        $user = $this->unverified('joko@gmail.com');
        $url = $this->verifyUrl($user);

        $this->get($url)->assertRedirect();
        $this->assertSame($url, session('url.intended'));
    }

    public function test_logging_in_as_the_right_account_continues_to_the_verification_link(): void
    {
        $target = $this->unverified('joko@gmail.com');
        $target->forceFill(['password' => bcrypt('rahasia123')])->save();
        $url = $this->verifyUrl($target);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        session()->put('url.intended', $url);

        Livewire::test(\Filament\Auth\Pages\Login::class)
            ->fillForm(['email' => 'joko@gmail.com', 'password' => 'rahasia123'])->call('authenticate')->assertRedirect($url);
    }

    public function test_switching_account_signs_out_and_keeps_the_link_for_after_login(): void
    {
        $target = $this->unverified('joko@gmail.com');
        $other = User::factory()->create();
        $url = $this->verifyUrl($target);

        $this->actingAs($other)->post(route('verification.switch'), ['url' => $url])->assertRedirect(Filament::getPanel('app')->getLoginUrl());

        $this->assertGuest();
        $this->assertSame($url, session('url.intended'));
    }

    public function test_switching_account_refuses_anything_but_a_signed_verification_link(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('verification.switch'), ['url' => 'https://evil.example/phish'])->assertStatus(422);
        $this->actingAs($other)->post(route('verification.switch'), ['url' => url('/app/email-verification/verify/1/abc')])->assertStatus(422);
        $this->assertAuthenticatedAs($other);
    }

    public function test_resending_sends_a_fresh_verification_email(): void
    {
        Notification::fake();
        $user = $this->unverified('joko@gmail.com');

        $this->actingAs($user)->post(route('verification.resend'))->assertOk()->assertSee('Email verifikasi dikirim');

        Notification::assertSentTo($user, VerifyEmail::class, fn (VerifyEmail $n) => str_contains($n->url, '/email-verification/verify/'.$user->getKey().'/'));
    }

    public function test_resending_to_a_verified_account_says_so_and_sends_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('verification.resend'))->assertOk()->assertSee('sudah terverifikasi');

        Notification::assertNothingSent();
    }

    // --- 3. super admin reset link ---

    public function test_the_admin_reset_action_sends_a_link_to_the_app_panel_without_error(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $user = User::factory()->create(['email' => 'budi@example.com']);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListUsers::class)->callTableAction('reset', $user)->assertNotified('Tautan dikirim');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;
            $this->assertStringContainsString('/app/password-reset/reset', $url);
            $this->assertStringContainsString('email=budi%40example.com', $url);
            $this->assertStringContainsString('signature=', $url);

            return true;
        });
    }
}
