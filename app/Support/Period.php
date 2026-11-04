<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A reporting period (Dashboard, Lead, Pipeline, Laporan): today, yesterday, the last 7 or 30 days, this or last
 * month, or custom dates (at most MAX_DAYS). Days start at 00:00 and end at 23:59:59 in the agency's time zone, so
 * a lead at 23:30 WIB is today's, not tomorrow's. "Last 7 days" is today and the 6 days before.
 *
 * In a URL it is ?periode=7d, or ?dari=2026-11-01&sampai=2026-11-04 for custom dates.
 */
final class Period
{
    public const ALL = 'all';

    public const CUSTOM = 'custom';

    public const MAX_DAYS = 366;

    public const OPTIONS = [
        'today' => 'Hari ini',
        'yesterday' => 'Kemarin',
        '7d' => '7 hari terakhir',
        '30d' => '30 hari terakhir',
        'this_month' => 'Bulan ini',
        'last_month' => 'Bulan lalu',
        self::CUSTOM => 'Kustom',
    ];

    public const DEFAULT = '7d';

    private function __construct(
        public readonly string $key,
        /** First moment, in the app's clock zone (how dates are stored). */
        public readonly CarbonImmutable $start,
        /** Last moment (23:59:59.999999 local), in the app's clock zone. */
        public readonly CarbonImmutable $end,
        public readonly string $timezone,
    ) {}

    /**
     * The period for these inputs; anything unknown or invalid falls back to $default.
     * Custom dates are swapped when given backwards and refused when longer than MAX_DAYS.
     */
    public static function make(?string $key, ?string $from = null, ?string $to = null, ?string $timezone = null, string $default = self::DEFAULT): self
    {
        $tz = $timezone ?: self::tenantTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        if (($key === null || $key === '' || $key === self::CUSTOM) && filled($from) && filled($to)) {
            $a = self::date($from, $tz);
            $b = self::date($to, $tz);

            if ($a !== null && $b !== null) {
                [$a, $b] = $a->greaterThan($b) ? [$b, $a] : [$a, $b];

                if ($a->diffInDays($b) + 1 <= self::MAX_DAYS) {
                    return self::between(self::CUSTOM, $a, $b, $tz);
                }
            }
        }

        $ranges = [
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
        ];
        $key = isset($ranges[$key]) ? $key : (isset($ranges[$default]) ? $default : self::DEFAULT);

        return self::between($key, $ranges[$key][0], $ranges[$key][1], $tz);
    }

    /** Null for "Semua waktu" (no period chosen) where a page allows it. */
    public static function optional(?string $key, ?string $from = null, ?string $to = null, ?string $timezone = null): ?self
    {
        if (($key === null || $key === '' || $key === self::ALL) && (blank($from) || blank($to))) {
            return null;
        }

        if ($key !== self::CUSTOM && $key !== null && $key !== '' && ! array_key_exists($key, self::OPTIONS)) {
            return null;
        }

        if ($key === self::CUSTOM && (blank($from) || blank($to))) {
            return null;
        }

        return self::make($key ?: self::CUSTOM, $from, $to, $timezone);
    }

    /** The period just before, of the same length, to compare with. */
    public function previous(): self
    {
        $local = $this->localStart();
        $days = $this->days();

        if ($this->key === 'this_month' || $this->key === 'last_month') {
            // Month to month: last month against the one before; this month so far against the same days last month.
            $start = $local->subMonthNoOverflow()->startOfMonth();
            $end = $this->key === 'last_month'
                ? $start->endOfMonth()->startOfDay()
                : $start->addDays(min($days, $start->daysInMonth) - 1);

            return self::between('previous', $start, $end, $this->timezone);
        }

        return self::between('previous', $local->subDays($days), $local->subDay(), $this->timezone);
    }

    public function days(): int
    {
        return (int) $this->localStart()->diffInDays($this->end->setTimezone($this->timezone)->startOfDay()) + 1;
    }

