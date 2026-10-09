<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation code the operator hands out. Not tenant-owned: it exists
 * before the person has an agency.
 */
#[Fillable(['code', 'max_uses', 'expires_at', 'note'])]
class RegistrationInvite extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function normalize(?string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    public static function generate(int $uses = 1, ?int $days = 14, ?string $note = null): self
    {
        $raw = Str::upper(Str::random(10));

        return static::create([
            'code' => $raw,
            'max_uses' => $uses,
            'expires_at' => $days ? now()->addDays($days) : null,
            'note' => $note,
        ]);
    }

    public function scopeRedeemable(Builder $query): void
    {
        $query->whereColumn('uses', '<', 'max_uses')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function usable(?string $code): bool
    {
        $code = static::normalize($code);

        return $code !== '' && static::query()->redeemable()->where('code', $code)->exists();
    }

    /**
     * Uses one place of the code. Atomic, so two people cannot spend the last use together.
     */
    public static function consume(?string $code): bool
    {
        $code = static::normalize($code);

        if ($code === '') {
            return false;
        }

        return static::query()->redeemable()->where('code', $code)->increment('uses') === 1;
    }

    public function display(): string
    {
        return implode('-', str_split($this->code, 5));
    }
}
