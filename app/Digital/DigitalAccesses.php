<?php

namespace App\Digital;

use App\Automation\DigitalStages;
use App\Enums\Role;
use App\Models\DigitalAccess;
use App\Models\DigitalPayment;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use App\Payments\PaymentInstructions;
use App\Support\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A paid buyer's access to a digital product: one signed link per buyer (/akses/{token}) that opens a page with a
 * download button, valid for the product's days and downloads (90 and 10 by default), with a trail on the lead
 * (opened, downloads, last IP and browser). Staff or the AI may send the link again at most MAX_RESENDS times per
 * lead; after that only an admin. Moving the lead to Refund, or "Cabut akses", revokes it. The link itself is never
 * written to the activity or shown to the AI (MessageContent::forAi). Only for industry "digital"; must run inside the
 * tenant.
 *
 * The aim is to limit sharing and leave a trail, not DRM: an HTML file gets the buyer's licence text in its
 * data-lic="{{LISENSI}}" marker at download time; the stored file is never changed.
 */
final class DigitalAccesses
{
    public const ORIGIN = 'digital_access';

    public const DEFAULT_DAYS = 90;

    public const MAX_DAYS = 365;

    public const DEFAULT_DOWNLOADS = 10;

    public const MAX_DOWNLOADS = 100;

    public const MAX_RESENDS = 3;

    /** Same as the server's upload limit (deploy/install.sh: upload_max_filesize and client_max_body_size 10M). */
    public const FILE_MAX_KB = 10240;

    public const FILE_EXTENSIONS = ['html', 'htm', 'zip', 'pdf'];

    public const FILE_MIMES = ['text/html', 'text/plain', 'application/zip', 'application/x-zip-compressed', 'application/pdf'];

    public const LICENCE_MARKER = '{{LISENSI}}';

    /** Always handed to a person for a digital agency, whatever the agency's own handoff words say. */
    public const HANDOFF_WORDS = ['tidak bisa buka', 'link mati', 'refund'];

    public const NEW_MESSAGE = "Halo {nama}, pembayaran {produk} sudah kami terima. Terima kasih 🙏\nAkses Anda (khusus untuk Anda, berlaku sampai {sampai}, maksimal {unduhan} kali unduh): {tautan}\nBuka dengan Chrome. Kalau ada kendala membuka, balas chat ini ya.";

    public const RESEND_MESSAGE = "Halo {nama}, ini tautan akses {produk} Anda: {tautan}\nBerlaku sampai {sampai}, sisa {sisa} kali unduh. Buka dengan Chrome ya.";

    public function __construct(private CurrentTenant $current, private PaymentInstructions $whatsapp) {}

    public function applies(?Lead $lead = null): bool
    {
        return ($tenant = $lead?->tenant ?? $this->current->get()) !== null && $tenant->industry()->key === 'digital';
    }

    /** The lead's product has something to open: its own file, the link on the lead, or the product's link. */
    public static function hasTarget(Lead $lead): bool
    {
        $product = $lead->property;

        return (bool) $product?->hasAccessFile() || self::linkOf($lead) !== null;
    }

    /** The outside link (download, class, group) the buyer is sent on to, when there is no file. */
    public static function linkOf(Lead $lead): ?string
    {
        $link = trim((string) ($lead->access_link ?: $lead->property?->access_url));

        return preg_match('#^https?://#i', $link) ? $link : null;
    }

    /**
     * The buyer's access: the one still working for this product, or a new one. Null when the lead is not paid, or
     * the product has nothing to open.
     */
    public function issue(Lead $lead, ?DigitalPayment $payment = null): ?DigitalAccess
    {
        $lead->loadMissing('property');

        if (! $this->applies($lead) || $lead->paid_at === null || ! self::hasTarget($lead)) {
            return null;
        }

        if (($active = $this->active($lead)) !== null && (int) $active->property_id === (int) $lead->property_id) {
            return $active;
        }

        return $this->create($lead, $lead->property, $payment);
    }

    public static function url(DigitalAccess $access): string
    {
        return URL::temporarySignedRoute('digital.access', $access->expires_at, ['token' => $access->token]);
    }

    public static function downloadUrl(DigitalAccess $access): string
    {
        return URL::temporarySignedRoute('digital.access.download', $access->expires_at, ['token' => $access->token]);
    }

    public function latest(Lead $lead): ?DigitalAccess
    {
        return DigitalAccess::query()->where('lead_id', $lead->getKey())->latest('id')->first();
    }

    public function active(Lead $lead): ?DigitalAccess
    {
        return DigitalAccess::query()->where('lead_id', $lead->getKey())->whereNull('revoked_at')->where('expires_at', '>', now())
            ->whereColumn('downloads', '<', 'max_downloads')->latest('id')->first();
    }

