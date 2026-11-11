<?php

namespace Database\Seeders\Demo;

use App\Actions\ProvisionTenant;
use App\Enums\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Privacy\TenantEraser;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demo agencies the tutorial screenshots are taken from (php artisan tutorial:capture). Everything in them is
 * made up: names, phone numbers (0899000xxxx), addresses, tracking numbers, and keys. They refuse to run outside
 * APP_ENV=local or testing, and running one again replaces its agency. Every login uses the password "password".
 */
abstract class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** Only these environments may hold demo agencies. */
    public const ENVIRONMENTS = ['local', 'testing'];

    abstract protected function slug(): string;

    abstract protected function name(): string;

    abstract protected function industry(): string;

    /** Fills the agency; runs inside it. */
    abstract protected function fill(Tenant $tenant, array $team): void;

    public static function allowed(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    public function run(): void
    {
        if (! self::allowed()) {
            throw new RuntimeException('Data demo hanya boleh dibuat di APP_ENV=local atau testing, tidak di '.app()->environment().'.');
        }

        $domain = $this->slug().'.test';
        $admin = $this->user('Admin Demo', 'admin@'.$domain);

        if ($old = Tenant::query()->where('slug', $this->slug())->first()) {
            app(TenantEraser::class)($old, $admin);
        }

        $tenant = app(ProvisionTenant::class)($this->name(), $this->slug(), $admin, $this->industry());
        // A paid plan far ahead: no trial banner or plan pop-up in the screenshots.
        $tenant->forceFill(['plan' => 'agensi', 'status' => 'active', 'trial_ends_at' => null])->save();
        $tenant->currentSubscription()->forceFill([
            'plan' => 'agensi', 'status' => Subscription::ACTIVE, 'trial_ends_at' => null,
            'current_period_start' => now()->subDays(3), 'current_period_end' => now()->addYear(),
        ])->save();

        $team = ['admin' => $admin];

        foreach ([['manager', 'Tim Leader Demo', Role::Manager], ['agent', 'Agen Demo Satu', Role::Agent], ['agent2', 'Agen Demo Dua', Role::Agent]] as [$key, $name, $role]) {
            $team[$key] = $this->user($name, $key.'@'.$domain);
            $tenant->users()->attach($team[$key], ['role' => $role->value, 'status' => 'active']);
        }

        app(CurrentTenant::class)->run($tenant, fn () => $this->fill($tenant, $team));
    }

    private function user(string $name, string $email): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        // The "Lengkapi nomor WhatsApp Anda" card is dismissed so it stays out of the screenshots.
        $user->forceFill(['name' => $name, 'password' => bcrypt(self::PASSWORD), 'email_verified_at' => now(), 'whatsapp_prompt_dismissed_at' => now()])->save();

        return $user;
    }

    /** A made-up phone number: 0899000 followed by four digits. */
    protected static function phone(int $n): string
    {
        return '0899000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
