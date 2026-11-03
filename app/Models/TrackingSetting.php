<?php

namespace App\Models;

use App\Billing\PlanLimits;
use App\Models\Concerns\BelongsToTenant;
use App\Tracking\PipelineConversions;
use App\Tracking\PixelEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The agency's ad-tracking ids. The ids go into public pages; the API tokens
 * for server-side events are encrypted and never sent back to a form.
 */
#[Fillable(['meta_pixel_id', 'tiktok_pixel_id', 'google_tag_id', 'google_ads_label', 'meta_event', 'tiktok_event', 'google_event', 'button_events', 'credentials', 'pipeline_events'])]
#[Hidden(['credentials'])]
class TrackingSetting extends Model
{
    use BelongsToTenant;

    public const SECRETS = ['meta_capi_token', 'tiktok_events_token'];

    /**
     * The event each platform receives when someone submits a form, chosen from every standard event of that
     * platform (App\Tracking\PixelEvents). The first of each list is the default.
     */
    public const EVENTS = [
        'meta' => PixelEvents::META,
        'tiktok' => PixelEvents::TIKTOK,
        'google' => PixelEvents::GOOGLE,
    ];

    /** What a button click sends when its own pixel setting says "Ikuti Pengaturan Pelacakan". */
    public const BUTTON_DEFAULTS = ['meta' => 'Contact', 'tiktok' => 'ClickButton', 'google' => 'select_content'];

    /** The event to send for a platform, falling back to its default if the stored one is not on the list. */
    public function eventFor(string $platform): string
    {
        $stored = $this->getAttribute($platform.'_event');

        return array_key_exists((string) $stored, self::EVENTS[$platform]) ? $stored : array_key_first(self::EVENTS[$platform]);
    }

    /** The default click event for a platform: the agency's choice, "none", or BUTTON_DEFAULTS. */
    public function buttonEventFor(string $platform): ?string
    {
        $stored = $this->button_events[$platform] ?? null;

        return match (true) {
            $stored === PixelEvents::NONE => null,
            PixelEvents::isStandard($platform, $stored) => $stored,
            default => self::BUTTON_DEFAULTS[$platform] ?? null,
        };
    }

    /** @return array<string, ?string> per platform, the form-submit default */
    public function formDefaults(): array
    {
        return ['meta' => $this->eventFor('meta'), 'tiktok' => $this->eventFor('tiktok'), 'google' => $this->eventFor('google')];
    }

    /** @return array<string, ?string> per platform, the button-click default */
    public function buttonDefaults(): array
    {
        return ['meta' => $this->buttonEventFor('meta'), 'tiktok' => $this->buttonEventFor('tiktok'), 'google' => $this->buttonEventFor('google')];
    }

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'button_events' => 'array', 'pipeline_events' => 'array', 'last_results' => 'array'];
    }

    /** Where server events go, as the Pelacakan page names them. */
    public const DESTINATIONS = ['meta' => 'Meta', 'tiktok' => 'TikTok', 'meta_messaging' => 'Meta (WhatsApp Business, beta)'];

    /**
     * Which events the CRM sends by itself (App\Tracking\PipelineConversions): stage id => Meta event, the event
     * for a lead turning Hot, and the business messaging beta. Off and unmapped until the agency saves it.
     *
     * @return array{enabled: bool, hot: ?string, stages: array<int, string>, messaging: bool}
     */
    public function pipeline(): array
    {
        $stored = (array) ($this->pipeline_events ?? []);
        $valid = fn ($e) => is_string($e) && array_key_exists($e, PipelineConversions::EVENTS) ? $e : null;
        $stages = [];

        foreach ((array) ($stored['stages'] ?? []) as $stageId => $event) {
            if ($valid($event) !== null && (int) $stageId > 0) {
                $stages[(int) $stageId] = $event;
            }
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'hot' => $valid($stored['hot'] ?? null),
            'stages' => $stages,
            'messaging' => (bool) ($stored['messaging'] ?? false),
        ];
    }

    /** Notes the last answer of a destination for the Pelacakan page: no token, no customer data. */
    public function remember(string $destination, bool $ok, ?string $event, ?int $status = null): void
    {
        $results = (array) static::query()->whereKey($this->getKey())->value('last_results');
        $results[$destination] = array_filter(['ok' => $ok, 'at' => now()->toIso8601String(), 'event' => $event, 'status' => $status], fn ($v) => $v !== null);

        $this->forceFill(['last_results' => $results])->saveQuietly();
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
