<?php

namespace App\Ai\Evaluation;

use App\Ai\AiSettings;
use App\Enums\AiMode;
use App\Enums\Role;
use App\Filament\Pages\AiHealthPage;
use App\Mail\AiHealthDropped;
use App\Models\AiEvaluation;
use App\Models\AiKnowledgeItem;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "Kesehatan AI": every evaluation run is kept, and an agency's admins are e-mailed when the score falls below the run
 * before. With "Uji otomatis" on (new property agencies; others turn it on in Kesehatan AI), a property agency whose AI
 * is on is evaluated again some minutes after its knowledge changes and once a night (ai:health). Must run inside the tenant except where a Tenant is passed.
 */
final class AiHealth
{
    /** Minutes the knowledge must stay unchanged before it is evaluated (an owner editing many entries is one run). */
    public const SETTLE_MINUTES = 10;

    /** The agency's local hours in which the nightly run may start. */
    public const NIGHT_HOURS = [1, 5];

    /** What the knowledge looks like now: the number of active entries and the last change. */
    public static function version(): string
    {
        $row = AiKnowledgeItem::query()->where('active', true)->selectRaw('count(*) as n, max(updated_at) as at')->first();

        return $row?->n.'|'.$row?->at.'|'.AiKnowledgeItem::query()->count();
    }

    /** @param  list<array{passed: bool}>  $results */
    public static function record(Tenant $tenant, string $trigger, array $results): AiEvaluation
    {
        return app(CurrentTenant::class)->run($tenant, function () use ($tenant, $trigger, $results) {
            $previous = AiEvaluation::query()->latest('id')->first();
            $evaluation = AiEvaluation::query()->create([
                'trigger' => $trigger, 'passed' => count(array_filter($results, fn (array $r) => $r['passed'])), 'total' => count($results),
                'results' => $results, 'knowledge_version' => self::version(),
            ]);

            if ($previous !== null && $evaluation->score() < $previous->score()) {
                self::alert($tenant, $previous, $evaluation);
            }

            return $evaluation;
        });
    }

    private static function alert(Tenant $tenant, AiEvaluation $before, AiEvaluation $now): void
    {
        $url = AiHealthPage::getUrl(panel: 'app', tenant: $tenant);

        foreach ($tenant->activeUsers()->wherePivot('role', Role::Admin->value)->get() as $admin) {
            try {
                Mail::to($admin->email)->send(new AiHealthDropped($tenant, $before->score(), $now->score(), $now, $url));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Why this agency should be evaluated now ("knowledge" or "nightly"), or null. Only a property agency with a
     * number whose AI is on.
     */
    public static function due(Tenant $tenant, CarbonImmutable $now): ?string
    {
        if ($tenant->industry()->key !== 'properti' || ! AiSettings::of($tenant)->autoEval()) {
            return null;
        }

        return app(CurrentTenant::class)->run($tenant, function () use ($tenant, $now) {
            if (! WaChannel::query()->where('ai_mode', '!=', AiMode::Off->value)->exists()) {
                return null;
            }

            $last = AiEvaluation::query()->latest('id')->first();
            $changed = AiKnowledgeItem::query()->max('updated_at');

            if (($last === null || $last->knowledge_version !== self::version())
                && ($changed === null || CarbonImmutable::parse($changed)->lte($now->subMinutes(self::SETTLE_MINUTES)))) {
                return 'knowledge';
            }

            $local = $now->setTimezone($tenant->timezone ?: (string) config('app.timezone'));
            $lastNightly = AiEvaluation::query()->where('trigger', 'nightly')->latest('id')->first();

            if ($local->hour >= self::NIGHT_HOURS[0] && $local->hour < self::NIGHT_HOURS[1]
                && ($lastNightly === null || $lastNightly->created_at->lt($now->subHours(20)))) {
                return 'nightly';
            }

            return null;
        });
    }
}
