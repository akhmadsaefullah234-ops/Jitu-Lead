<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the buyer of a digital product paid through iPaymu: the signed access link (App\Automation\DigitalStageAutomation). */
class DigitalAccessReady extends Mailable
{
    public function __construct(public Tenant $tenant, public Lead $lead, public string $link, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Akses '.($this->lead->property?->name ?? 'produk').' dari '.$this->tenant->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.digital-access-ready', with: [
            'tenant' => $this->tenant, 'lead' => $this->lead, 'link' => $this->link, 'days' => $this->days,
        ]);
    }
}
