<?php

namespace App\Shipping\Carriers;

use App\Models\CarrierAccount;
use App\Models\Tenant;

/**
 * The agency's courier aggregator, or none. Without a configured provider (Pengaturan > Pengiriman: provider
 * picked, client key and secret saved) every "Buat resi" menu is hidden and nothing calls out.
 */
class Carriers
{
    public static function account(?Tenant $tenant): ?CarrierAccount
    {
        if ($tenant === null) {
            return null;
        }

        $account = CarrierAccount::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->first();

        return $account !== null && array_key_exists($account->provider, (array) config('carriers.providers')) ? $account : null;
    }

    public static function configured(?Tenant $tenant): bool
    {
        return $tenant?->industry()->key === 'retail' && (self::account($tenant)?->hasCredentials() ?? false);
    }

    public static function for(?Tenant $tenant): ?CarrierProvider
    {
        return self::configured($tenant) ? self::make(self::account($tenant)) : null;
    }

    public static function make(CarrierAccount $account): CarrierProvider
    {
        $class = config('carriers.providers.'.$account->provider) ?? throw new CarrierException('Penyedia pengiriman tidak dikenal.');

        return new $class($account);
    }

    /** "everpro" => "Everpro". */
    public static function label(?string $provider): string
    {
        return (string) (config('carriers.labels.'.$provider) ?? ucfirst((string) $provider));
    }

    /** @return array<string, string> provider key => name, for the settings form */
    public static function options(): array
    {
        return collect(array_keys((array) config('carriers.providers')))->mapWithKeys(fn ($k) => [$k => config("carriers.labels.{$k}", ucfirst($k))])->all();
    }
}
