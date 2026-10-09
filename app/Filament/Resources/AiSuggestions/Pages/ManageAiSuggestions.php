<?php

namespace App\Filament\Resources\AiSuggestions\Pages;

use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAiSuggestions extends ManageRecords
{
    protected static string $resource = AiSuggestionResource::class;

    public function getSubheading(): ?string
    {
        return 'Jawaban agen yang bisa menjadi pengetahuan AI. Periksa dan hapus data pribadi sebelum menyetujui.';
    }
}
