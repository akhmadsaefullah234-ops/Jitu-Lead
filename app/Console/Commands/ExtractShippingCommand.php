<?php

namespace App\Console\Commands;

use App\Automation\RetailStages;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:extract-shipping {--tenant= : ID atau slug agensi} {--stage=* : tahap (nama atau kunci: order, payment, shipping); bawaan Order masuk, Menunggu pembayaran, Dikemas & dikirim} {--apply : simpan perubahan (tanpa ini hanya pratinjau)} {--all : juga lead yang alamatnya sudah lengkap} {--lead=* : hanya lead dengan ID ini (boleh berulang)}')]
#[Description('Isi data pengiriman lead (alamat, bagian alamat, varian, jumlah, cara bayar) dari chat WhatsApp-nya. Pratinjau kecuali --apply.')]
class ExtractShippingCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))
            ->when(ctype_digit((string) $this->option('tenant')), fn ($q) => $q->orWhere('id', (int) $this->option('tenant')))->first();

        if ($tenant === null) {
            $this->error('Agensi tidak ditemukan. Pakai --tenant=<id atau slug>.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        return $current->run($tenant, function () use ($apply) {
            $stages = $this->stages();

            if ($stages === [] && $this->option('lead') === []) {
                $this->error('Tahap tidak ditemukan.');

                return self::FAILURE;
            }

            $only = array_values(array_filter(array_map('intval', (array) $this->option('lead'))));
            $leads = Lead::query()->whereNull('tracking_number')->orderBy('id')
                ->when($only !== [], fn ($q) => $q->whereIn('id', $only), fn ($q) => $q->whereIn('stage_id', $stages))->get();
            $leads = $this->option('all') || $only !== [] ? $leads : ChatShippingDetails::incomplete($leads);
            $service = app(ChatShippingDetails::class);
            $rows = [];
            $changed = 0;

            foreach ($leads as $lead) {
                $result = $service->fromConversation($lead, save: $apply);
                $labels = $result['labels'] ?? [];
                $changed += $labels === [] ? 0 : 1;
                $lead = $apply ? $lead->fresh() : $lead->forceFill($result['changes'] ?? []);

                // Never the address itself: the lead number and the last 4 digits of the phone.
                [$before, $after] = [$result['parts_before'] ?? 0, $result['parts_after'] ?? 0];
                $rows[] = [$lead->getKey(), '…'.substr((string) $lead->phone, -4), $labels === [] ? '-' : implode(', ', $labels),
                    ($result['previous'] ?? []) !== [] ? 'ya' : '', $before.' → '.$after.($after < $before ? ' ⚠ berkurang' : ''),
                    ChatShippingDetails::complete($lead) ? 'lengkap' : 'belum'];
            }

            $this->table(['Lead', 'HP', 'Diisi dari chat', 'Alamat diganti', 'Bagian alamat', 'Alamat'], $rows);
            $fewer = count(array_filter($rows, fn ($r) => str_contains($r[4], 'berkurang')));
            $this->info(sprintf('%d lead diperiksa, %d dengan data baru dari chat%s. %s', count($rows), $changed, $fewer > 0 ? ", {$fewer} dengan bagian alamat berkurang" : '',
                $apply ? 'Perubahan disimpan.' : 'Pratinjau: belum ada yang disimpan. Jalankan lagi dengan --apply untuk menyimpan.'));

            return self::SUCCESS;
        });
    }

    /** @return list<int> */
    private function stages(): array
    {
        $wanted = array_filter((array) $this->option('stage')) ?: [RetailStages::ORDER, RetailStages::PAYMENT, RetailStages::SHIPPING];
        $retail = new RetailStages;
        $ids = [];

        foreach ($wanted as $name) {
            $stage = in_array($name, array_values(RetailStages::TEMPLATE), true) ? $retail->stage($name)
                : Stage::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first();

            if ($stage !== null) {
                $ids[] = (int) $stage->getKey();
            }
        }

        return array_values(array_unique($ids));
    }
}
