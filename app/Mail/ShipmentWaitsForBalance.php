<?php

namespace App\Mail;

use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A parcel held because the provider's balance does not cover it (App\Shipping\Shipments\Shipments::waitForBalance). */
class ShipmentWaitsForBalance extends Mailable
{
    public function __construct(public Tenant $tenant, public Lead $lead, public Shipment $shipment, public string $headline, public string $detail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->headline}: {$this->lead->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.shipment-waits-for-balance', with: [
            'url' => ShipmentResource::getUrl('index', ['tab' => 'balance'], panel: 'app', tenant: $this->tenant),
        ]);
    }
}
