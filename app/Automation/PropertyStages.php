<?php

namespace App\Automation;

use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Models\Stage;
use Illuminate\Support\Collection;

/**
 * The steps of the property pipeline the automatic moves work with (App\Automation\PropertyStageAutomation), found
 * by stages.template_key first, so a renamed stage keeps working. Agencies whose stages carry no key (every agency
 * made before the keys existed) are matched by the template's names, then the survey step by its "survey"
 * requirement, Booking by its "booking" requirement among open stages, Closing as the first won stage and Gugur as
 * the first lost stage. The first three steps fall back to the first three open stages only when they come before
 * the survey step, so an agency's own pipeline is never guessed further than that. Must run inside the tenant.
 */
class PropertyStages
{
    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const QUALIFIED = 'qualified';

    public const SURVEY = 'survey';

    public const SURVEYED = 'surveyed';

    public const NEGOTIATION = 'negotiation';

    public const BOOKING = 'booking';

    public const CLOSING = 'closing';

    public const LOST = 'lost';

    /** Template name => key, in pipeline order; must agree with config/industries.php (properti). */
    public const TEMPLATE = [
        'lead baru' => self::NEW,
        'dihubungi' => self::CONTACTED,
        'terkualifikasi' => self::QUALIFIED,
        'jadwal survei' => self::SURVEY,
        'sudah survei' => self::SURVEYED,
        'negosiasi' => self::NEGOTIATION,
        'booking' => self::BOOKING,
        'closing' => self::CLOSING,
        'gugur' => self::LOST,
    ];

    private const EARLY = [self::NEW, self::CONTACTED, self::QUALIFIED];

    /** @var array<string, ?Stage> */
    private array $found = [];

    /** @var Collection<int, Stage>|null */
    private ?Collection $all = null;

    public function stage(string $key): ?Stage
    {
        if (array_key_exists($key, $this->found)) {
            return $this->found[$key];
        }

        $stages = $this->all();
        $stage = $stages->firstWhere('template_key', $key);

        if ($stage === null && ! $stages->pluck('template_key')->contains($key)) {
            $id = self::match($stages->whereNull('template_key')->map(fn (Stage $s) => [
                'id' => $s->id, 'name' => $s->name, 'type' => $s->type->value, 'requirement' => $s->requirement?->value,
            ])->values()->all())[$key] ?? null;
            $stage = $id ? $stages->firstWhere('id', $id) : null;
        }

        return $this->found[$key] = $stage;
    }

    public function keyOf(?Stage $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        if ($stage->template_key !== null && in_array($stage->template_key, self::TEMPLATE, true)) {
            return $stage->template_key;
        }

        foreach (self::TEMPLATE as $key) {
            if ($this->stage($key)?->is($stage)) {
                return $key;
            }
        }

        return null;
    }

    public function is(?Stage $stage, string $key): bool
    {
        return $stage !== null && $this->keyOf($stage) === $key;
    }

    /** @return Collection<int, Stage> */
    private function all(): Collection
    {
        return $this->all ??= Stage::query()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Which stage is which step.
     *
     * @param  list<array{id: int, name: string, type: string, requirement?: ?string}>  $stages  in pipeline order
     * @return array<string, int> key => stage id
     */
    public static function match(array $stages): array
    {
        $found = [];
        $used = [];
        $take = function (string $key, array $s) use (&$found, &$used) {
            $found[$key] = $s['id'];
            $used[$s['id']] = true;
        };

        foreach ($stages as $s) {
            $key = self::TEMPLATE[mb_strtolower(trim($s['name']))] ?? null;

            if ($key !== null && ! isset($found[$key])) {
                $take($key, $s);
            }
        }

        $open = array_values(array_filter($stages, fn ($s) => $s['type'] === StageType::Open->value));

        foreach ([self::SURVEY => StageRequirement::Survey->value, self::BOOKING => StageRequirement::Booking->value] as $key => $requirement) {
            foreach ($open as $s) {
                if (! isset($found[$key]) && ! isset($used[$s['id']]) && ($s['requirement'] ?? null) === $requirement) {
                    $take($key, $s);
                }
            }
        }

        // The first three steps by place, only before the survey step.
        $survey = isset($found[self::SURVEY]) ? array_search($found[self::SURVEY], array_column($open, 'id'), true) : false;

        foreach (self::EARLY as $i => $key) {
            if (! isset($found[$key]) && isset($open[$i]) && ! isset($used[$open[$i]['id']]) && $survey !== false && $i < $survey) {
                $take($key, $open[$i]);
            }
        }

        foreach ([self::CLOSING => StageType::Won->value, self::LOST => StageType::Lost->value] as $key => $type) {
            foreach ($stages as $s) {
                if (! isset($found[$key]) && ! isset($used[$s['id']]) && $s['type'] === $type) {
                    $take($key, $s);
                }
            }
        }

        return $found;
    }
}
