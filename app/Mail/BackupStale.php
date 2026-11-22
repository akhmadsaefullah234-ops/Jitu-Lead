<?php

namespace App\Mail;

use App\Models\BackupRun;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** No database dump has passed its check for longer than jitu.backup.stale_hours (backup:monitor). */
class BackupStale extends Mailable
{
    public function __construct(public ?BackupRun $lastValid, public int $hours) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Backup JITU LEAD: tidak ada cadangan database valid lebih dari {$this->hours} jam");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.backup-stale', with: [
            'hours' => $this->hours,
            'when' => $this->lastValid?->finished_at?->timezone(config('app.timezone'))->format('d M Y H:i'),
            'latest' => BackupRun::query()->latest('started_at')->first(),
            'url' => url('/admin/backup-runs'),
        ]);
    }
}
