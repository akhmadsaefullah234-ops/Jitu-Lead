<?php

namespace App\Filament\Admin\Resources\BackupRuns\Pages;

use App\Filament\Admin\Resources\BackupRuns\BackupRunResource;
use Filament\Resources\Pages\ListRecords;

class ListBackupRuns extends ListRecords
{
    protected static string $resource = BackupRunResource::class;
}
