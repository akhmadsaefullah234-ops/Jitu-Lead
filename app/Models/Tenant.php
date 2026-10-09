<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'plan', 'status', 'timezone', 'trial_ends_at'])]
class Tenant extends Model
{
    protected $hidden = ['capture_token'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'capture_settings' => 'array',
        ];
    }

    /**
     * The secret in the public form address; changing it switches off old embeds.
     */
    public function regenerateCaptureToken(): string
    {
        $this->forceFill(['capture_token' => Str::random(40)])->save();

        return $this->capture_token;
    }

    public function captureUrl(): ?string
    {
        return $this->capture_token ? route('capture.store', $this->capture_token) : null;
    }

    /**
     * Texts and colour of the hosted lead form, with defaults for anything unset.
     *
     * @return array<string, mixed>
     */
    public function formSettings(): array
    {
        return array_replace([
            'title' => 'Dapatkan informasi lengkap',
            'intro' => 'Isi data Anda, tim kami akan menghubungi lewat WhatsApp.',
            'button' => 'Kirim',
            'success' => 'Terima kasih! Tim kami akan segera menghubungi Anda.',
            'color' => '#dc2626',
            'ask_email' => false,
            'ask_note' => true,
            'show_privacy' => true,
            'privacy' => 'Dengan mengirim, Anda setuju dihubungi oleh tim kami terkait permintaan ini.',
        ], array_filter((array) $this->capture_settings, fn ($v) => $v !== null));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', 'active');
    }

    public function agents(): BelongsToMany
    {
        return $this->activeUsers()->wherePivot('role', Role::Agent->value);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('position');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }

    public function lostReasons(): HasMany
    {
        return $this->hasMany(LostReason::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
