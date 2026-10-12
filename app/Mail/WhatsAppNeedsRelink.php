<?php

namespace App\Mail;

use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Models\Tenant;
use App\Models\WaChannel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WhatsAppNeedsRelink extends Mailable
{
    public function __construct(public Tenant $tenant, public WaChannel $channel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "WhatsApp {$this->channel->name} perlu scan ulang");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.whatsapp-needs-relink', with: [
            'tenant' => $this->tenant,
            'channel' => $this->channel,
            'url' => WaChannelResource::getUrl('index', tenant: $this->tenant),
        ]);
    }
}
