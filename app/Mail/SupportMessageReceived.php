<?php

namespace App\Mail;

use App\Models\SupportMessage;
use App\Models\SupportThread;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the platform owner a user wrote in the support chat. */
class SupportMessageReceived extends Mailable
{
    public function __construct(public SupportThread $thread, public SupportMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Chat support baru: '.$this->thread->tenant?->name);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.support-message-received', with: [
            'agency' => $this->thread->tenant?->name,
            'user' => $this->thread->user?->name,
            'body' => $this->message->body,
            'url' => url('/admin/support-threads/'.$this->thread->getKey()),
        ]);
    }
}
