<?php

namespace App\Retention;

use App\Actions\RegisterLead;
use App\Enums\StageType;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a lead for every agency on trial in the platform owner's own agency (PLATFORM_SALES_TENANT), so the
 * owner sells to them with the CRM itself: the agency's follow-up rules reach the lead on WhatsApp while it
 * sits in "Trial", and stop by themselves once it moves to "Berlangganan" (a won stage) or "Tidak lanjut".
 * Only agencies whose registrant agreed to be contacted are added. Nothing here ever breaks a sign-up or billing.
 */
class PlatformLeads
{
    public const SOURCE = 'Pendaftaran JITU LEAD';

    public const TRIAL = 'Trial';

    public const SUBSCRIBED = 'Berlangganan';

    public const DROPPED = 'Tidak lanjut';

    public const LOST_REASON = 'Trial berakhir tanpa bayar';

    public function __construct(private CurrentTenant $current, private RegisterLead $register) {}

    public static function salesTenant(): ?Tenant
    {
        $slug = config('jitu.platform_sales_tenant');

        return filled($slug) ? Tenant::query()->where('slug', $slug)->first() : null;
    }

    /** Adds the agency as a lead, once. $registrant is the person who signed it up. */
    public function capture(Tenant $agency, User $registrant): ?Lead
    {
        return $this->guard(function () use ($agency, $registrant) {
            $sales = self::salesTenant();

            if ($sales === null || $sales->is($agency) || $agency->sales_lead_id !== null || $registrant->contact_consent_at === null) {
                return null;
            }

            return DB::transaction(fn () => $this->current->run($sales, function () use ($sales, $agency, $registrant) {
                $stages = $this->stages($sales);

                [$lead] = ($this->register)([
                    'name' => $registrant->name,
                    'phone' => $registrant->whatsapp,
                    'email' => $registrant->email,
                    'note' => 'Mendaftarkan agensi '.$agency->name,
                ], self::SOURCE, 'manual');

                $lead->forceFill(['custom_fields' => array_replace((array) $lead->custom_fields, ['jitu' => $this->details($agency)])]);
                $this->move($lead, $stages[$this->target($agency)], 'pendaftaran agensi');

                $agency->forceFill(['sales_lead_id' => $lead->getKey()])->save();

                return $lead;
            }));
        });
    }

    /** Moves the agency's lead to the stage its subscription is in now: Trial, Berlangganan or Tidak lanjut. */
    public function sync(Tenant $agency): void
    {
        if ($agency->sales_lead_id === null) {
            return;
        }

        $this->guard(function () use ($agency) {
            $lead = Lead::withoutGlobalScopes()->find($agency->sales_lead_id);
            $sales = $lead?->tenant;

            if ($sales === null) {
                return;
            }

            $this->current->run($sales, function () use ($sales, $agency, $lead) {
                $stage = $this->stages($sales)[$target = $this->target($agency)];
                $lead->forceFill(['custom_fields' => array_replace((array) $lead->custom_fields, ['jitu' => $this->details($agency)])]);

                if ($lead->stage_id === $stage->getKey()) {
                    $lead->save();

                    return;
                }

                $this->move($lead, $stage, match ($target) {
                    self::SUBSCRIBED => 'paket aktif',
                    self::DROPPED => 'masa percobaan berakhir tanpa bayar',
                    default => 'masa percobaan berjalan lagi',
                });
            });
        });
    }

    /** The stage name the agency belongs in now. Once it paid, it stays Berlangganan. */
    public function target(Tenant $agency): string
    {
        $sub = $agency->currentSubscription();

        if (! $sub->neverPaid()) {
            return self::SUBSCRIBED;
        }

        return $sub->trial_ends_at !== null && $sub->trial_ends_at->isPast() ? self::DROPPED : self::TRIAL;
    }

    /** @return array<string, string> shown on the lead */
    private function details(Tenant $agency): array
    {
        $sub = $agency->currentSubscription();
        $plan = $sub->neverPaid() ? config('plans.plans.'.config('plans.trial_plan').'.name').' (percobaan)' : config("plans.plans.{$sub->plan}.name", (string) $sub->plan);

        return array_filter([
            'agensi' => $agency->name,
            'ruang_kerja' => $agency->slug,
            'paket' => $plan,
            'trial_berakhir' => $sub->trial_ends_at?->timezone(config('app.timezone'))->format('Y-m-d'),
            'status' => $sub->statusLabel(),
        ], 'filled');
    }

    private function move(Lead $lead, Stage $stage, string $why): void
    {
        $from = $lead->stage_id ? Stage::query()->find($lead->stage_id) : null;

        $lead->stage_id = $stage->getKey();
        $lead->stage_entered_at = now();
        $lead->next_action = $stage->default_action;
        $lead->next_action_due_at = $stage->default_due_hours === null ? null : now()->addHours($stage->default_due_hours);
        $lead->lost_reason_id = $stage->type === StageType::Lost ? LostReason::query()->firstOrCreate(['label' => self::LOST_REASON])->getKey() : null;
        $lead->save();

        $lead->activities()->create([
            'type' => 'stage_changed',
            'body' => trim(($from ? "Pindah dari {$from->name} ke {$stage->name}" : "Masuk ke {$stage->name}")." (otomatis: $why)"),
            'meta' => ['from' => $from?->getKey(), 'to' => $stage->getKey(), 'auto' => true],
        ]);
    }

    /**
     * The three stages this needs, created at the end of the owner's pipeline the first time.
     *
     * @return array<string, Stage>
     */
    private function stages(Tenant $sales): array
    {
        $wanted = [
            self::TRIAL => [StageType::Open, 'Bantu agensi mencoba fitur dan tawarkan paket', 48],
            self::SUBSCRIBED => [StageType::Won, null, null],
            self::DROPPED => [StageType::Lost, null, null],
        ];

        $out = [];

        foreach ($wanted as $name => [$type, $action, $hours]) {
            $out[$name] = Stage::query()->where('name', $name)->first() ?? Stage::query()->create([
                'name' => $name,
                'position' => (int) Stage::query()->max('position') + 1,
                'type' => $type,
                'default_action' => $action,
                'default_due_hours' => $hours,
            ]);
        }

        return $out;
    }

    private function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
