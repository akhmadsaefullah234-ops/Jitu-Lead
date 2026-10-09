<?php

namespace App\Filament\Resources\Stages\Pages;

use App\Filament\Resources\Stages\StageResource;
use App\Models\Stage;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStages extends ManageRecords
{
    protected static string $resource = StageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(function (array $data) {
                $data['position'] = ((int) Stage::query()->max('position')) + 1;

                return $data;
            }),
        ];
    }
}