    public function resendsUsed(Lead $lead): int
    {
        return (int) DigitalAccess::query()->where('lead_id', $lead->getKey())->sum('resends');
    }

    /** "Pembayaran diterima" or iPaymu: the first link, sent on WhatsApp. Null when sent, else why not. */
    public function sendNew(Lead $lead, DigitalAccess $access, User $actor): ?string
    {
        $why = $this->deliver($lead, $access, $actor, self::NEW_MESSAGE);
        $lead->activities()->create(['type' => $why === null ? 'access_sent' : 'access_not_sent',
            'body' => $why === null ? 'Tautan akses khusus pembeli dikirim lewat WhatsApp (berlaku sampai '.self::date($access->expires_at).', '.$access->max_downloads.' kali unduh).'
                : "Tautan akses belum terkirim lewat WhatsApp ({$why}).", 'meta' => ['digital_access_id' => $access->getKey()]]);

        return $why;
    }

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && app(CurrentTenant::class)->roleOf($user) === Role::Admin;
    }

    /** Why this person (or the AI, with no person) may not send the link again; null when they may. */
    public function cannotResend(Lead $lead, ?User $person): ?string
    {
        $access = $this->active($lead);

        return match (true) {
            ! $this->applies($lead) || $lead->paid_at === null => 'lead belum lunas',
            $this->isRefunded($lead) => 'lead sudah refund',
            $access === null => ($this->latest($lead) ? 'tautan akses '.$this->latest($lead)->problem().', perlu tautan baru dari admin' : 'belum ada tautan akses'),
            ! self::isAdmin($person) && $this->resendsUsed($lead) >= self::MAX_RESENDS => 'sudah dikirim ulang '.self::MAX_RESENDS.' kali, perlu admin',
            default => null,
        };
    }

    /**
     * Sends the same working link again (never a new one): staff or the AI at most MAX_RESENDS times per lead, an
     * admin any time. Every resend is written to the activity, without the link.
     *
     * @param  string  $by  who asked, for the activity: a person's name or "AI"
     * @return array{ok: bool, pesan: string}
     */
    public function resend(Lead $lead, User $actor, string $by, bool $asPerson = true): array
    {
        $lead->loadMissing('property');

        if (($why = $this->cannotResend($lead, $asPerson ? $actor : null)) !== null) {
            $lead->activities()->create(['type' => 'access_not_sent', 'body' => "Kirim ulang tautan akses oleh {$by} ditolak: {$why}."]);

            return ['ok' => false, 'pesan' => $why];
        }

        $access = $this->active($lead);

        if (($why = $this->deliver($lead, $access, $actor, self::RESEND_MESSAGE)) !== null) {
            $lead->activities()->create(['type' => 'access_not_sent', 'body' => "Kirim ulang tautan akses oleh {$by} gagal: {$why}."]);

            return ['ok' => false, 'pesan' => $why];
        }

        $access->increment('resends');
        $used = $this->resendsUsed($lead);
        $lead->activities()->create(['type' => 'access_resent', 'user_id' => $asPerson ? $actor->getKey() : null,
            'body' => "Tautan akses dikirim ulang oleh {$by} (kirim ulang ke-{$used}, batas ".self::MAX_RESENDS.' tanpa admin).',
            'meta' => ['digital_access_id' => $access->getKey()]]);

        return ['ok' => true, 'pesan' => 'Tautan akses dikirim ulang lewat WhatsApp.'];
    }

    /**
     * Admin only: the old links stop working and a new one (with fresh days and downloads) is sent.
     *
     * @return array{ok: bool, pesan: string}
     */
    public function reissue(Lead $lead, User $admin): array
    {
        $lead->loadMissing('property');

        if (! self::isAdmin($admin) || ! $this->applies($lead) || $lead->paid_at === null || $this->isRefunded($lead) || ! self::hasTarget($lead)) {
            return ['ok' => false, 'pesan' => 'Tautan baru hanya untuk admin, pada lead lunas yang belum refund dan produknya punya berkas atau tautan.'];
        }

        $this->revoke($lead, $admin, 'diganti tautan baru', false);
        $access = $this->create($lead, $lead->property, null);
        $lead->activities()->create(['type' => 'access_reissued', 'user_id' => $admin->getKey(), 'body' => 'Admin '.$admin->name.' menerbitkan tautan akses baru; tautan lama tidak berlaku lagi.']);
        $why = $this->sendNew($lead, $access, $admin);

        return ['ok' => $why === null, 'pesan' => $why === null ? 'Tautan akses baru dikirim lewat WhatsApp.' : "Tautan baru dibuat, tetapi belum terkirim: {$why}."];
    }

    /** Every working link of the lead stops working. Returns how many were revoked. */
    public function revoke(Lead $lead, ?User $actor, string $reason, bool $log = true): int
    {
        $count = DigitalAccess::query()->where('lead_id', $lead->getKey())->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => Str::limit($reason, 120, ''), 'updated_at' => now()]);

        if ($log && $count > 0) {
            $lead->activities()->create(['type' => 'access_revoked', 'user_id' => $actor?->getKey(),
                'body' => 'Akses produk dicabut ('.$reason.')'.($actor ? ' oleh '.$actor->name : '').'; tautan pembeli tidak bisa dibuka lagi.']);
        }

        return $count;
    }

    /** MoveLeadToStage: a lead moved to Refund loses its access. */
    public function stageEntered(Lead $lead): void
    {
        if ($this->applies($lead) && $this->isRefunded($lead)) {
            $this->revoke($lead, null, 'lead pindah ke tahap Refund');
        }
    }

    public function isRefunded(Lead $lead): bool
    {
        // Read again: the lead in hand may be from before the move.
        $stageId = Lead::query()->whereKey($lead->getKey())->value('stage_id');

        return $stageId !== null && (new DigitalStages)->stage(DigitalStages::REFUND)?->getKey() === (int) $stageId;
    }

    /** What the HTML file shows as its licence: the buyer's name (or the end of their number) and the order. */
    public static function licence(DigitalAccess $access): string
    {
        $lead = $access->lead;
        $who = trim((string) $lead?->name) !== '' ? Str::limit(trim((string) $lead->name), 60, '') : 'WA •••'.substr((string) preg_replace('/\D/', '', (string) $lead?->phone), -4);

        return 'Lisensi '.$who.' · Pesanan #'.$access->lead_id;
    }

    /** The first {{LISENSI}} inside a data-lic attribute becomes the licence, once; anything else is left as it is. */
    public static function personalise(string $html, string $licence): string
    {
        $safe = htmlspecialchars($licence, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace_callback('/(data-lic\s*=\s*(["\'])[^"\']*?)'.preg_quote(self::LICENCE_MARKER, '/').'/i',
            fn (array $m) => $m[1].$safe, $html, 1);
    }

    /**
     * Counts one download when the link still allows it (in one statement, so two clicks never pass the limit),
     * with the IP and browser.
     */
    public static function countDownload(DigitalAccess $access, ?string $ip, ?string $agent): bool
    {
        $counted = DigitalAccess::query()->withoutGlobalScopes()->whereKey($access->getKey())->whereNull('revoked_at')
            ->where('expires_at', '>', now())->whereColumn('downloads', '<', 'max_downloads')
            ->update(['downloads' => DB::raw('downloads + 1'), 'last_downloaded_at' => now(), 'last_ip' => $ip ? Str::limit($ip, 45, '') : null,
                'last_user_agent' => $agent ? Str::limit($agent, 255, '') : null, 'updated_at' => now()]) === 1;

        $access->refresh();

        return $counted;
    }

    public static function date(Carbon $at): string
    {
        return $at->copy()->timezone(app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta')->translatedFormat('j M Y');
    }

    private function create(Lead $lead, ?Property $product, ?DigitalPayment $payment): DigitalAccess
    {
        $days = max(1, min(self::MAX_DAYS, (int) ($product?->access_days ?: self::DEFAULT_DAYS)));
        $downloads = max(1, min(self::MAX_DOWNLOADS, (int) ($product?->access_max_downloads ?: self::DEFAULT_DOWNLOADS)));

        return DigitalAccess::query()->create([
            'tenant_id' => $lead->tenant_id, 'lead_id' => $lead->getKey(), 'property_id' => $product?->getKey(), 'digital_payment_id' => $payment?->getKey(),
            'token' => Str::random(40), 'expires_at' => now()->addDays($days), 'max_downloads' => $downloads,
        ]);
    }

    private function deliver(Lead $lead, DigitalAccess $access, User $actor, string $template): ?string
    {
        $text = strtr($template, [
            '{nama}' => $lead->name ?: 'Kak', '{produk}' => $lead->property?->name ?? 'produk', '{tautan}' => self::url($access),
            '{sampai}' => self::date($access->expires_at), '{unduhan}' => (string) $access->max_downloads,
            '{sisa}' => (string) max(0, $access->max_downloads - $access->downloads),
        ]);

        return $this->whatsapp->deliver($lead, $actor, $text, self::ORIGIN);
    }
}
