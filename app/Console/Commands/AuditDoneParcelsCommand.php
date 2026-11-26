<?php

namespace App\Console\Commands;

use App\Actions\MoveLeadToStage;
use App\Automation\RetailStages;
use App\Automation\StageAutomation;
use App\Enums\Role;
use App\Enums\StageType;
use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Stage;
use App\Models\Tenant;
use App\Shipping\Carriers\ShipmentStatus;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Leads a shop has in a won stage (Selesai) whose newest active parcel the courier reported as not arrived
 * (delivery_failed, returning, returned): before the fix, the time-based "N hari setelah nomor resi dikirim" rule
 * could close them. Lists each one with how it got there and the conversion events sent since, so the owner can
 * review. Nothing is changed without --apply, which moves the listed leads (or only --lead=…) back to
 * "Dikemas & dikirim" with a note. Conversion events already sent to ad platforms cannot be taken back.
 */
#[Signature('stages:audit-done {--tenant= : ID atau slug agensi} {--apply : pindahkan lead yang terdaftar kembali ke Dikemas & dikirim (tanpa ini hanya daftar)} {--lead=* : hanya lead dengan ID ini (boleh berulang)}')]
#[Description('Daftar lead Selesai yang paketnya gagal antar atau diretur. Hanya daftar kecuali --apply.')]
class AuditDoneParcelsCommand extends Command
{
    public function handle(CurrentTenant $current, MoveLeadToStage $move): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))
            ->when(ctype_digit((string) $this->option('tenant')), fn ($q) => $q->orWhere('id', (int) $this->option('tenant')))->first();

        if ($tenant === null) {
            $this->error('Agensi tidak ditemukan. Pakai --tenant=<id atau slug>.');

            return self::FAILURE;
        }

        return $current->run($tenant, function () use ($tenant, $move) {
            $only = array_values(array_filter(array_map('intval', (array) $this->option('lead'))));
            $rows = self::find($only);

            if ($rows === []) {
                $this->info('Tidak ada lead Selesai dengan paket gagal antar atau diretur.');

                return self::SUCCESS;
            }

            $this->table(['Lead', 'Nama', 'Status kurir', 'Masuk Selesai', 'Cara', 'Event konversi sejak itu'], array_map(fn (array $r) => [
                $r['lead']->id, $r['lead']->name, ShipmentStatus::label($r['status']), $r['entered_at'] ?? '-', $r['how'],
                $r['events'] === [] ? '-' : implode(', ', $r['events']),
            ], $rows));

            $sent = array_sum(array_map(fn ($r) => count($r['events']), $rows));
            $this->line(count($rows).' lead. '.($sent > 0
                ? "{$sent} event konversi sudah terkirim ke platform iklan setelah lead masuk Selesai; event itu tidak bisa ditarik kembali, hanya tidak dikirim ulang."
                : 'Tidak ada event konversi yang terkirim setelah lead masuk Selesai.'));

            if (! $this->option('apply')) {
                $this->comment('Hanya daftar. Tambahkan --apply (boleh dengan --lead=ID) untuk memindahkan lead ini kembali ke Dikemas & dikirim.');

                return self::SUCCESS;
            }

            $target = (new RetailStages)->stage(RetailStages::SHIPPING);

            if ($target === null) {
                $this->error('Tahap Dikemas & dikirim tidak ditemukan.');

                return self::FAILURE;
            }

            $moved = 0;

            foreach ($rows as $r) {
                $lead = $r['lead'];
                $actor = $lead->owner ?? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->first();

                try {
                    $move($lead, $target, $actor, [], 'paket '.mb_strtolower(ShipmentStatus::label($r['status'])).', dikembalikan dari Selesai setelah ditinjau');
                    $moved++;
                } catch (Throwable $e) {
                    $this->error("Lead {$lead->id}: {$e->getMessage()}");
                }
            }

            $this->info("{$moved} lead dipindah ke {$target->name}.");

            return self::SUCCESS;
        });
    }

    /**
     * @param  list<int>  $only
     * @return list<array{lead: Lead, status: string, entered_at: ?string, how: string, events: list<string>}>
     */
    public static function find(array $only = []): array
    {
        $won = Stage::query()->where('type', StageType::Won->value)->pluck('id');
        $out = [];

        foreach (Lead::query()->whereIn('stage_id', $won)->when($only !== [], fn ($q) => $q->whereIn('id', $only))->whereHas('shipments')->orderBy('id')->get() as $lead) {
            $status = StageAutomation::parcelProblem($lead);

            if ($status === null) {
                continue;
            }

            $entered = LeadActivity::query()->where('lead_id', $lead->getKey())->where('type', 'stage_changed')->latest('id')->get()
                ->first(fn (LeadActivity $a) => (int) (((array) $a->meta)['to'] ?? 0) === (int) $lead->stage_id);
            $since = $entered?->created_at ?? $lead->stage_entered_at;

            $out[] = [
                'lead' => $lead,
                'status' => $status,
                'entered_at' => $since?->copy()->timezone(app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta')->format('d-m-Y H:i'),
                'how' => $entered === null ? '-' : (str_starts_with((string) $entered->body, 'Pindah otomatis') ? 'otomatis: '.(((array) $entered->meta)['auto'] ?? '') : 'manual'),
                'events' => $since === null ? [] : ConversionEvent::query()->where('lead_id', $lead->getKey())->where('created_at', '>=', $since)
                    ->orderBy('id')->get()->map(fn (ConversionEvent $e) => $e->event_name.' ('.$e->created_at->format('d-m H:i').')')->all(),
            ];
        }

        return $out;
    }
}
