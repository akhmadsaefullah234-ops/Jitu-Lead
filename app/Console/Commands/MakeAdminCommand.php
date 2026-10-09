<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admin:make {email : email akun} {--name= : nama (untuk akun baru)} {--password= : kata sandi (untuk akun baru; kosongkan agar ditanya)} {--revoke : cabut akses super admin}')]
#[Description('Jadikan sebuah akun super admin (panel /admin), atau cabut aksesnya')]
class MakeAdminCommand extends Command
{
    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($this->option('revoke')) {
            if (! $user) {
                $this->error('Akun tidak ditemukan.');

                return self::FAILURE;
            }

            $user->forceFill(['is_super_admin' => false])->save();
            $this->info("$email bukan lagi super admin.");

            return self::SUCCESS;
        }

        if (! $user) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Email tidak valid.');

                return self::FAILURE;
            }

            $password = $this->option('password') ?: $this->secret('Kata sandi untuk akun baru (min. 10 karakter)');

            if (strlen((string) $password) < 10) {
                $this->error('Kata sandi minimal 10 karakter.');

                return self::FAILURE;
            }

            $user = new User(['name' => $this->option('name') ?: 'Super Admin', 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->forceFill(['is_super_admin' => true])->save();
        $this->info("$email sekarang super admin. Masuk lewat /admin.");

        return self::SUCCESS;
    }
}
