<?php

namespace App\Mail;

use App\Models\AiEvaluation;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The AI evaluation scored lower than the run before ("Kesehatan AI"). */
class AiHealthDropped extends Mailable
{
    public function __construct(public Tenant $tenant, public int $before, public int $now, public AiEvaluation $evaluation, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nilai AI {$this->tenant->name} turun ke {$this->now}%");
    }

    public function content(): Content
    {
        $failed = collect($this->evaluation->results)->reject(fn (array $r) => $r['passed'])->take(5)->map(fn (array $r) => $r['title'].': '.implode('; ', $r['problems']))->values()->all();

        return new Content(markdown: 'mail.ai-health-dropped', with: ['tenant' => $this->tenant, 'before' => $this->before, 'now' => $this->now, 'failed' => $failed, 'url' => $this->url]);
    }
}
