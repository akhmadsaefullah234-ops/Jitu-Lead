<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The daily, weekly or monthly report by email, word for word the WhatsApp text, when WhatsApp could not take it. */
class DailyReportMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $text, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-report', with: ['body' => self::toMarkdown($this->text), 'url' => $this->url]);
    }

    /** WhatsApp formatting (*bold*, _italic_, one line per item) as Markdown; the Dashboard link becomes the button. */
    public static function toMarkdown(string $text): string
    {
        $lines = array_filter(explode("\n", $text), fn ($l) => ! str_starts_with($l, '🔗'));
        $lines = array_map(fn ($l) => preg_replace(['/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '/(?<![\w_])_([^_\n]+)_(?![\w_])/u'], ['**$1**', '*$1*'], e($l, false)), $lines);

        return rtrim(implode("  \n", $lines));
    }
}
