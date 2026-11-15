<?php

namespace App\Console\Commands;

use App\Ops\HealthChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** The heartbeat's checks by hand, for a deploy: prints each result, sends no ping, exits 1 when one fails. */
#[Signature('ops:check')]
#[Description('Cetak hasil pemeriksaan kesehatan operasional (tanpa ping); kode keluar 1 bila ada yang gagal')]
class OpsCheckCommand extends Command
{
    private const LABELS = [
        'database' => 'Database',
        'queue' => 'Antrean (kanari)',
        'gateway' => 'Gateway WhatsApp',
        'failed_jobs' => 'Job gagal',
        'disk' => 'Ruang disk',
    ];

    public function handle(HealthChecks $checks): int
    {
        $results = $checks->run();

        foreach ($results as $name => $result) {
            $mark = match ($result['status']) {
                'ok' => '<info>LULUS </info>',
                'fail' => '<error>GAGAL </error>',
                default => '<comment>LEWATI</comment>',
            };
            $this->line($mark.' '.(self::LABELS[$name] ?? $name).': '.$result['detail']);
        }

        $this->line('Scheduler: dibuktikan oleh ping ops:heartbeat tiap menit (lihat docs/ops-monitoring.md).');
        $this->line(filled(config('jitu.ops.heartbeat_url')) ? 'Heartbeat: OPS_HEARTBEAT_URL terisi.' : 'Heartbeat: OPS_HEARTBEAT_URL kosong, ping nonaktif.');

        return HealthChecks::failed($results) === [] ? self::SUCCESS : self::FAILURE;
    }
}
