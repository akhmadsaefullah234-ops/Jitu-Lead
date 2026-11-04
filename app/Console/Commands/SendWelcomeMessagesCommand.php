<?php

namespace App\Console\Commands;

use App\Jobs\SendWelcomeMessage;
use App\Models\WelcomeMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('welcome:send')]
#[Description('Kirim pesan sambutan WhatsApp formulir yang sudah waktunya')]
class SendWelcomeMessagesCommand extends Command
{
    /** A welcome stuck "sending" this long (the worker died mid-way) is looked at again. */
    private const STUCK_MINUTES = 10;

    public function handle(): int
    {
        // One that died mid-send goes back in line; WelcomeSender sees a message that did go out and keeps it at one.
        WelcomeMessage::query()->withoutGlobalScopes()->where('status', WelcomeMessage::SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => WelcomeMessage::PENDING, 'updated_at' => now()]);

        $count = 0;

        WelcomeMessage::query()->withoutGlobalScopes()->where('status', WelcomeMessage::PENDING)->where('due_at', '<=', now())
            ->orderBy('due_at')->limit(500)->pluck('id')
            ->each(function (int $id) use (&$count) {
                try {
                    SendWelcomeMessage::dispatchSync($id);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        if ($count > 0) {
            $this->line("Pesan sambutan diproses: {$count}");
        }

        return self::SUCCESS;
    }
}
