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

#[Fillable(['type', 'name', 'phone', 'status', 'credentials', 'intro_template', 'position', 'last_connected_at', 'last_error', 'ai_mode'])]
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
            'last_connected_at' => 'datetime',
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

    public function markConnected(): void
    {
        $this->forceFill(['status' => WaChannelStatus::Connected, 'last_connected_at' => now(), 'last_error' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => WaChannelStatus::Error, 'last_error' => Str::limit($error, 500)])->save();
    }

    public function markDisconnected(?string $reason = null): void
    {
        $this->forceFill(['status' => WaChannelStatus::Disconnected, 'last_error' => $reason])->save();
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
