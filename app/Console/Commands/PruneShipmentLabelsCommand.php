<?php

namespace App\Console\Commands;

use App\Shipping\Shipments\LabelSheet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('shipping:labels-prune')]
#[Description('Hapus PDF gabungan "Cetak label" yang lebih lama dari batas simpan')]
class PruneShipmentLabelsCommand extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('local');
        $before = now()->subHours((int) config('carriers.label_keep_hours', 24))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(LabelSheet::DIR) as $path) {
            if ($disk->lastModified($path) < $before) {
                $disk->delete($path);
                $deleted++;
            }
        }

        $this->info("{$deleted} file dihapus.");

        return self::SUCCESS;
    }
}
