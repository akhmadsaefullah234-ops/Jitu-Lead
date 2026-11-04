<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The automatic WhatsApp welcome for one lead from one form (App\Welcome\WelcomeSender): pending until due_at,
 * then sent, skipped or failed, never twice for the same lead and form.
 */
#[Fillable(['lead_id', 'lead_form_id', 'landing_page_id', 'returning', 'status', 'due_at', 'attempts', 'context', 'note', 'wa_message_id'])]
class WelcomeMessage extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'returning' => 'boolean', 'context' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class, 'landing_page_id');
    }
}
