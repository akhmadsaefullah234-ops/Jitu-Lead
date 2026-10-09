<?php

namespace App\Console\Commands;

use App\Models\RegistrationInvite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invite:create {--uses=1 : Berapa kali kode boleh dipakai} {--days=14 : Berlaku berapa hari, 0 untuk tanpa batas} {--note= : Catatan, misalnya nama calon pengguna}')]
#[Description('Buat kode undangan pendaftaran')]
class CreateInviteCommand extends Command
{
    public function handle(): int
    {
        $invite = RegistrationInvite::generate(max(1, (int) $this->option('uses')), (int) $this->option('days') ?: null, $this->option('note'));

        $this->info('Kode undangan: '.$invite->display());
        $this->line('Berlaku untuk '.$invite->max_uses.' pendaftaran'.($invite->expires_at ? ', sampai '.$invite->expires_at->format('d M Y H:i') : ', tanpa batas waktu').'.');

        return self::SUCCESS;
    }
}
