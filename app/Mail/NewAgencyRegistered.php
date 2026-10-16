<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the platform owner an agency just signed up. Queued, and carries plain values so it needs no database when it is sent. */
class NewAgencyRegistered extends Mailable implements ShouldQueue
{
    /** @param  array<string, string>  $details  label => value, in the order they are shown */
    public function __construct(public string $agency, public array $details, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Agensi baru mendaftar: '.$this->agency);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.owner-alert', with: [
            'headline' => "Agensi baru mendaftar: {$this->agency}",
            'details' => $this->details,
            'url' => $this->url,
            'linkLabel' => 'Lihat agensi',
        ]);
    }
}
