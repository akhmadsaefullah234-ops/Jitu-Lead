<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\ExportLeadsCsv;
use App\Enums\Role;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\IndustryTemplate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
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
                ->fillForm(fn () => [
                    'industry' => app(CurrentTenant::class)->get()?->industry()->key,
                    'show_property_fields' => app(CurrentTenant::class)->get()?->showsIndustryFields() ?? true,
                ])
                ->schema([
                    Select::make('industry')->label('Jenis bisnis')->options(Industry::options())->required()->native(false)->live()
                        ->disabled(fn () => app(CurrentTenant::class)->get()?->isPlatformSales() ?? false)
                        ->helperText(fn () => Lead::withTrashed()->exists()
                            ? 'Agensi ini sudah punya lead: bila diganti, hanya label, kolom, dan istilah yang berubah. Tahap, alasan gugur, dan sumber lead jenis baru ditambahkan; yang lama tidak dihapus.'
                            : 'Agensi ini belum punya lead: bila diganti, tahap pipeline, alasan gugur, sumber lead, dan follow-up diganti dengan templat jenis baru.'),
                    Toggle::make('show_property_fields')->label('Tampilkan kolom khusus jenis bisnis')
                        ->helperText(fn (Get $get) => app(CurrentTenant::class)->get()?->isPlatformSales()
                            ? 'Agensi penjualan JITU LEAD selalu tanpa kolom ini.'
                            : implode(', ', Industry::make($get('industry'))->leadFields()).'. Data yang sudah tersimpan tidak dihapus.')
                        ->disabled(fn () => app(CurrentTenant::class)->get()?->isPlatformSales() ?? false),
                ])
                ->action(function (array $data) {
                    $tenant = app(CurrentTenant::class)->get();
                    $tenant->forceFill(['show_property_fields' => (bool) ($data['show_property_fields'] ?? true)])->save();

                    if (! $tenant->isPlatformSales() && ($data['industry'] ?? $tenant->industry()->key) !== $tenant->industry()->key) {
                        $full = app(IndustryTemplate::class)->switch($tenant, $data['industry']);

                        Notification::make()->title('Jenis bisnis diganti ke '.$tenant->industry()->label())->success()
                            ->body($full ? 'Templat lengkap diterapkan.' : 'Label dan kolom diganti. Tahap, alasan gugur, dan sumber lead baru ditambahkan tanpa menghapus yang lama.')
                            ->send();
                        $this->redirect(static::getUrl());

                        return;
                    }

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
