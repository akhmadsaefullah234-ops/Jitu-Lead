<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushKeysCommand extends Command
{
    protected $signature = 'push:keys {--env= : Path to the .env file (default: the app\'s own)}';

    protected $description = 'Creates the VAPID keys for notifications in .env, once. Existing keys are never replaced.';

    public function handle(): int
    {
        if (filled(config('jitu.push.public_key')) && filled(config('jitu.push.private_key'))) {
            $this->info('Kunci notifikasi sudah ada. Tidak diubah.');

            return self::SUCCESS;
        }

        $env = $this->option('env') ?: base_path('.env');

        if (! is_file($env) || ! is_writable($env)) {
            $this->error('File .env tidak ada atau tidak bisa ditulis. Isi VAPID_PUBLIC_KEY dan VAPID_PRIVATE_KEY secara manual.');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();
        $contents = (string) file_get_contents($env);

        // Replace empty placeholders (from .env.example) instead of adding duplicates.
        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
            $contents = preg_match("/^{$name}=.*$/m", $contents)
                ? preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $contents)
                : rtrim($contents)."\n{$name}={$value}\n";
        }

        file_put_contents($env, $contents);
        $this->info('Kunci notifikasi dibuat dan disimpan di .env. Jalankan: php artisan config:cache');

        return self::SUCCESS;
    }
}
