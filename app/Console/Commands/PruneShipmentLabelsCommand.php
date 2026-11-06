<?php

namespace App\Console\Commands;

use App\Models\LabelBatch;
use App\Models\Shipment;
use App\Shipping\Shipments\LabelSheet;
use App\Shipping\Shipments\LabelStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('shipping:labels-prune')]
#[Description('Hapus PDF cetak label dan label kiriman yang lebih lama dari batas simpan')]
class PruneShipmentLabelsCommand extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;

        // Print runs (merged PDFs), kept to download again from the history.
        $before = now()->subDays((int) config('carriers.label_batch_keep_days', 30))->getTimestamp();

        foreach ($disk->allFiles(LabelSheet::DIR) as $path) {
            if ($disk->lastModified($path) < $before) {
                $disk->delete($path);
                $deleted++;
            }
        }

        // Each parcel's own label: deleted with its date folder; printing again fetches it from the provider.
        $cutoff = now()->subDays((int) config('carriers.label_store_days', 90))->toDateString();

        foreach ($disk->directories(LabelStore::DIR) as $tenantDir) {
            foreach ($disk->directories($tenantDir) as $dayDir) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})$/', $dayDir, $m) && $m[1] < $cutoff) {
                    $deleted += count($disk->allFiles($dayDir));
                    $disk->deleteDirectory($dayDir);
                    Shipment::withoutGlobalScopes()->where('label_path', 'like', $dayDir.'/%')->update(['label_path' => null, 'label_saved_at' => null]);
                }
            }
        }

        LabelBatch::withoutGlobalScopes()->where('created_at', '<', now()->subDays((int) config('carriers.label_batch_keep_days', 30) + 1))->delete();

        $this->info("{$deleted} file dihapus.");

        return self::SUCCESS;
    }
}
