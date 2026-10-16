<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Models\User;
use App\Retention\PlatformLeads;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/**
 * Asks an account made before the WhatsApp number was required for it, once: saving or closing the banner
 * hides it for good.
 */
class WhatsAppPrompt extends Widget
{
    protected string $view = 'filament.widgets.whatsapp-prompt';

    protected static ?int $sort = -20;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public string $whatsapp = '';

    public bool $consent = false;

    public bool $done = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && blank($user->whatsapp) && $user->whatsapp_prompt_dismissed_at === null;
    }

    public function save(): void
    {
        $this->validate(['whatsapp' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) {
            if (PhoneNumber::whatsapp((string) $value) === null) {
                $fail('Isi nomor WhatsApp Indonesia yang valid, misalnya 0812 3456 7890.');
            }
        }]], [], ['whatsapp' => 'nomor WhatsApp']);

        /** @var User $user */
        $user = auth()->user();
        $user->setWhatsapp($this->whatsapp);
        $user->forceFill([
            'contact_consent_at' => $this->consent ? ($user->contact_consent_at ?? now()) : $user->contact_consent_at,
            'whatsapp_prompt_dismissed_at' => now(),
        ])->save();

        // An admin who agrees now joins the owner's list the way a new sign-up would.
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant !== null && $user->contact_consent_at !== null && $user->roleIn($tenant) === Role::Admin) {
            app(PlatformLeads::class)->capture($tenant, $user);
        }

        $this->done = true;
        Notification::make()->title('Nomor WhatsApp tersimpan')->success()->send();
    }

    public function dismiss(): void
    {
        auth()->user()->forceFill(['whatsapp_prompt_dismissed_at' => now()])->save();
        $this->done = true;
    }
}
