<?php

namespace App\Console\Commands;

use App\Models\RegistrationInvite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invite:list')]
#[Description('Daftar kode undangan dan pemakaiannya')]
class ListInvitesCommand extends Command
{
    public function handle(): int
    {
        $this->table(['Kode', 'Terpakai', 'Berlaku sampai', 'Catatan'], RegistrationInvite::query()->latest('id')->get()->map(fn (RegistrationInvite $i) => [
            $i->display(), "{$i->uses}/{$i->max_uses}", $i->expires_at?->format('d M Y H:i') ?? '-', $i->note ?? '-',
        ])->all());

        return self::SUCCESS;
    }
}
