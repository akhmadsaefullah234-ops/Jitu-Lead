<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubscriptionEnding extends Mailable
{
    public function __construct(public Tenant $tenant, public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        $what = $this->subscription->status === Subscription::TRIAL ? 'Masa percobaan' : 'Langganan';

        return new Envelope(subject: "$what {$this->tenant->name} segera berakhir");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.subscription-ending', with: [
            'trial' => $this->subscription->status === Subscription::TRIAL,
            'ends' => $this->subscription->endsAt()->timezone(config('app.timezone')),
            'days' => $this->subscription->daysLeft(),
            'grace' => (int) config('plans.grace_days'),
            'url' => route('filament.app.pages.subscription', ['tenant' => $this->tenant->slug]),
        ]);
    }
}
