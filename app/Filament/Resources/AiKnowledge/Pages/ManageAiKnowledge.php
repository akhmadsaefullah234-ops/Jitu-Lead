<?php

namespace App\Filament\Resources\AiKnowledge\Pages;

use App\Ai\AnthropicClient;
use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageAiKnowledge extends ManageRecords
{
    protected static string $resource = AiKnowledgeResource::class;

    public function getSubheading(): ?string
    {
        return app(AnthropicClient::class)->configured()
            ? 'Semua yang AI ketahui tentang agensi Anda ada di sini. AI hanya menjawab dari catatan ini.'
            : 'AI belum aktif: kunci API (ANTHROPIC_API_KEY) belum diisi di server. Catatan di sini tetap tersimpan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')->label('Arahan & serah-terima')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
                ->modalHeading('Arahan untuk AI')
                ->fillForm(fn () => app(CurrentTenant::class)->get()->only(['ai_instructions', 'ai_handoff_keywords']))
                ->schema([
                    Textarea::make('ai_instructions')->label('Gaya dan aturan khusus')->rows(4)->maxLength(2000)
                        ->placeholder('Contoh: Sapa dengan "Kak". Jangan sebut harga sebelum tahu budget. Arahkan ke survei lokasi.'),
                    Textarea::make('ai_handoff_keywords')->label('Kata yang membuat AI menyerahkan chat ke agen')->rows(3)->maxLength(1000)
                        ->helperText('Pisahkan dengan koma. Sudah termasuk: nego, komplain, refund, minta ditelepon, dan sejenisnya.'),
                ])
                ->action(function (array $data) {
                    app(CurrentTenant::class)->get()->update([
                        'ai_instructions' => $data['ai_instructions'] ?: null,
                        'ai_handoff_keywords' => $data['ai_handoff_keywords'] ?: null,
                    ]);
                    Notification::make()->title('Arahan disimpan')->success()->send();
                }),
            CreateAction::make(),
        ];
    }
}
