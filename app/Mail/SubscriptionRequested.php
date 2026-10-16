<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the platform owner an agency asked for a plan and will transfer the fee. Queued, with plain values. */
class SubscriptionRequested extends Mailable implements ShouldQueue
{
    /** @param  array<string, string>  $details  label => value, in the order they are shown */
    public function __construct(public string $agency, public string $plan, public array $details, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Permintaan paket {$this->plan} dari {$this->agency}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.owner-alert', with: [
            'headline' => "{$this->agency} meminta paket {$this->plan} dan akan mentransfer pembayaran.",
            'details' => $this->details,
            'url' => $this->url,
            'linkLabel' => 'Buka permintaan langganan',
        ]);
    }
}
