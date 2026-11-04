<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A public form submission rejected as a robot (App\LeadForms\SpamGuard). Holds no personal data. */
#[Fillable(['tenant_id', 'lead_form_id', 'reason'])]
class LeadFormRejection extends Model
{
    public const UPDATED_AT = null;

    /** @return array<int, int> lead_form_id => rejections in the last 7 days, for one agency */
    public static function lastWeekPerForm(int $tenantId): array
    {
        return static::query()->where('tenant_id', $tenantId)->whereNotNull('lead_form_id')->where('created_at', '>=', now()->subDays(7))
            ->groupBy('lead_form_id')->selectRaw('lead_form_id, count(*) as n')->pluck('n', 'lead_form_id')->map(fn ($n) => (int) $n)->all();
    }
}
