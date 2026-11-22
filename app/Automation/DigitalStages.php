<?php

namespace App\Automation;

use App\Models\Stage;

/**
 * The steps of the digital-product pipeline (config/industries.php "digital") the automatic moves work with, found by
 * stages.template_key rather than by name, so a renamed stage keeps working. Stages without a key are matched by
 * name first, then by place: the 1st and 2nd open stages, the first won and the first two lost. Must run inside
 * the tenant.
 */
class DigitalStages
{
    public const NEW = 'new';

    public const PAYMENT = 'payment';

    public const PAID = 'paid';

    public const UNPAID = 'unpaid';

    public const REFUND = 'refund';

    /** Template name => key, in pipeline order; must agree with config/industries.php (digital). */
    public const TEMPLATE = [
        'lead baru' => self::NEW,
        'menunggu pembayaran' => self::PAYMENT,
        'lunas & akses terkirim' => self::PAID,
        'tidak jadi bayar' => self::UNPAID,
        'refund' => self::REFUND,
    ];

    private const OPEN_ORDER = [self::NEW, self::PAYMENT];

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

    public function is(?Stage $stage, string $key): bool
    {
        return $stage !== null && ($target = $this->stage($key)) !== null && $target->is($stage);
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

        foreach ([self::PAID => 'won', self::UNPAID => 'lost', self::REFUND => 'lost'] as $key => $type) {
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
