<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the platform owner an iPaymu payment came in. Queued, with plain values. */
class PaymentReceived extends Mailable implements ShouldQueue
{
    /** @param  array<string, string>  $details  label => value, in the order they are shown */
    public function __construct(public string $agency, public string $plan, public string $total, public array $details, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pembayaran diterima: {$this->agency} {$this->plan} {$this->total} via iPaymu");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.owner-alert', with: [
            'headline' => "Pembayaran diterima: {$this->agency} {$this->plan} {$this->total} via iPaymu.",
            'details' => $this->details,
            'url' => $this->url,
            'linkLabel' => 'Buka daftar transaksi iPaymu',
        ]);
    }
}
