<?php

namespace App\Support;

use App\Filament\Admin\Resources\SubscriptionRequests\SubscriptionRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Jobs\SendPushAlert;
use App\Mail\NewAgencyRegistered;
use App\Mail\SubscriptionRequested;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Push\PushTransport;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the platform owner (SUPPORT_EMAIL and every super admin with push on) about a new agency
 * and a new plan request. Everything goes through the queue, a failure only gets reported so it
 * never breaks the sign-up or the request, and NOTIFY_SIGNUPS=false switches it all off.
 */
class OwnerAlerts
{
    public static function newAgency(Tenant $tenant, User $registrant): void
    {
        self::guard(function () use ($tenant, $registrant) {
            $url = TenantResource::getUrl('index', ['tableSearch' => $tenant->name], panel: 'admin');
            $trialEnds = $tenant->trial_ends_at?->timezone(config('app.timezone'))->format('d M Y');

            self::email(new NewAgencyRegistered($tenant->name, array_filter([
                'Agensi' => $tenant->name,
                'Pendaftar' => $registrant->name,
                'Email' => $registrant->email,
                'Mode pendaftaran' => self::mode(),
                'Percobaan berakhir' => $trialEnds,
            ], 'filled'), $url));

            self::push("Agensi baru: {$tenant->name} ({$registrant->email})", 'Buka daftar agensi', $url, 'agency-'.$tenant->getKey());
        });
    }

    public static function planRequested(SubscriptionRequest $request): void
    {
        self::guard(function () use ($request) {
            $tenant = $request->tenant;
            $plan = config("plans.plans.{$request->plan}.name", $request->plan);
            $cycle = $request->billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan';
            $total = 'Rp '.number_format((int) $request->amount, 0, ',', '.');
            $url = SubscriptionRequestResource::getUrl('index', panel: 'admin');

            $addons = collect(config('plans.addons'))
                ->map(fn ($a, $kind) => ($qty = (int) $tenant->currentSubscription()->{'addon_'.$kind}) > 0 ? "{$a['label']} x {$qty}" : null)
                ->filter()->implode(', ');

            self::email(new SubscriptionRequested($tenant->name, $plan, [
                'Agensi' => $tenant->name,
                'Paket' => $plan,
                'Periode' => $cycle,
                'Add-on saat ini' => $addons ?: 'tidak ada',
                'Total yang harus ditransfer' => $total,
            ], $url));

            self::push("Permintaan paket {$plan} dari {$tenant->name} — {$total}", 'Buka permintaan langganan', $url, 'plan-request-'.$request->getKey());
        });
    }

    private static function mode(): string
    {
        return match (config('jitu.registration')) {
            'invite' => 'Undangan (pakai kode undangan)',
            'closed' => 'Akun dibuat operator',
            default => 'Percobaan gratis (pendaftaran terbuka)',
        };
    }

    private static function email(object $mailable): void
    {
        if ($to = config('jitu.support_email')) {
            Mail::to($to)->send($mailable);
        }
    }

    private static function push(string $title, string $body, string $url, string $tag): void
    {
        if (! app(PushTransport::class)->enabled()) {
            return;
        }

        $ids = User::query()->where('is_super_admin', true)->pluck('id')->all();

        if ($ids !== []) {
            SendPushAlert::dispatch($ids, ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag]);
        }
    }

    private static function guard(callable $send): void
    {
        if (! config('jitu.notify_signups')) {
            return;
        }

        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
