<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\LabelBatch;
use App\Models\Shipment;
use App\Shipping\Shipments\LabelSheet;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    public function getTabs(): array
    {
        $tabs = [];

        foreach (Shipment::TABS as $key => $label) {
            $count = ShipmentResource::getEloquentQuery()->tab($key)->count();
            // Unprinted: oldest first, the order they are printed in; the others newest first.
            $tabs[$key] = Tab::make($label)->badge($count ?: null)->badgeColor($key === 'action' ? 'danger' : ($key === 'unprinted' ? 'warning' : 'gray'))
                ->modifyQueryUsing(fn (Builder $query) => $query->tab($key)->orderBy('created_at', $key === 'unprinted' ? 'asc' : 'desc'));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'unprinted';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printAll')
                ->label(fn () => 'Cetak semua yang belum dicetak ('.$this->unprinted()->count().')')
                ->icon(Heroicon::OutlinedPrinter)->size('lg')
                ->disabled(fn () => $this->unprinted()->count() === 0)
                ->modalHeading('Cetak semua label yang belum dicetak')
                ->modalDescription('Satu PDF berisi semua label, urut dari resi terlama. Setelah PDF jadi, kiriman pindah ke tab "Sudah dicetak".')
                ->modalSubmitActionLabel('Cetak')
                ->schema([ShipmentResource::packingToggle()])
                ->action(fn (array $data) => ShipmentResource::printNow($this->unprinted()->with('lead')->orderBy('created_at')->get(), $this, (bool) ($data['packing_list'] ?? false))),
            Action::make('history')->label('Riwayat cetak')->icon(Heroicon::OutlinedClock)->color('gray')
                ->modalHeading('Riwayat cetak label')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                ->schema([Text::make(fn () => $this->history())]),
        ];
    }

    private function unprinted(): Builder
    {
        return ShipmentResource::getEloquentQuery()->tab('unprinted');
    }

    /** The last print runs, each with a fresh link while its PDF is kept. Agents see their own runs. */
    private function history(): HtmlString
    {
        $current = app(CurrentTenant::class);
        $tenant = $current->get();
        $tz = $tenant?->timezone ?: 'Asia/Jakarta';
        $batches = LabelBatch::query()->with('user:id,name')
            ->when(! ($current->roleOf(auth()->user())?->seesAllLeads() ?? false), fn ($q) => $q->where('user_id', auth()->id()))
            ->latest('id')->limit(30)->get();

        if ($batches->isEmpty()) {
            return new HtmlString('<span style="color:var(--gray-500)">Belum ada cetak label.</span>');
        }

        $rows = $batches->map(fn (LabelBatch $b) => '<tr><td style="padding:.3rem .5rem">'.e($b->created_at->timezone($tz)->format('d M Y H:i')).'</td>'
            .'<td style="padding:.3rem .5rem">'.e($b->count).' label'.($b->packing_list ? ' + daftar' : '').'</td>'
            .'<td style="padding:.3rem .5rem">'.e($b->user?->name ?? 'Otomatis').'</td>'
            .'<td style="padding:.3rem .5rem">'.($b->available() ? '<a href="'.e(LabelSheet::link($tenant, $b->file)).'" target="_blank" style="text-decoration:underline">Unduh</a>' : '<span style="color:var(--gray-500)">sudah dihapus</span>').'</td></tr>')
            ->implode('');

        return new HtmlString('<table style="width:100%;font-size:.875rem"><thead><tr style="text-align:left"><th style="padding:.3rem .5rem">Waktu</th><th style="padding:.3rem .5rem">Jumlah</th><th style="padding:.3rem .5rem">Oleh</th><th></th></tr></thead><tbody>'.$rows.'</tbody></table>');
    }
}
