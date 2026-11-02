<?php

namespace App\Automation;

use App\Models\Stage;

/**
 * The steps of the retail pipeline the automatic moves work with, found by the step they are in the template
 * (stages.template_key) rather than by name, so a renamed stage keeps working. Agencies whose stages carry no
 * key yet are matched by name first, then by order: the 1st to 4th open stages, the first won and the first lost.
 * Must run inside the tenant.
 */
class RetailStages
{
    public const INQUIRY = 'inquiry';

    public const ORDER = 'order';

    public const PAYMENT = 'payment';

    public const SHIPPING = 'shipping';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    /** Template name => key, in pipeline order; must agree with config/industries.php (retail). */
    public const TEMPLATE = [
        'tanya produk' => self::INQUIRY,
        'order masuk' => self::ORDER,
        'menunggu pembayaran' => self::PAYMENT,
        'dikemas & dikirim' => self::SHIPPING,
        'selesai' => self::DONE,
        'batal' => self::CANCELLED,
    ];

    private const OPEN_ORDER = [self::INQUIRY, self::ORDER, self::PAYMENT, self::SHIPPING];

    /** @var array<string, ?Stage> */
    private array $found = [];

    public function stage(string $key): ?Stage
    {
        if (array_key_exists($key, $this->found)) {
            return $this->found[$key];
        }

        $stage = Stage::query()->where('template_key', $key)->first();

        if ($stage === null) {
            $stages = Stage::query()->orderBy('position')->orderBy('id')->get();
            $keyed = $stages->whereNotNull('template_key')->pluck('template_key')->all();
            $id = in_array($key, $keyed, true) ? null : (self::match($stages->whereNull('template_key')
                ->map(fn (Stage $s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type->value])->values()->all())[$key] ?? null);
            $stage = $id ? $stages->firstWhere('id', $id) : null;
        }

        return $this->found[$key] = $stage;
    }

    public function keyOf(?Stage $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        if ($stage->template_key !== null) {
            return $stage->template_key;
        }

        foreach (array_keys(self::TEMPLATE) as $name) {
            $key = self::TEMPLATE[$name];

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

    /**
     * Which stage is which step: by template name first, then by place.
     *
     * @param  list<array{id: int, name: string, type: string}>  $stages  in pipeline order
     * @return array<string, int> key => stage id
     */
    public static function match(array $stages): array
    {
        $found = [];
        $used = [];

        foreach ($stages as $s) {
            $key = self::TEMPLATE[mb_strtolower(trim($s['name']))] ?? null;

            if ($key !== null && ! isset($found[$key])) {
                $found[$key] = $s['id'];
                $used[$s['id']] = true;
            }
        }

        $open = array_values(array_filter($stages, fn ($s) => $s['type'] === 'open'));

        foreach (self::OPEN_ORDER as $i => $key) {
            if (! isset($found[$key]) && isset($open[$i]) && ! isset($used[$open[$i]['id']])) {
                $found[$key] = $open[$i]['id'];
                $used[$open[$i]['id']] = true;
            }
        }

        foreach ([self::DONE => 'won', self::CANCELLED => 'lost'] as $key => $type) {
            if (! isset($found[$key])) {
                foreach ($stages as $s) {
                    if ($s['type'] === $type && ! isset($used[$s['id']])) {
                        $found[$key] = $s['id'];
                        $used[$s['id']] = true;
                        break;
                    }
                }
            }
        }

        return $found;
    }
}
