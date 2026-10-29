<?php

namespace App\Shipping;

use App\Models\Tenant;

/**
 * An agency's shipping setup (tenants.shipping): where parcels leave from, which couriers it uses, how weight
 * is counted, and the zone table. Missing keys fall back to config/shipping.php.
 */
final class ShippingSettings
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private array $data) {}

    public static function of(Tenant $tenant): self
    {
        return new self($tenant->shipping ?? []);
    }

    /** What the settings form starts with for an agency that never saved any. */
    public static function blank(): array
    {
        return [
            'enabled' => false, 'provider' => 'zone', 'origin' => null, 'couriers' => [],
            'default_weight_grams' => config('shipping.default_weight_grams'), 'volumetric_divisor' => config('shipping.volumetric_divisor'),
            'zones' => [],
        ];
    }

    public function enabled(): bool
    {
        return (bool) ($this->data['enabled'] ?? false) && $this->couriers() !== [] && $this->zones() !== [];
    }

    public function provider(): string
    {
        return (string) ($this->data['provider'] ?? 'zone');
    }

    public function origin(): ?string
    {
        return $this->data['origin'] ?? null;
    }

    /** @return list<string> */
    public function couriers(): array
    {
        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), $this->data['couriers'] ?? [])));
    }

    public function defaultWeightGrams(): int
    {
        return max(1, (int) ($this->data['default_weight_grams'] ?? config('shipping.default_weight_grams')));
    }

    public function volumetricDivisor(): int
    {
        return max(1, (int) ($this->data['volumetric_divisor'] ?? config('shipping.volumetric_divisor')));
    }

    /** @return list<array{name: string, provinces: list<string>, cities: list<string>, excluded_cities: list<string>, rates: list<array{courier: string, per_kg: int}>, eta_min: ?int, eta_max: ?int, confirm_admin: bool}> */
    public function zones(): array
    {
        return array_values(array_map(fn (array $z) => [
            'name' => (string) ($z['name'] ?? ''),
            'provinces' => array_values(array_filter($z['provinces'] ?? [])),
            'cities' => array_values(array_filter($z['cities'] ?? [])),
            'excluded_cities' => array_values(array_filter($z['excluded_cities'] ?? [])),
            'rates' => array_values(array_filter($z['rates'] ?? [], fn ($r) => filled($r['courier'] ?? null) && filled($r['per_kg'] ?? null))),
            'eta_min' => filled($z['eta_min'] ?? null) ? (int) $z['eta_min'] : null,
            'eta_max' => filled($z['eta_max'] ?? null) ? (int) $z['eta_max'] : null,
            'confirm_admin' => (bool) ($z['confirm_admin'] ?? false),
        ], $this->data['zones'] ?? []));
    }

    public function toArray(): array
    {
        return $this->data + self::blank();
    }
}
