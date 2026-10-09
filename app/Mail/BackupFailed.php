<?php

namespace App\Mail;

use App\Models\BackupRun;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BackupFailed extends Mailable
{
    public function __construct(public BackupRun $run) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Backup JITU LEAD GAGAL');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.backup-failed', with: [
            'when' => $this->run->started_at->timezone(config('app.timezone'))->format('d M Y H:i'),
            'message' => $this->run->message,
            'steps' => collect($this->run->steps)->map(fn ($s) => "- {$s['label']}: {$s['status']}".($s['note'] ? " ({$s['note']})" : ''))->implode("\n"),
            'url' => url('/admin/backup-runs'),
        ]);
    }
}
