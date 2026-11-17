<?php

namespace App\Console\Commands;

use App\Ai\AnthropicClient;
use App\Ai\Evaluation\AiHealth;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('ai:health')]
#[Description('Kesehatan AI: uji ulang AI agensi properti yang pengetahuannya berubah, dan tiap malam (bila "Uji otomatis" menyala)')]
class AiHealthCommand extends Command
{
    public function handle(AnthropicClient $ai): int
    {
        if (! $ai->configured()) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            if (($trigger = AiHealth::due($tenant, $now)) === null) {
                continue;
            }

            // Its own process: the evaluation holds back queued jobs, mail and outgoing HTTP while it runs.
            $result = Process::timeout(900)->run([PHP_BINARY, base_path('artisan'), 'ai:evaluate', '--tenant='.$tenant->slug, '--record='.$trigger]);
            $this->line("{$tenant->slug}: {$trigger}, ".($result->successful() ? 'semua lulus' : 'ada yang gagal'));
        }

        return self::SUCCESS;
    }
}
