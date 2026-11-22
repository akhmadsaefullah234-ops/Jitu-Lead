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

    // Backups (docs/deploy.md "Backup"). backup:database dumps the database every few hours; backup:run (02:00 WIB) dumps it
    // too, then backs up the WhatsApp gateway sessions and mirrors storage/app off the server.
    'backup' => [
        // Local copies: db/ (dumps), sessions/ (gateway tarballs), storage/ (uploads tarball, only without a remote) and the
        // dated folders older versions wrote. Sessions, uploads tarballs and old dated folders are kept this many days.
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 7),
        'database' => [
            // backup:database runs at minute 0 every this many hours (WIB): 6 = 00:00, 06:00, 12:00, 18:00.
            'every_hours' => (int) env('BACKUP_DB_EVERY_HOURS', 6),
            'keep_days' => (int) env('BACKUP_DB_KEEP_DAYS', 14),
            'remote_keep_days' => (int) env('BACKUP_DB_REMOTE_KEEP_DAYS', 60),
            // backup:restore-check fails a dump that creates fewer tables than this.
            'min_tables' => (int) env('BACKUP_DB_MIN_TABLES', 40),
        ],
        // No valid database dump for this long: the owner gets a push notification and an email (backup:monitor, hourly).
        'stale_hours' => (int) env('BACKUP_STALE_HOURS', 26),
        // Less free space than this on the backup disk: the oldest local copies go first (never the newest valid dump).
        'disk_min_free_percent' => (int) env('BACKUP_DISK_MIN_FREE_PERCENT', 20),
        // The admin dashboard warns when the disk is fuller than this.
        'disk_warn_used_percent' => 70,
        // WhatsApp media older than this can be removed by hand with backup:prune-media (a dry run unless --apply).
        // archive = moved to <remote>/media-archive first; delete = removed (the mirror keeps it remote_keep_days more).
        'media' => [
            'keep_days' => (int) env('BACKUP_MEDIA_KEEP_DAYS', 90),
            'mode' => env('BACKUP_MEDIA_PRUNE_MODE', 'archive'),
        ],
        // WhatsApp gateway sessions, backed up when this folder exists and is readable.
        'gateway_path' => env('BACKUP_GATEWAY_PATH', '/opt/jitu-gateway/data'),
        // Off-server copy through rclone (S3-compatible, Backblaze B2, Google Drive, ...): "remote:folder".
        // Empty = skipped with a warning. The remote itself is defined by RCLONE_CONFIG_<REMOTE>_* values in .env.
        'rclone_remote' => env('BACKUP_RCLONE_REMOTE'),
        // Files deleted or replaced in storage/app stay in <remote>/storage-deleted this many days.
        'remote_keep_days' => (int) env('BACKUP_REMOTE_KEEP_DAYS', 30),
        'sessions_remote_keep_days' => (int) env('BACKUP_SESSIONS_REMOTE_KEEP_DAYS', 14),
        // Dated folders older versions uploaded (a full copy each night). Lower this once the new layout is uploaded to
        // free the space sooner; the newest is kept while the remote has no dump in db/.
        'legacy_remote_keep_days' => (int) env('BACKUP_LEGACY_REMOTE_KEEP_DAYS', (int) env('BACKUP_REMOTE_KEEP_DAYS', 30)),
        // Copied here so they still work after `config:cache` (which stops .env being read).
        'rclone_env' => collect(array_merge($_ENV, $_SERVER))
            ->filter(fn ($v, $k) => is_string($k) && str_starts_with($k, 'RCLONE_CONFIG_') && is_string($v))->all(),
    ],

    // What the WhatsApp AI reads of a chat (App\Ai\ConversationHistory, App\Ai\ConversationMemory).
    'ai' => [
        // The newest messages it reads word for word: at most this many, and at most this many tokens (estimated).
        'history_messages' => (int) env('AI_HISTORY_MESSAGES', 40),
        'history_tokens' => (int) env('AI_HISTORY_TOKENS', 6000),
        // A single message longer than this (characters) is cut.
        'message_chars' => 1500,
        // The chat summary (wa_conversations.ai_memory) is written again after an AI reply once this many messages
        // came in or went out since the last one. Older messages that no longer fit are summarised before the AI answers.
        'memory_every' => (int) env('AI_MEMORY_EVERY', 12),
        // Messages per summary call; a longer backlog is summarised in several calls.
        'memory_chunk' => 60,
    ],

    // Operator health heartbeat (php artisan ops:heartbeat, every minute; docs/ops-monitoring.md). When every check passes the
    // server pings this URL; an outside monitor (Better Stack heartbeat) emails the operator once the pings stop.
    // Empty = off. The URL is a secret: it is never logged.
    'ops' => [
        'heartbeat_url' => env('OPS_HEARTBEAT_URL'),
        // More failed jobs than this in the last 15 minutes counts as unhealthy.
        'failed_jobs_max' => 20,
        // Free space on the app's disk below this percentage counts as unhealthy.
        'disk_min_percent' => 10,
        // The queue canary (App\Jobs\QueueCanary, every minute) must have run within this many minutes.
        'queue_max_age_minutes' => 5,
    ],

];
