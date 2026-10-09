<?php

namespace App\Filament\Widgets;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Models\Subscription;
use App\Support\CurrentTenant;
use Filament\Widgets\Widget;

/** Tells the agency admin about an ending trial, an overdue plan, or a limit at 80% or 100%. */
class PlanAlerts extends Widget
{
    protected string $view = 'filament.widgets.plan-alerts';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin && static::alerts() !== [];
    }

    /** @return list<array{level: 'warning'|'danger', text: string}> */
    public static function alerts(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant === null) {
            return [];
        }

        $limits = PlanLimits::for($tenant);
        $sub = $limits->subscription();
        $alerts = [];

        $status = $sub->effectiveStatus();

        if ($status === Subscription::READ_ONLY) {
            $alerts[] = ['level' => 'danger', 'text' => ($sub->neverPaid() ? 'Masa percobaan berakhir. ' : 'Langganan berakhir. ').'Data Anda aman dan bisa dilihat, tetapi tidak bisa ditambah atau diubah, dan AI serta follow-up otomatis berhenti. Pilih paket untuk memakai CRM lagi.'];
        } elseif ($status === Subscription::PAST_DUE) {
            $alerts[] = ['level' => 'danger', 'text' => 'Langganan jatuh tempo. Perpanjang sebelum masa tenggang berakhir agar akun tidak menjadi hanya-baca.'];
        } elseif (($days = $sub->daysLeft()) !== null && $days <= (int) config('plans.reminder_days') + 4) {
            $what = $sub->status === Subscription::TRIAL ? 'Masa percobaan' : 'Langganan';
            $alerts[] = ['level' => 'warning', 'text' => "$what berakhir dalam $days hari."];
        }

        foreach ($limits->warnings() as $w) {
            $alerts[] = $w['percent'] >= 100
                ? ['level' => 'danger', 'text' => "{$w['label']} sudah penuh ({$w['usage']} dari {$w['limit']}).".($w['key'] === 'leads' ? ' Lead baru tetap masuk.' : '').($w['key'] === 'ai' ? ' AI menyerahkan chat ke agen sampai kuota bertambah.' : '')]
                : ['level' => 'warning', 'text' => "{$w['label']} sudah {$w['percent']}% terpakai ({$w['usage']} dari {$w['limit']})."];
        }

        return $alerts;
    }

    public function getViewData(): array
    {
        return ['alerts' => static::alerts(), 'url' => SubscriptionPage::getUrl()];
    }
}
