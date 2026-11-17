<?php

namespace App\Console\Commands;

use App\Ai\AnthropicClient;
use App\Ai\Evaluation\AiHealth;
use App\Ai\Evaluation\Evaluator;
use App\Ai\Evaluation\Scenarios;
use App\Models\AiEvaluation;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:evaluate {--tenant= : slug agensi} {--only= : nomor skenario, dipisah koma (mis. 1,8,20)} {--quick : 10 pertanyaan "Uji AI saya"} {--record= : simpan hasil untuk Kesehatan AI (manual, quick, knowledge, nightly)} {--json : hasil dalam JSON}')]
#[Description('Uji AI WhatsApp satu agensi properti dengan 20 skenario playbook ditambah pertanyaan dari data agensi itu sendiri (tanpa mengirim apa pun dan tanpa menyimpan chat); lulus/gagal per skenario')]
class AiEvaluateCommand extends Command
{
    public function handle(Evaluator $evaluator, AnthropicClient $ai): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->error('Agensi tidak ditemukan. Pakai --tenant=<slug>.');

            return self::FAILURE;
        }

        if (! $ai->configured()) {
            $this->error('Kunci API AI belum diatur (services.anthropic.key).');

            return self::FAILURE;
        }

        if ($tenant->industry()->key !== 'properti') {
            $this->warn("{$tenant->name} bukan agensi properti; skenario ini ditulis untuk properti.");
        }

        $scenarios = $this->option('quick') ? Scenarios::quick($tenant) : Scenarios::for($tenant);
        $only = filled($this->option('only'))
            ? array_values(array_filter(array_map('intval', explode(',', (string) $this->option('only'))), fn (int $n) => $n >= 1 && $n <= count($scenarios)))
            : null;

        $results = $evaluator->run($tenant, $only, $scenarios);

        if (filled($trigger = $this->option('record')) && array_key_exists((string) $trigger, AiEvaluation::TRIGGERS)) {
            AiHealth::record($tenant, (string) $trigger, $results);
        }

        $passed = count(array_filter($results, fn (array $r) => $r['passed']));

        if ($this->option('json')) {
            $this->line((string) json_encode(['tenant' => $tenant->slug, 'passed' => $passed, 'total' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['No', 'Skenario', 'Hasil', 'AI', 'Catatan'], array_map(fn (array $r) => [
                $r['no'], $r['title'], $r['passed'] ? 'LULUS' : 'GAGAL', $r['outcome'], implode('; ', $r['problems']),
            ], $results));

            foreach (array_filter($results, fn (array $r) => ! $r['passed'] && $r['reply'] !== null) as $r) {
                $this->line("#{$r['no']} jawaban AI: ".str_replace("\n", ' / ', $r['reply']));
            }

            $this->info("{$passed} dari ".count($results).' skenario lulus.');
        }

        return $passed === count($results) ? self::SUCCESS : self::FAILURE;
    }
}
