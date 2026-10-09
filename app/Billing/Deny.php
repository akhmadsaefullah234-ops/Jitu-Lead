<?php

namespace App\Billing;

use App\Filament\Pages\Subscription;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Tells the user why a plan limit stopped them, with a way to upgrade. */
class Deny
{
    public static function notify(string $message): void
    {
        Notification::make()->title('Batas paket tercapai')->body($message)->warning()->persistent()
            ->actions([Action::make('upgrade')->label('Lihat paket')->url(Subscription::getUrl())->button()])
            ->send();
    }
}
