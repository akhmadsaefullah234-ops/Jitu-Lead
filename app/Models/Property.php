<?php

namespace App\Models;

use App\Enums\PropertyKind;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kind', 'name', 'property_type', 'location', 'developer', 'price_from', 'status', 'details', 'weight_grams', 'length_cm', 'width_cm', 'height_cm', 'ad_keywords', 'compare_price', 'promo_text'])]
class Property extends Model
{
    use BelongsToTenant;

    // A shop's products have no kind or status picker, so they start as these
    protected $attributes = ['kind' => 'primary', 'status' => 'available'];

    protected function casts(): array
    {
        return [
            'kind' => PropertyKind::class,
            'details' => 'array',
            'ad_keywords' => 'array',
        ];
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
