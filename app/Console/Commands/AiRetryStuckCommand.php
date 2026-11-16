<?php

namespace App\Console\Commands;

use App\Actions\GenerateAiReply;
use App\Ai\AiSettings;
use App\Ai\ConversationHistory;
use App\Enums\AiMode;
use App\Models\AiDraft;
use App\Models\Tenant;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ai:retry-stuck {--tenant= : slug agensi} {--minutes=30 : pesan klien tanpa balasan lebih lama dari ini} {--hours=48 : abaikan pesan yang lebih tua dari ini} {--limit=20 : paling banyak percakapan yang dijalankan} {--dry-run : hanya tampilkan, tanpa menjalankan AI}')]
#[Description('Jalankan ulang AI untuk chat yang macet: pesan terakhirnya sambutan jam malam AI, atau pesan klien yang belum dibalas. Tidak pernah membalas dua kali.')]
class AiRetryStuckCommand extends Command
{
    public function handle(CurrentTenant $current, GenerateAiReply $generate): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->error('Agensi tidak ditemukan. Pakai --tenant=<slug>.');

            return self::FAILURE;
        }

        if (AiSettings::of($tenant)->quietUntil(now()->toImmutable(), $tenant->timezone ?: (string) config('app.timezone')) !== null) {
            $this->warn('Sekarang jam malam agensi ini; jalankan lagi setelah jam malam selesai.');

            return self::SUCCESS;
        }

        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        return $current->run($tenant, function () use ($generate, $minutes, $limit) {
            $stuck = $this->stuck($minutes, max(1, (int) $this->option('hours')), $limit);
            $answered = 0;

            foreach ($stuck as [$conversation, $inbound, $why]) {
                $this->line("Percakapan #{$conversation->getKey()}: {$why}");

                if ($this->option('dry-run')) {
                    continue;
                }

                try {
                    $draft = $generate($inbound->setRelation('conversation', $conversation), afterHours: true);
                } catch (Throwable $e) {
                    report($e);
                    $this->warn('  gagal: '.$e->getMessage());

                    continue;
                }

                $this->line('  => '.match (true) {
                    $draft === null => 'dilewati',
                    $draft->status === AiDraft::SENT => 'dibalas',
                    $draft->status === AiDraft::HANDOFF => 'diserahkan ke agen',
                    default => 'saran menunggu agen',
                });
                $answered += $draft !== null ? 1 : 0;
            }

            $this->info(count($stuck).' percakapan macet, '.$answered.' dijalankan ulang.');

            return self::SUCCESS;
        });
    }

    /**
     * Chats whose newest message is the AI's night greeting, or a client's message older than $minutes, with no
     * answer and no AI result for that client message yet (so nothing is answered twice), on a number with AI on.
     *
     * @return list<array{0: WaConversation, 1: WaMessage, 2: string}>
     */
    private function stuck(int $minutes, int $hours, int $limit): array
    {
        $found = [];

        $conversations = WaConversation::query()->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>=', now()->subHours($hours))
            ->where('last_inbound_at', '<=', now()->subMinutes($minutes))
            ->orderBy('last_inbound_at')->get();

        foreach ($conversations as $conversation) {
            if (count($found) >= $limit) {
                break;
            }

            $last = $conversation->messages()->whereNotIn('type', ConversationHistory::SKIPPED)->reorder()->latest('id')->first();
            $quiet = $last?->direction === 'out' && $last->origin === GenerateAiReply::QUIET_ORIGIN;

            if ($last === null || ($last->direction !== 'in' && ! $quiet)) {
                continue;
            }

            $inbound = $quiet
                ? $conversation->messages()->where('direction', 'in')->where('id', '<', $last->getKey())->reorder()->latest('id')->first()
                : $last;

            if ($inbound === null || $inbound->sent_at === null || $inbound->sent_at->gt(now()->subMinutes($minutes))
                || AiDraft::query()->where('message_id', $inbound->getKey())->exists()
                || in_array($inbound->channel?->ai_mode, [null, AiMode::Off], true)) {
                continue;
            }

            $found[] = [$conversation, $inbound, $quiet ? 'berhenti di sambutan jam malam' : 'pesan klien belum dibalas sejak '.$inbound->sent_at->diffForHumans()];
        }

        return $found;
    }
}
