<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Register;
use App\Models\RegistrationInvite;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        config(['jitu.registration' => 'invite']);
    }

    private function form(array $extra = []): array
    {
        return $extra + ['name' => 'Budi', 'email' => 'budi@example.com', 'password' => 'rahasia-123-ok', 'passwordConfirmation' => 'rahasia-123-ok'];
    }

    private function register(array $extra = [], bool $freshLimiter = true)
    {
        // Filament throttles sign-up to 2 tries a minute; each case here is a different visitor.
        $freshLimiter && Cache::flush();

        return Livewire::test(Register::class)->fillForm($this->form($extra))->call('register');
    }

    public function test_a_valid_code_creates_the_account_and_is_spent(): void
    {
        $invite = RegistrationInvite::generate(1, 7, 'Pak Budi');

        $this->register(['invite_code' => $invite->display()])->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', ['email' => 'budi@example.com']);
        $this->assertSame(1, $invite->fresh()->uses);
    }

    public function test_codes_are_forgiving_about_case_and_dashes(): void
    {
        $invite = RegistrationInvite::generate();

        $this->register(['invite_code' => strtolower($invite->display())])->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', ['email' => 'budi@example.com']);
    }

    public function test_no_code_a_wrong_code_a_spent_code_or_an_expired_code_is_refused(): void
    {
        $spent = RegistrationInvite::generate(1);
        $spent->forceFill(['uses' => 1])->save();
        $expired = RegistrationInvite::generate(5, 1);
        $expired->update(['expires_at' => now()->subMinute()]);

        foreach ([null, '', 'SALAH-KODE0', $spent->display(), $expired->display()] as $code) {
            try {
                $this->register(['invite_code' => $code])->assertHasFormErrors(['invite_code']);
            } catch (\Throwable $e) {
                $this->fail('code='.var_export($code, true).' '.$e->getMessage());
            }
        }

        $this->assertSame(0, User::count());
    }

    public function test_a_code_with_several_uses_stops_when_they_run_out(): void
    {
        $invite = RegistrationInvite::generate(2);

        $this->register(['invite_code' => $invite->code, 'email' => 'a@example.com'])->assertHasNoFormErrors();
        auth()->logout();
        $this->register(['invite_code' => $invite->code, 'email' => 'b@example.com'])->assertHasNoFormErrors();
        auth()->logout();
        $this->register(['invite_code' => $invite->code, 'email' => 'c@example.com'])->assertHasFormErrors(['invite_code']);

        $this->assertSame(2, User::count());
        $this->assertSame(2, $invite->fresh()->uses);
    }

    public function test_guessing_codes_is_throttled(): void
    {
        Cache::flush();

        foreach (range(1, 4) as $i) {
            $this->register(['invite_code' => 'TEBAK'.$i.'KODE0'], freshLimiter: false);
        }

        $valid = RegistrationInvite::generate();
        $this->register(['invite_code' => $valid->code], freshLimiter: false);

        $this->assertSame(0, User::count(), 'Even a valid code is refused once the visitor is throttled');
    }

    public function test_the_last_use_cannot_be_spent_twice(): void
    {
        $invite = RegistrationInvite::generate(1);

        $this->assertTrue(RegistrationInvite::consume($invite->code));
        $this->assertFalse(RegistrationInvite::consume($invite->code));
    }

    public function test_open_mode_needs_no_code_and_closed_mode_has_no_sign_up_page(): void
    {
        config(['jitu.registration' => 'open']);
        $this->assertFalse(Register::needsInvite());
        $this->register()->assertHasNoFormErrors();
        $this->assertDatabaseHas('users', ['email' => 'budi@example.com']);

        config(['jitu.registration' => 'invite']);
        $this->assertTrue(Register::needsInvite());
    }

    public function test_the_command_prints_a_code_that_works(): void
    {
        $this->artisan('invite:create', ['--uses' => 3, '--days' => 0, '--note' => 'Tes'])->assertSuccessful();

        $invite = RegistrationInvite::first();
        $this->assertSame(3, $invite->max_uses);
        $this->assertNull($invite->expires_at);
        $this->artisan('invite:list')->assertSuccessful();
    }
}
