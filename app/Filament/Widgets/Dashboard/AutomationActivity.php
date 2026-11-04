<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Concerns\UsesPagePeriod;
use Filament\Widgets\Widget;

/** What the AI, the follow-ups, the form welcomes and the ad conversions did in the period. */
class AutomationActivity extends Widget
{
    use UsesPagePeriod;

    protected string $view = 'filament.widgets.dashboard.automation';

    protected static ?int $sort = 7;

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $a = $this->data()->automation($this->period());

        return ['groups' => [
            'AI' => [
                'Balasan AI terkirim' => $a['ai_replies'],
                'Draf menunggu persetujuan (sekarang)' => $a['drafts_waiting'],
                'Diserahkan ke admin' => $a['handoffs'],
                'Voice note belum dibalas' => $a['voice_unanswered'],
            ],
            'Pesan otomatis' => [
                'Follow-up terkirim' => $a['follow_ups_sent'],
                'Follow-up dilewati' => $a['follow_ups_skipped'],
                'Sambutan formulir terkirim' => $a['welcome_sent'],
                'Sambutan dilewati / gagal' => $a['welcome_skipped'].' / '.$a['welcome_failed'],
                '"Tanya produk" terkirim / dijawab' => $a['ask_sent'].' / '.$a['ask_answered'],
            ],
            'Iklan' => [
                'Event konversi ke Meta terkirim' => $a['meta_sent'],
                'Event konversi ke Meta gagal' => $a['meta_failed'],
            ],
        ]];
    }
}
