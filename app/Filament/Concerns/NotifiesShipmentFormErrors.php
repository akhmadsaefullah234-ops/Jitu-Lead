<?php

namespace App\Filament\Concerns;

use App\Support\CurrentTenant;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * "Buat resi" never fails silently: when a field of its modal is not valid (perhaps scrolled out of sight), the
 * agent also gets a notification listing every error, and the stop is logged without any of the buyer's data.
 * Filament itself scrolls to the first field in error.
 */
trait NotifiesShipmentFormErrors
{
    public function exceptionNotifiesShipmentFormErrors($e, $stopPropagation): void
    {
        $action = method_exists($this, 'getMountedAction') ? $this->getMountedAction() : null;

        if (! $e instanceof ValidationException || $action === null || ! in_array($action->getName(), ['createShipment'], true)) {
            return;
        }

        $errors = collect($e->errors())->flatten()->unique()->take(10)->all();
        Notification::make()->title('Periksa isian Buat resi')
            ->body(new HtmlString(implode('<br>', array_map(fn ($m) => '• '.e($m), $errors))))->danger()->send();
        Log::warning('Buat resi stopped by the form', ['tenant' => app(CurrentTenant::class)->id(), 'fields' => array_map(fn ($k) => last(explode('.', $k)), array_keys($e->errors()))]);
    }
}
