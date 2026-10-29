<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * The kind of business an agency runs (tenants.industry) and what follows from it in config/industries.php:
 * the starting template, which lead fields show and their labels, and the words the app uses.
 */
class Industry
{
    private function __construct(public readonly string $key) {}

    public static function of(?Tenant $tenant): self
    {
        return self::make($tenant?->industry);
    }

    /** The industry of the current request's agency; the default one when there is none. */
    public static function current(): self
    {
        return self::of(app(CurrentTenant::class)->get());
    }

    public static function make(?string $key): self
    {
        return new self(array_key_exists((string) $key, config('industries.industries')) ? $key : config('industries.default'));
    }

    /** A word or phrase of the current agency's industry, e.g. term('catalog') is "Properti" or "Produk". */
    public static function t(string $key): string
    {
        return self::current()->term($key);
    }

    /** @return array<string, string> key => label, for a picker */
    public static function options(): array
    {
        return array_map(fn (array $i) => $i['label'], config('industries.industries'));
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config("industries.industries.{$this->key}.{$key}", $default);
    }

    public function label(): string
    {
        return $this->config('label');
    }

    public function term(string $key): string
    {
        return $this->config("terms.{$key}") ?? config('industries.industries.'.config('industries.default').".terms.{$key}") ?? $key;
    }

    /** @return array<string, string> lead column => label, in form order */
    public function leadFields(): array
    {
        return $this->config('lead_fields', []);
    }

    public function usesLeadField(string $field): bool
    {
        return array_key_exists($field, $this->leadFields());
    }

    /** @return list<string> every industry-specific lead column across all industries */
    public static function allLeadFields(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (array $i) => array_keys($i['lead_fields']), array_values(config('industries.industries'))))));
    }
}
