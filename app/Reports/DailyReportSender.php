<?php

namespace App\Reports;

use App\Mail\DailyReportMail;
use App\Models\Membership;
use App\Models\ReportDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Retention\PlatformLeads;
use App\WhatsApp\Providers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the reports (`reports:daily`, every 5 minutes): finds who is due in each agency's own time zone, writes one
 * report_deliveries row for each (unique per agency, person, kind and day, so nothing goes out twice), spread over
 * a few minutes, then sends the rows that are due at most `per_minute` a minute.
 *
 * Reports go from the platform's own WhatsApp number (PLATFORM_REPORT_CHANNEL, a number of PLATFORM_SALES_TENANT),
 * never an agency's. Without a verified number, with that number down, or when the send fails, the same text goes
 * by email and the reason is kept on the row. Locked agencies (trial ended, plan run out, suspended) get nothing.
 */
class DailyReportSender
{
    /** A row left "sending" this long (the process died mid-send) is marked failed, never sent again. */
    public const STUCK_MINUTES = 30;

    public function __construct(private DailyReport $report, private Providers $providers) {}

    /** @return array{queued: int, sent: int, email: int, failed: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['queued' => $this->queue($now), 'sent' => 0, 'email' => 0, 'failed' => 0];

        ReportDelivery::query()->where('status', ReportDelivery::SENDING)->where('updated_at', '<', $now->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => ReportDelivery::FAILED, 'error' => 'Pengiriman terputus di tengah jalan.']);

        $perMinute = max(1, (int) config('jitu.reports.per_minute', 20));
        $rows = ReportDelivery::query()->where('status', ReportDelivery::PENDING)->where('due_at', '<=', $now)
            ->orderBy('due_at')->orderBy('id')->limit($perMinute * 4)->get();

        foreach ($rows as $i => $row) {
            if ($i > 0) {
                // At most $perMinute WhatsApp messages a minute from the one platform number.
                Sleep::for(intdiv(60_000, $perMinute))->milliseconds();
            }

            $status = $this->deliver($row);
            $counts[$status === ReportDelivery::SENT ? 'sent' : ($status === ReportDelivery::EMAIL ? 'email' : 'failed')]++;
        }

        return $counts;
    }

    /** Writes the rows of every report due by $now that has none yet. */
    public function queue(CarbonImmutable $now): int
    {
        $queued = 0;
        $late = (int) config('jitu.reports.late_hours', 3);
        $spread = max(0, (int) config('jitu.reports.spread_minutes', 10));

        Membership::query()->where('status', 'active')->with(['tenant', 'user'])->orderBy('id')
            ->each(function (Membership $membership) use ($now, $late, $spread, &$queued) {
                $tenant = $membership->tenant;

                if ($tenant === null || $membership->user === null || ! self::receives($tenant)) {
                    return;
                }

                $today = $now->setTimezone($tenant->timezone ?: 'Asia/Jakarta');

                foreach (ReportSettings::dueOn(ReportSettings::effective($membership, $tenant), $today) as $kind => $at) {
                    if ($now->lessThan($at) || $now->greaterThan($at->addHours($late))) {
                        continue;
                    }

                    // The same person always lands on the same minute, so reports of one send time do not all go at once.
                    $offset = $spread > 0 ? crc32($membership->user_id.':'.$kind) % ($spread * 60) : 0;

                    $queued += ReportDelivery::query()->insertOrIgnore([
                        'tenant_id' => $tenant->getKey(), 'user_id' => $membership->user_id, 'kind' => $kind,
                        'report_date' => $at->toDateString(), 'status' => ReportDelivery::PENDING,
                        'due_at' => $at->addSeconds($offset)->setTimezone(config('app.timezone')),
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });

        return $queued;
    }

    /** "Kirim contoh sekarang": yesterday's report to $user at once, recorded like any other. */
    public function sample(Tenant $tenant, User $user): ReportDelivery
    {
        $row = ReportDelivery::query()->create([
            'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(), 'kind' => ReportDelivery::SAMPLE,
            'report_date' => now($tenant->timezone ?: 'Asia/Jakarta')->toDateString(), 'status' => ReportDelivery::PENDING, 'due_at' => now(),
        ]);
        $this->deliver($row);

        return $row->refresh();
    }

    /** Whether an agency may get reports now: not suspended and not read-only (trial ended or plan run out). */
    public static function receives(Tenant $tenant): bool
    {
        return $tenant->status !== 'suspended' && ! $tenant->currentSubscription()->isReadOnly();
    }

    /** Sends one row, if nobody else took it; returns its final status. */
    public function deliver(ReportDelivery $row): string
    {
        $claimed = ReportDelivery::query()->whereKey($row->getKey())->where('status', ReportDelivery::PENDING)
            ->update(['status' => ReportDelivery::SENDING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return $row->refresh()->status;
        }

        $tenant = Tenant::query()->find($row->tenant_id);
        $user = User::query()->find($row->user_id);
        $member = $tenant && $user && Membership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->where('status', 'active')->exists();

        if (! $member || ! self::receives($tenant)) {
            return $this->finish($row, ReportDelivery::FAILED, 'Dilewati: agensi terkunci, langganan habis, atau bukan anggota lagi.');
        }

        try {
            $report = $this->report->build($tenant, $user, $row->kind === ReportDelivery::SAMPLE ? ReportDelivery::DAILY : $row->kind);
        } catch (Throwable $e) {
            report($e);

            return $this->finish($row, ReportDelivery::FAILED, 'Laporan tidak bisa disusun: '.$e->getMessage());
        }

        $row->forceFill(['period_label' => $report['period']->label()]);
        $problem = $user->hasVerifiedWhatsapp() ? $this->whatsapp($user->whatsapp, $report['text']) : 'Nomor WhatsApp belum terverifikasi.';

        if ($problem === null) {
            return $this->finish($row, ReportDelivery::SENT, null);
        }

        try {
            $subject = 'Laporan '.Str::lower(ReportDelivery::KINDS[$row->kind] ?? 'harian').' '.$tenant->name.' — '.$report['period']->label();
            Mail::to($user->email)->send(new DailyReportMail($subject, $report['text'], $report['url']));
        } catch (Throwable $e) {
            report($e);

            return $this->finish($row, ReportDelivery::FAILED, $problem.' Email juga gagal: '.$e->getMessage());
        }

        Log::info('Daily report sent by email instead of WhatsApp', ['delivery' => $row->getKey(), 'reason' => $problem]);

        return $this->finish($row, ReportDelivery::EMAIL, $problem);
    }

    /** The platform's report number, when it is set and belongs to the platform's own agency. */
    public static function platformChannel(): ?WaChannel
    {
        $id = config('jitu.reports.channel');
        $sales = filled($id) ? PlatformLeads::salesTenant() : null;

        return $sales ? WaChannel::query()->withoutGlobalScopes()->where('tenant_id', $sales->getKey())->whereKey((int) $id)->first() : null;
    }

    /** Sends $text from the platform number; null when it went, else why not. */
    public function whatsapp(string $to, string $text): ?string
    {
        $channel = self::platformChannel();

        if ($channel === null) {
            return 'Nomor WhatsApp platform untuk laporan belum diatur.';
        }

        if (! $channel->isConnected()) {
            return 'Nomor WhatsApp platform sedang terputus.';
        }

        try {
            $result = $this->providers->for($channel)->sendText($to, $text);
        } catch (Throwable $e) {
            report($e);

            return 'WhatsApp gagal: '.$e->getMessage();
        }

        return $result->ok ? null : 'WhatsApp gagal: '.$result->error;
    }

    private function finish(ReportDelivery $row, string $status, ?string $error): string
    {
        $row->forceFill([
            'status' => $status, 'error' => $error === null ? null : Str::limit($error, 490),
            'sent_at' => in_array($status, [ReportDelivery::SENT, ReportDelivery::EMAIL], true) ? now() : null,
        ])->save();

        return $status;
    }
}
