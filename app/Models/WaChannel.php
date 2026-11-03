<?php

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['type', 'name', 'phone', 'status', 'credentials', 'intro_template', 'position', 'last_connected_at', 'last_error', 'ai_mode', 'label_settings'])]
#[Hidden(['credentials'])]
class WaChannel extends Model
{
    use BelongsToTenant;

    public const DEFAULT_INTRO = 'Selamat datang kembali, {nama}. Saya {agen} dari {agensi}. Kita pernah bicara soal {properti}. Boleh saya lanjutkan informasinya di nomor ini?';

    protected static function booted(): void
    {
        static::creating(function (WaChannel $channel) {
            $channel->webhook_token ??= Str::random(48);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => WaChannelType::class,
            'status' => WaChannelStatus::class,
            'ai_mode' => AiMode::class,
            'credentials' => 'encrypted:array',
            'label_settings' => 'array',
            'last_connected_at' => 'datetime',
            'down_since' => 'datetime',
            'down_alerted_at' => 'datetime',
        ];
    }

    public function scopeConnected(Builder $query): void
    {
        $query->where('status', WaChannelStatus::Connected->value);
    }

    public function scopeOfType(Builder $query, WaChannelType $type): void
    {
        $query->where('type', $type->value);
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function isOfficial(): bool
    {
        return $this->type === WaChannelType::Official;
    }

    public function isConnected(): bool
    {
        return $this->status === WaChannelStatus::Connected;
    }

    /** Back up. `down_alerted_at` stays until App\WhatsApp\ChannelAlerts has told the admins it is back. */
    public function markConnected(): void
    {
        $this->forceFill(['status' => WaChannelStatus::Connected, 'last_connected_at' => now(), 'last_error' => null, 'down_since' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => WaChannelStatus::Error, 'last_error' => Str::limit($error, 500), 'down_since' => $this->downSince()])->save();
    }

    /** `$deliberate`: disconnected on purpose from the CRM, so it is not an outage to warn about. */
    public function markDisconnected(?string $reason = null, bool $deliberate = false): void
    {
        $this->forceFill([
            'status' => WaChannelStatus::Disconnected,
            'last_error' => $reason,
            'down_since' => $deliberate ? null : $this->downSince(),
            'down_alerted_at' => $deliberate ? null : $this->down_alerted_at,
        ])->save();
    }

    /** The saved WhatsApp login stopped working; the agency has to scan the QR code again. */
    public function markNeedsRelink(?string $reason = null): void
    {
        $this->forceFill(['status' => WaChannelStatus::NeedsRelink, 'last_error' => $reason ?: 'Sesi WhatsApp rusak, scan QR ulang.', 'down_since' => $this->downSince()])->save();
    }

    /** When the current outage began: kept from the first sign of it. */
    private function downSince(): mixed
    {
        return $this->status === WaChannelStatus::Connected || $this->down_since === null ? now() : $this->down_since;
    }

    /** A gateway number that uses the platform's own gateway (QR only) instead of agency-entered details. */
    public function usesPlatformGateway(): bool
    {
        return $this->type === WaChannelType::Gateway
            && blank($this->credential('base_url'))
            && filled(config('whatsapp.gateway.url'));
    }

    public function webhookUrl(): string
    {
        return route('webhooks.whatsapp.'.$this->type->value, ['token' => $this->webhook_token]);
    }

    public function introText(): string
    {
        return filled($this->intro_template) ? $this->intro_template : self::DEFAULT_INTRO;
    }
}
