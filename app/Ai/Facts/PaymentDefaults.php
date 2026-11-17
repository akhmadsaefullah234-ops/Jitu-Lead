<?php

namespace App\Ai\Facts;

use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\Tenant;

/**
 * Jitu's own wording that names a way to pay, fitted to the agency's payment model when the owner saves it: the
 * follow-up that asks how the client will pay lists only the ways offered, the stage actions say "KPR" only when KPR is
 * offered ("simulasi pembayaran" otherwise), and the lost reason "KPR ditolak" exists only then. Only for an agency made
 * with these defaults (tenants.pipeline_settings.payment_defaults, set for new property agencies), and only text that
 * is still Jitu's: what the agency wrote itself is never touched. Must run inside the tenant.
 */
final class PaymentDefaults
{
    public const FLAG = 'jitu';

    /** [stage name, action with KPR, action without] */
    public const ACTIONS = [
        ['Sudah survei', 'Kirim penawaran dan simulasi KPR', 'Kirim penawaran dan simulasi pembayaran'],
        ['Booking', 'Lengkapi berkas, proses KPR', 'Lengkapi berkas, proses pembayaran'],
    ];

    public const FOLLOW_UP = 'Dihubungi H+2';

    /** The follow-up as the industry template writes it, and the start every fitted version keeps. */
    public const FOLLOW_UP_ORIGINAL = 'Halo Kak {nama}, kalau boleh tahu rencananya mau bayar tunai, cicilan developer, atau KPR?';

    private const FOLLOW_UP_START = 'Halo Kak {nama}, kalau boleh tahu rencananya mau bayar ';

    public const KPR_REASON = 'KPR ditolak';

    public static function applies(?Tenant $tenant): bool
    {
        return $tenant !== null && $tenant->industry()->key === 'properti' && data_get($tenant->pipeline_settings, 'payment_defaults') === self::FLAG;
    }

    /** Marks a new property agency (App\Support\IndustryTemplate). */
    public static function provision(Tenant $tenant): void
    {
        if ($tenant->industry()->key === 'properti' && data_get($tenant->pipeline_settings, 'payment_defaults') === null) {
            $tenant->forceFill(['pipeline_settings' => array_merge((array) $tenant->pipeline_settings, ['payment_defaults' => self::FLAG])])->save();
        }
    }

    public static function adapt(Tenant $tenant, PaymentModel $model): void
    {
        if (! self::applies($tenant) || ! $model->filled()) {
            return;
        }

        $kpr = $model->offers('kpr');

        foreach (self::ACTIONS as [$stage, $with, $without]) {
            Stage::query()->where('name', $stage)->whereIn('default_action', [$with, $without])->update(['default_action' => $kpr ? $with : $without]);
        }

        FollowUpRule::query()->where('name', self::FOLLOW_UP)->where('body', 'like', self::FOLLOW_UP_START.'%')
            ->update(['body' => self::FOLLOW_UP_START.$model->choices().'?']);

        $reason = LostReason::query()->whereRaw('lower(label) = ?', [mb_strtolower(self::KPR_REASON)])->first();

        if ($kpr && $reason === null) {
            LostReason::query()->create(['label' => self::KPR_REASON]);
        } elseif (! $kpr && $reason !== null && ! Lead::withTrashed()->where('lost_reason_id', $reason->getKey())->exists()) {
            $reason->delete();
        }
    }
}
