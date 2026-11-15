<?php

namespace App\Ai\Evaluation;

use App\Actions\GenerateAiReply;
use App\Ai\AiSettings;
use App\Ai\PropertyGuard;
use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\StageType;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Runs the playbook scenarios (Scenarios) against an agency's real knowledge, instructions and settings, the way
 * the AI would answer them in Draft mode, and says per scenario whether it passed. Each scenario runs in a database
 * transaction that is rolled back: a made-up client (0899000xxxx) and chat exist only for that moment, nothing is
 * sent to WhatsApp, and no job, e-mail or notification goes out. Only the AI model is really called (it costs tokens,
 * not counted in the plan's quota).
 */
final class Evaluator
{
    /** Emoji the playbook never uses. */
    public const BANNED_EMOJI = ['😂', '🤣', '❤️', '❤'];

    public const MAX_EMOJI = 3;

    /** Always refused, whatever the agency set. */
    public const PROMISES = ['siap huni', 'pasti disetujui'];

    public function __construct(private CurrentTenant $current) {}

    /**
     * @param  list<int>|null  $only  scenario numbers (from 1) to run; all when null
     * @return list<array{no: int, title: string, passed: bool, outcome: string, reply: ?string, problems: list<string>}>
     */
    public function run(Tenant $tenant, ?array $only = null): array
    {
        // Nothing leaves the app but the model call: queued work, mail and notifications are held, and any other
        // HTTP request (a gateway) is answered here.
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com') ? null : Http::response(['ok' => true]));

        $results = [];

        foreach (Scenarios::all() as $i => $scenario) {
            if ($only !== null && ! in_array($i + 1, $only, true)) {
                continue;
            }

            $results[] = ['no' => $i + 1, 'title' => $scenario['title']] + $this->current->run($tenant, fn () => $this->scenario($tenant, $i + 1, $scenario));
        }

        return $results;
    }

    /** @return array{passed: bool, outcome: string, reply: ?string, problems: list<string>} */
    private function scenario(Tenant $tenant, int $no, array $scenario): array
    {
        $before = count(Http::recorded());
        DB::beginTransaction();

        try {
            $draft = $this->answer($no, $scenario['turns']);
        } catch (Throwable $e) {
            report($e);

            return ['passed' => false, 'outcome' => 'galat', 'reply' => null, 'problems' => ['gagal dijalankan: '.$e->getMessage()]];
        } finally {
            DB::rollBack();
        }

        $given = Http::recorded()->slice($before)
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->map(fn (array $pair) => ($pair[0]['system'] ?? '')."\n".json_encode($pair[0]['messages'] ?? [], JSON_UNESCAPED_UNICODE))
            ->implode("\n");
        $given .= "\n".collect($scenario['turns'])->pluck(1)->implode("\n");

        return $this->judge($tenant, $scenario, $draft, $given);
    }

    /** @param  list<array{0: string, 1: string}>  $turns */
    private function answer(int $no, array $turns): ?AiDraft
    {
        $channel = WaChannel::query()->create([
            'type' => WaChannelType::Gateway, 'name' => 'Evaluasi AI', 'phone' => '+62899000'.sprintf('%04d', 9000 + $no),
            'status' => WaChannelStatus::Disconnected, 'position' => 999, 'ai_mode' => AiMode::Draft, 'credentials' => [],
        ]);
        $stage = Stage::query()->where('type', StageType::Open)->orderBy('position')->first();
        $lead = Lead::query()->create(['name' => 'Klien Uji', 'phone' => '+62899000'.sprintf('%04d', $no), 'stage_id' => $stage?->getKey()]);
        $conversation = WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]);
        $at = CarbonImmutable::now()->subMinutes(count($turns) + 1);
        $last = null;

        foreach ($turns as $k => [$side, $text]) {
            $last = $conversation->messages()->create([
                'channel_id' => $channel->getKey(), 'direction' => $side, 'type' => 'text', 'body' => $text,
                'status' => $side === 'in' ? MessageStatus::Read : MessageStatus::Sent, 'sent_at' => $at->addMinutes($k),
                'external_id' => 'eval-'.$no.'-'.$k,
            ]);
        }

        $conversation->forceFill(['last_inbound_at' => $last->sent_at, 'last_inbound_channel_id' => $channel->getKey()])->save();

        return app(GenerateAiReply::class)($last->setRelation('channel', $channel));
    }

    /** @return array{passed: bool, outcome: string, reply: ?string, problems: list<string>} */
    private function judge(Tenant $tenant, array $scenario, ?AiDraft $draft, string $given): array
    {
        $reply = $draft?->body !== null && trim($draft->body) !== '' ? trim($draft->body) : null;
        $outcome = match (true) {
            $draft === null => 'tidak membalas',
            $draft->status === AiDraft::HANDOFF => 'diserahkan ke tim',
            $reply !== null => 'menjawab',
            default => 'tidak membalas',
        };
        $problems = [];

        if ($scenario['expect'] === 'handoff' && $draft?->status !== AiDraft::HANDOFF) {
            $problems[] = 'seharusnya diserahkan ke tim, tetapi '.$outcome;
        }

        if ($scenario['expect'] === 'answer' && $reply === null) {
            $problems[] = 'seharusnya dijawab, tetapi '.$outcome.($draft?->reason ? ' ('.$draft->reason.')' : '');
        }

        if ($reply !== null) {
            $phrases = array_values(array_unique([...self::PROMISES, ...AiSettings::of($tenant)->forbiddenPhrases(), ...($scenario['promises'] ?? [])]));
            $problems = [...$problems, ...PropertyGuard::problems($reply, $given, $phrases)];

            foreach ($scenario['must'] ?? [] as $pattern => $what) {
                if (! preg_match($pattern, $reply)) {
                    $problems[] = 'tidak '.$what;
                }
            }

            foreach (self::BANNED_EMOJI as $emoji) {
                if (str_contains($reply, $emoji)) {
                    $problems[] = "memakai emoji {$emoji}";
                }
            }

            if (($n = self::emoji($reply)) > self::MAX_EMOJI) {
                $problems[] = "{$n} emoji (paling banyak ".self::MAX_EMOJI.')';
            }
        }

        $problems = array_values(array_unique($problems));

        return ['passed' => $problems === [], 'outcome' => $outcome, 'reply' => $reply, 'problems' => $problems];
    }

    public static function emoji(string $text): int
    {
        return preg_match_all('/\p{Extended_Pictographic}/u', $text);
    }
}
