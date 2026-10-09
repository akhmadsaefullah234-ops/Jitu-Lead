<?php

namespace App\Models;

use App\Billing\PlanLimits;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The agency's ad-tracking ids. The ids go into public pages; the API tokens
 * for server-side events are encrypted and never sent back to a form.
 */
#[Fillable(['meta_pixel_id', 'tiktok_pixel_id', 'google_tag_id', 'google_ads_label', 'meta_event', 'tiktok_event', 'google_event', 'credentials'])]
#[Hidden(['credentials'])]
class TrackingSetting extends Model
{
    use BelongsToTenant;

    public const SECRETS = ['meta_capi_token', 'tiktok_events_token'];

    /**
     * The event each platform receives when someone submits a form. Only these standard
     * names are accepted, because the platforms ignore or mis-group unknown ones.
     * The first of each list is the default.
     */
    public const EVENTS = [
        'meta' => [
            'Lead' => 'Lead (calon pelanggan baru)',
            'CompleteRegistration' => 'CompleteRegistration (pendaftaran selesai)',
            'Contact' => 'Contact (menghubungi)',
            'SubmitApplication' => 'SubmitApplication (mengajukan permohonan)',
            'Schedule' => 'Schedule (menjadwalkan, misalnya survei)',
        ],
        'tiktok' => [
            'SubmitForm' => 'SubmitForm (kirim formulir)',
            'CompleteRegistration' => 'CompleteRegistration (pendaftaran selesai)',
            'Contact' => 'Contact (menghubungi)',
            'Subscribe' => 'Subscribe (berlangganan)',
        ],
        'google' => [
            'generate_lead' => 'generate_lead (calon pelanggan baru)',
            'sign_up' => 'sign_up (pendaftaran)',
            'contact' => 'contact (menghubungi)',
        ],
    ];

    /** The event to send for a platform, falling back to its default if the stored one is not on the list. */
    public function eventFor(string $platform): string
    {
        $stored = $this->getAttribute($platform.'_event');

        return array_key_exists((string) $stored, self::EVENTS[$platform]) ? $stored : array_key_first(self::EVENTS[$platform]);
    }

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    /** The settings public pages and server events may use: none on plans without the pixel feature. */
    public static function active(): ?self
    {
        return (PlanLimits::current()?->feature('pixels') ?? true) ? static::current() : null;
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function hasAny(): bool
    {
        return filled($this->meta_pixel_id) || filled($this->tiktok_pixel_id) || filled($this->google_tag_id);
    }
}
