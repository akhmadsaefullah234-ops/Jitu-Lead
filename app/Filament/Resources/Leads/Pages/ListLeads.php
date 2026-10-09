<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\ExportLeadsCsv;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
