<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One email of the trial series or of a promo campaign, already filled in for one person. Queued, and carries plain values so it needs
 * no database when it is sent. Every one has a personal "stop these emails" link, also as a one-click header.
 */
class TrialEmail extends Mailable implements ShouldQueue
{
    public function __construct(
        public string $step,
        public string $subjectLine,
        public string $body,
        public string $button,
        public string $url,
        public string $unsubscribeUrl,
        public string $fromAddress,
        public string $fromName,
        public string $reason = 'Anda menerima email ini sebagai admin agensi yang sedang mencoba JITU LEAD.',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, $this->fromName), subject: $this->subjectLine, tags: [str_starts_with($this->step, 'campaign-') ? $this->step : 'trial-'.$this->step]);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.trial-email', with: [
            'body' => $this->body,
            'button' => $this->button,
            'url' => $this->url,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'reason' => $this->reason,
        ]);
    }
}
