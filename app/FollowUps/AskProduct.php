<?php

namespace App\FollowUps;

use App\Catalog\ProductMatcher;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use Illuminate\Support\Collection;

/**
 * The one "Tanya produk" message per tenant, sent once to a lead without a product in the first stage while the
 * general follow-ups are not used. It lists the active products ({daftar_produk}) and keeps the order it listed
 * them in, so a reply "2" gives the lead the second product. Runs inside the tenant.
 */
class AskProduct
{
    public const KIND = 'ask_product';

    public const DEFAULT_BODY = "Halo kak {nama} 😊 Kemarin kakak sempat tanya produk {agensi} ya. Kakak tertarik yang mana?\n\n{daftar_produk}\n\nBalas angkanya saja ya kak 😊";

    /** How many products the list shows; a longer catalogue is cut here. */
    public const MAX_PRODUCTS = 10;

    /** A reply that maps to a product must come within this many days of the message. */
    public const REPLY_DAYS = 7;

    /** The tenant's rule, made with its defaults the first time it is needed. */
    public static function rule(): FollowUpRule
    {
        $existing = FollowUpRule::query()->where('kind', self::KIND)->first();

        if ($existing !== null) {
            return $existing;
        }

        $stage = Stage::query()->where('type', 'open')->orderBy('position')->first();

        return FollowUpRule::query()->create([
            'name' => 'Tanya produk', 'kind' => self::KIND, 'stage_id' => $stage?->getKey(),
            'delay_days' => 1, 'send_time' => '10:00', 'body' => self::DEFAULT_BODY, 'active' => true,
        ]);
    }

    /** @return Collection<int, Property> the products the message lists, in its order */
    public static function products(): Collection
    {
        return Property::query()->where('status', '!=', 'sold')->orderBy('name')->orderBy('id')->limit(self::MAX_PRODUCTS)->get();
    }

    /** "1. CAPRA Pro Rp349.000 (dari ~Rp499.000~)", one product per line. */
    public static function listText(Collection $products): string
    {
        return $products->values()->map(function (Property $p, int $i) {
            $price = $p->price_from ? ' '.self::rupiah((int) $p->price_from) : '';
            $was = $p->price_from && $p->compare_price && (int) $p->compare_price > (int) $p->price_from ? ' (dari ~'.self::rupiah((int) $p->compare_price).'~)' : '';

            return ($i + 1).'. '.$p->name.$price.$was;
        })->implode("\n");
    }

    /**
     * A reply naming a number from the message ("2", "no 2", "yang 2 kak") gives a lead without a product the
     * product listed under that number. Returns the product, or null when the reply is not such an answer.
     */
    public static function answer(Lead $lead, ?string $reply): ?Property
    {
        if ($lead->property_id !== null || ! preg_match('/^\s*(?:(?:no(?:mor)?|yang|pilih|nomer)\.?\s*)?(\d{1,2})(?![\d.,])\b[^\d]{0,30}$/iu', (string) $reply, $m)) {
            return null;
        }

        $log = FollowUpLog::query()->where('lead_id', $lead->getKey())->where('status', 'sent')->whereNotNull('product_ids')
            ->where('created_at', '>=', now()->subDays(self::REPLY_DAYS))->latest('id')->first();

        $id = $log?->product_ids[(int) $m[1] - 1] ?? null;
        $product = $id ? Property::query()->find($id) : null;

        if ($product === null) {
            return null;
        }

        ProductMatcher::set($lead, $product, 'ask_reply', trim((string) $reply));

        return $product;
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
