<?php

namespace App\Mail;

use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Models\Tenant;
use App\Models\WaChannel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A gateway number's WhatsApp login stopped working (or its linked device was removed on the phone): scan again. */
class WhatsAppNeedsRelink extends Mailable
{
    /** `$reason`: from the gateway, e.g. "perangkat tertaut dilepas dari HP". */
    public function __construct(public Tenant $tenant, public WaChannel $channel, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "WhatsApp {$this->channel->name} perlu scan ulang");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.whatsapp-needs-relink', with: [
            'tenant' => $this->tenant,
            'channel' => $this->channel,
            'reason' => $this->reason,
            'removed' => $this->reason !== null && str_contains($this->reason, 'dilepas'),
            'url' => WaChannelResource::getUrl('index', tenant: $this->tenant),
        ]);
    }
}
