<?php

namespace App\Welcome;

use App\Models\Tenant;

/**
 * The quiet hours of an agency's automatic welcome, kept in tenants.capture_settings under 'welcome_hours':
 * ['all_day' => bool, 'from' => 0-23, 'until' => 0-23]. Nothing saved means the old fixed hours, 21:00 to 05:00
 * in the agency's time zone, so an agency that never opens the setting keeps the same behaviour. With all_day on
 * (Pengaturan agensi > "Kirim 24 jam (tanpa jam hening)") the welcome goes out at any hour.
 */
final class WelcomeHours
{
    public const DEFAULT_FROM = 21;

    public const DEFAULT_UNTIL = 5;

    private function __construct(public readonly bool $allDay, public readonly int $from, public readonly int $until) {}

    public static function for(Tenant $tenant): self
    {
        $saved = (array) (((array) $tenant->capture_settings)['welcome_hours'] ?? []);

        return self::make($saved);
    }

    /** @param  array<string, mixed>  $saved */
    public static function make(array $saved): self
    {
        $hour = fn ($v, int $default) => is_numeric($v) && (int) $v >= 0 && (int) $v <= 23 ? (int) $v : $default;
        $from = $hour($saved['from'] ?? null, self::DEFAULT_FROM);
        $until = $hour($saved['until'] ?? null, self::DEFAULT_UNTIL);

        // The same start and end hour would be no quiet time at all: that is what all_day is for.
        if ($from === $until) {
            [$from, $until] = [self::DEFAULT_FROM, self::DEFAULT_UNTIL];
        }

        return new self((bool) ($saved['all_day'] ?? false), $from, $until);
    }

    /** @return array{all_day: bool, from: int, until: int} */
    public function toArray(): array
    {
        return ['all_day' => $this->allDay, 'from' => $this->from, 'until' => $this->until];
    }

    /** Whether a local hour (0-23) falls in the quiet time. The quiet time may run past midnight. */
    public function quietAt(int $hour): bool
    {
        if ($this->allDay) {
            return false;
        }

        return $this->from > $this->until
            ? $hour >= $this->from || $hour < $this->until
            : $hour >= $this->from && $hour < $this->until;
    }

    /** "21:00–05:00", or "24 jam". */
    public function label(): string
    {
        return $this->allDay ? '24 jam' : sprintf('%02d:00–%02d:00', $this->from, $this->until);
    }
}
