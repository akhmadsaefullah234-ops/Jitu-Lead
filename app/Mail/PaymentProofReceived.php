<?php

namespace App\Mail;

use App\Filament\Pages\Inbox;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A buyer waiting to pay sent a picture (App\Automation\StageAutomation, rule 3). */
class PaymentProofReceived extends Mailable
{
    public function __construct(public Tenant $tenant, public Lead $lead) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Bukti transfer masuk dari {$this->lead->name}, perlu dicek");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-proof-received', with: [
            'tenant' => $this->tenant,
            'lead' => $this->lead,
            'url' => Inbox::getUrl(['lead' => $this->lead->getKey()], panel: 'app', tenant: $this->tenant),
        ]);
    }
}
