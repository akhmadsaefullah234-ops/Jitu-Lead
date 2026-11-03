<?php

namespace App\Mail;

use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Models\Tenant;
use App\Models\WaChannel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A gateway number has been down for a while (`$up` false), or is connected again after that (`$up` true). */
class WhatsAppConnectionAlert extends Mailable
{
    public function __construct(public Tenant $tenant, public WaChannel $channel, public bool $up) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->up
            ? "WhatsApp {$this->channel->name} tersambung lagi"
            : "WhatsApp {$this->channel->name} terputus");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.whatsapp-connection-alert', with: [
            'tenant' => $this->tenant,
            'channel' => $this->channel,
            'up' => $this->up,
            'url' => WaChannelResource::getUrl('index', tenant: $this->tenant),
        ]);
    }
}
