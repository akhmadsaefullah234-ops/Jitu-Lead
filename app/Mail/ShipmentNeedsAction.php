<?php

namespace App\Mail;

use App\Filament\Pages\Inbox;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A parcel the courier could not deliver (App\Shipping\Shipments\StatusNotices). */
class ShipmentNeedsAction extends Mailable
{
    public function __construct(public Tenant $tenant, public Lead $lead, public Shipment $shipment, public string $headline, public string $detail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->headline}: {$this->lead->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.shipment-needs-action', with: [
            'url' => Inbox::getUrl(['lead' => $this->lead->getKey()], panel: 'app', tenant: $this->tenant),
        ]);
    }
}
