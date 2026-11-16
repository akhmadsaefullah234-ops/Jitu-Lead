<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The AI could not answer a client on WhatsApp; the chat waits for a person in the Inbox. */
class AiReplyFailed extends Mailable
{
    public function __construct(public Tenant $tenant, public string $reason, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "AI {$this->tenant->name} gagal membalas chat WhatsApp");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ai-reply-failed', with: ['tenant' => $this->tenant, 'reason' => $this->reason, 'url' => $this->url]);
    }
}
