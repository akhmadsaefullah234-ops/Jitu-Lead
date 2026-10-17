<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\ExportLeadsCsv;
use App\Enums\Role;
use App\Filament\Resources\Leads\LeadResource;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Ekspor CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->tooltip('Unduh lead sesuai pencarian dan filter yang sedang aktif')
                ->action(fn () => $this->exportCsv()),
            Action::make('agencySettings')->label('Pengaturan agensi')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->visible(fn () => app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin)
                ->modalHeading('Pengaturan agensi')
                ->modalDescription('Untuk agensi yang tidak menjual properti, kolom khusus properti bisa disembunyikan dari formulir dan daftar lead. Data yang sudah tersimpan tidak dihapus.')
                ->fillForm(fn () => ['show_property_fields' => app(CurrentTenant::class)->get()?->showsPropertyFields() ?? true])
                ->schema([
                    Toggle::make('show_property_fields')->label('Tampilkan kolom properti')
                        ->helperText(fn () => app(CurrentTenant::class)->get()?->isPlatformSales()
                            ? 'Agensi penjualan JITU LEAD selalu tanpa kolom properti.'
                            : 'Properti diminati, tipe properti, budget, lokasi incaran, cara bayar, dan kebutuhan.')
                        ->disabled(fn () => app(CurrentTenant::class)->get()?->isPlatformSales() ?? false),
                ])
                ->action(function (array $data) {
                    app(CurrentTenant::class)->get()?->forceFill(['show_property_fields' => (bool) ($data['show_property_fields'] ?? true)])->save();

                    Notification::make()->title('Pengaturan disimpan')->success()->send();
                }),
            CreateAction::make(),
        ];
    }

    /** Exports what the list currently shows (search and filters applied), limited to the leads this user may see. */
    public function exportCsv()
    {
        $query = $this->getFilteredTableQuery();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            app(ExportLeadsCsv::class)($query, $out);
            fclose($out);
        }, 'lead-'.now()->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