    public function label(): string
    {
        if ($this->key === self::CUSTOM || $this->key === 'previous') {
            $a = $this->localStart();
            $b = $this->end->setTimezone($this->timezone);

            return $a->isSameDay($b) ? $a->translatedFormat('j M Y') : $a->translatedFormat('j M').' – '.$b->translatedFormat('j M Y');
        }

        return self::OPTIONS[$this->key];
    }

    /** @return array{periode: string, dari?: string, sampai?: string} */
    public function toQuery(): array
    {
        return $this->key === self::CUSTOM
            ? ['periode' => self::CUSTOM, 'dari' => $this->localStart()->toDateString(), 'sampai' => $this->end->setTimezone($this->timezone)->toDateString()]
            : ['periode' => $this->key];
    }

    /** Limits a query to rows whose $column falls in the period. */
    public function apply(Builder|QueryBuilder $query, string $column = 'created_at'): Builder|QueryBuilder
    {
        return $query->whereBetween($column, $this->bounds());
    }

    /** @return array{0: string, 1: string} as stored: wall time of the app's clock zone */
    public function bounds(): array
    {
        return [$this->start->format('Y-m-d H:i:s'), $this->end->format('Y-m-d H:i:s.u')];
    }

    public function key(): string
    {
        return $this->key.($this->key === self::CUSTOM ? ':'.$this->start->toDateString().':'.$this->end->toDateString() : '');
    }

    private function localStart(): CarbonImmutable
    {
        return $this->start->setTimezone($this->timezone);
    }

    private static function between(string $key, CarbonImmutable $firstDay, CarbonImmutable $lastDay, string $tz): self
    {
        $zone = config('app.timezone');

        return new self($key, $firstDay->startOfDay()->setTimezone($zone), $lastDay->endOfDay()->setTimezone($zone), $tz);
    }

    private static function date(string $value, string $tz): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * The viewer's last choice on a page (kept in their session), so coming back shows the same period.
     *
     * @return array{periode: ?string, dari: ?string, sampai: ?string}
     */
    public static function remembered(string $page): array
    {
        return (array) session()->get(self::sessionKey($page), []) + ['periode' => null, 'dari' => null, 'sampai' => null];
    }

    public static function remember(string $page, ?string $periode, ?string $dari = null, ?string $sampai = null): void
    {
        session()->put(self::sessionKey($page), ['periode' => $periode, 'dari' => $dari, 'sampai' => $sampai]);
    }

    private static function sessionKey(string $page): string
    {
        return 'periode.'.$page.'.'.(app(CurrentTenant::class)->id() ?? 0);
    }

    public static function tenantTimezone(): string
    {
        return app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta';
    }

    /**
     * The one picker every page uses: period chips, and two dates for "Kustom". Bound to "periode", "dari" and
     * "sampai" under $path.
     *
     * @param  bool  $all  offer "Semua waktu" (lists and boards that show everything by default)
     * @return list<mixed>
     */
    public static function fields(bool $all = false, string $label = 'Periode'): array
    {
        $custom = fn (Get $get) => $get('periode') === self::CUSTOM;

        return [
            ToggleButtons::make('periode')->label($label)->inline()->grouped(false)
                ->options(($all ? [self::ALL => 'Semua waktu'] : []) + self::OPTIONS)
                ->default($all ? self::ALL : self::DEFAULT)
                ->live()->columnSpanFull(),
            Grid::make(['default' => 2])->columnSpanFull()->visible($custom)->schema([
                DatePicker::make('dari')->label('Dari')->live(onBlur: true)
                    ->maxDate(fn () => now(self::tenantTimezone())->toDateString()),
                DatePicker::make('sampai')->label('Sampai')->live(onBlur: true)
                    ->maxDate(fn () => now(self::tenantTimezone())->toDateString())
                    ->helperText('Paling panjang '.self::MAX_DAYS.' hari.'),
            ]),
        ];
    }
}
