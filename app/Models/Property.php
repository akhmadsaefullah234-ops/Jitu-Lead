<?php

namespace App\Models;

use App\Enums\PropertyKind;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['kind', 'name', 'property_type', 'location', 'developer', 'price_from', 'status', 'details', 'weight_grams', 'length_cm', 'width_cm', 'height_cm', 'ad_keywords', 'compare_price', 'promo_text', 'welcome_text', 'shipping_promos', 'access_url', 'access_file_path', 'access_file_name', 'access_file_size', 'access_days', 'access_max_downloads'])]
class Property extends Model
{
    use BelongsToTenant;

    // A shop's products have no kind or status picker, so they start as these
    protected $attributes = ['kind' => 'primary', 'status' => 'available'];

    /**
     * A digital product's file stays on the private disk under its own tenant; its size is kept for the catalogue.
     * A path from anywhere else is dropped, so one agency can never point at another's file.
     */
    protected static function booted(): void
    {
        static::saving(function (Property $product) {
            if (! $product->isDirty('access_file_path')) {
                return;
            }

            $path = (string) $product->access_file_path;
            $tenantId = $product->tenant_id ?? app(CurrentTenant::class)->id();

            if ($path === '' || $tenantId === null || ! str_starts_with($path, $tenantId.'/') || str_contains($path, '..')) {
                $product->forceFill(['access_file_path' => null, 'access_file_name' => null, 'access_file_size' => null]);

                return;
            }

            $product->access_file_size = Storage::disk('digital')->exists($path) ? Storage::disk('digital')->size($path) : null;
            $product->access_file_name = Str::limit(basename((string) ($product->access_file_name ?: $path)), 255, '');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => PropertyKind::class,
            'details' => 'array',
            'ad_keywords' => 'array',
            'shipping_promos' => 'array',
        ];
    }

    /** A digital product with its own private file (Katalog > Berkas produk). */
    public function hasAccessFile(): bool
    {
        return filled($this->access_file_path);
    }

    /** AI knowledge that only applies to this product. */
    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(AiKnowledgeItem::class);
    }

    /** This product's own follow-up sequence; it replaces the general one for the stages it covers. */
    public function followUpRules(): HasMany
    {
        return $this->hasMany(FollowUpRule::class);
    }
}
