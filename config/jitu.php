<?php

return [

    // Who may create an account: "open" (anyone, with a 14-day trial; the default),
    // "invite" (a valid invitation code is required), or "closed" (no sign-up page at all; the operator creates accounts).
    'registration' => env('REGISTRATION_MODE', 'open'),

    // Where agencies transfer the subscription fee (shown on the Langganan page). Plain text, line breaks allowed.
    'billing_bank_info' => env('BILLING_BANK_INFO'),
    'billing_contact' => env('BILLING_CONTACT'),

    // Support chat: new messages are mailed here (optional) and the chat shows this WhatsApp number as a fallback (digits with country code, e.g. 6281234567890).
    'support_email' => env('SUPPORT_EMAIL'),
    'support_whatsapp' => env('SUPPORT_WHATSAPP'),

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
