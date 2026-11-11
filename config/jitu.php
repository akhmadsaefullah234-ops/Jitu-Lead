<?php

return [

    // Set only by `php artisan tutorial:capture` on its own server (local/testing): Everpro is faked and no outside call is made (App\Tutorials\CaptureFakes).
    'tutorial_capture' => (bool) env('TUTORIAL_CAPTURE', false),

    // Who may create an account: "open" (anyone, with a 14-day trial; the default),
    // "invite" (a valid invitation code is required), or "closed" (no sign-up page at all; the operator creates accounts).
    'registration' => env('REGISTRATION_MODE', 'open'),

    // Where agencies transfer the subscription fee (shown on the Langganan page). Plain text, line breaks allowed.
    'billing_bank_info' => env('BILLING_BANK_INFO'),
    'billing_contact' => env('BILLING_CONTACT'),

    // Support chat: new messages are mailed here (optional) and the chat shows this WhatsApp number as a fallback (digits with country code, e.g. 6281234567890).
    'support_email' => env('SUPPORT_EMAIL'),
    'support_whatsapp' => env('SUPPORT_WHATSAPP'),

    // Tells the platform owner (email to SUPPORT_EMAIL and push to super admins) about each new agency and plan request. Set to false if it gets too busy.
    'notify_signups' => (bool) env('NOTIFY_SIGNUPS', true),

    // Slug of the platform owner's own agency. Every new agency that agreed to be contacted becomes a lead there
    // (stage Trial, then Berlangganan or Tidak lanjut), so the owner's follow-up rules can reach it on WhatsApp.
    'platform_sales_tenant' => env('PLATFORM_SALES_TENANT'),

    // Daily WhatsApp report to owners and admins (Pengaturan > Laporan harian, `reports:daily` every 5 minutes).
    'reports' => [
        // Id of the WhatsApp number (wa_channels.id) of the PLATFORM_SALES_TENANT agency that sends the reports and the
        // verification codes. Never an agency's own number. Not connected or a failed send: the report goes by email.
        'channel' => env('PLATFORM_REPORT_CHANNEL'),
        // WhatsApp messages per minute at most, and how many minutes the reports of one send time are spread over.
        'per_minute' => (int) env('REPORT_PER_MINUTE', 20),
        'spread_minutes' => (int) env('REPORT_SPREAD_MINUTES', 10),
        // A report found this many hours after its send time (server was down) is skipped for the day.
        'late_hours' => 3,
    ],

    // Web Push (notifications on phones and computers). Keys come from `php artisan push:keys`; never regenerate
    // them once people have subscribed, because every device would have to subscribe again.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT') ?: env('APP_URL', 'https://localhost'),
    ],

    // Daily backup (php artisan backup:run, scheduled 02:00 WIB). See docs/deploy.md.
    'backup' => [
        // Local copies; each run is a dated folder. Kept this many days.
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 7),
        // WhatsApp gateway sessions, backed up when this folder exists and is readable.
        'gateway_path' => env('BACKUP_GATEWAY_PATH', '/opt/jitu-gateway/data'),
        // Off-server copy through rclone (S3-compatible, Backblaze B2, Google Drive, ...): "remote:folder".
        // Empty = skipped with a warning. The remote itself is defined by RCLONE_CONFIG_<REMOTE>_* values in .env.
        'rclone_remote' => env('BACKUP_RCLONE_REMOTE'),
        'remote_keep_days' => (int) env('BACKUP_REMOTE_KEEP_DAYS', 30),
        // Copied here so they still work after `config:cache` (which stops .env being read).
        'rclone_env' => collect(array_merge($_ENV, $_SERVER))
            ->filter(fn ($v, $k) => is_string($k) && str_starts_with($k, 'RCLONE_CONFIG_') && is_string($v))->all(),
    ],

];
