<?php

namespace App\Catalog;

use App\Models\Lead;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Finds the catalogue product a chat is about, so many products can run ads at the same time. In this order:
 * the Meta ad id of a Click-to-WhatsApp ad, the ad's title or text, then words in the buyer's first message.
 * Each product lists what recognises it under "Pengenal iklan" (Property::ad_keywords): ad ids, keywords, or
 * pieces of an ad's greeting text; its own name counts as one too. When two products fit equally well, none is
 * picked and the AI asks which one the buyer means. Must run inside the tenant.
 */
class ProductMatcher
{
    /** How the product of a lead was decided => what the lead shows. */
    public const SOURCES = [
        'ad_id' => 'ID iklan',
        'ad_text' => 'judul/teks iklan',
        'keyword' => 'kata kunci di pesan pertama',
        'agent' => 'dipilih agen',
        'ai' => 'percakapan dengan AI',
        'ask_reply' => 'balasan pesan tanya produk',
        'form' => 'formulir',
    ];

    /** An entry of only digits this long is a Meta ad id, never a word to look for. */
    private const AD_ID = '/^\d{6,30}$/';

    /**
     * @param  array<string, mixed>|null  $referral  Click-to-WhatsApp ad data (source_id, headline, body)
     * @param  string|null  $firstMessage  the buyer's first message; null for a later one
     * @return array{product: Property, source: string, match: string}|null
     */
    public function match(?array $referral, ?string $firstMessage): ?array
    {
        $products = Property::query()->get(['id', 'name', 'ad_keywords', 'status']);

        if ($products->isEmpty()) {
            return null;
        }

        $adId = trim((string) ($referral['source_id'] ?? ''));

        if ($adId !== '') {
            $byId = $products->filter(fn (Property $p) => in_array($adId, self::adIds($p), true));
            if ($byId->count() === 1) {
                return ['product' => $byId->first(), 'source' => 'ad_id', 'match' => $adId];
            }
        }

        $adText = trim(($referral['headline'] ?? '').' '.($referral['body'] ?? ''));

        if ($adText !== '' && ($found = $this->byWords($products, $adText))) {
            return $found + ['source' => 'ad_text'];
        }

        if (filled($firstMessage) && ($found = $this->byWords($products, (string) $firstMessage))) {
            return $found + ['source' => 'keyword'];
        }

        return null;
    }

    /**
     * Gives the lead the product its chat points to and notes why. A product an agent picked stays; a new ad
     * click may change any other.
     */
    public function assign(Lead $lead, ?array $referral, ?string $firstMessage): ?Property
    {
        if ($lead->product_source === 'agent' && $lead->property_id !== null) {
            return null;
        }

        $found = $this->match($referral, $firstMessage);

        if ($found === null || ($lead->property_id === $found['product']->getKey())) {
            return null;
        }

        self::set($lead, $found['product'], $found['source'], $found['match']);

        return $found['product'];
    }

    /** Sets the lead's product and writes down how it was decided. */
    public static function set(Lead $lead, ?Property $product, string $source, ?string $detail = null): void
    {
        $lead->forceFill(['property_id' => $product?->getKey(), 'product_source' => $product ? $source : null])->save();

        $lead->activities()->create([
            'type' => 'note',
            'body' => $product
                ? 'Produk diminati: '.$product->name.' (ditentukan dari '.(self::SOURCES[$source] ?? $source).($detail !== null && $detail !== '' ? ': "'.mb_substr($detail, 0, 80).'"' : '').')'
                : 'Produk diminati dikosongkan ('.(self::SOURCES[$source] ?? $source).')',
            'meta' => ['product_source' => $source, 'property_id' => $product?->getKey()],
        ]);
    }

    /**
     * The product whose keyword (or name) appears in the text. When two products are named, it is not clear
     * which one the buyer means and none is picked.
     *
     * @param  Collection<int, Property>  $products
     * @return array{product: Property, match: string}|null
     */
    private function byWords(Collection $products, string $text): ?array
    {
        $haystack = self::normalize($text);
        $best = [];

        foreach ($products as $product) {
            foreach (self::words($product) as $word) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                    $length = mb_strlen($word);
                    if (! isset($best[$product->getKey()]) || $best[$product->getKey()][1] < $length) {
                        $best[$product->getKey()] = [$word, $length];
                    }
                }
            }
        }

        if ($best === []) {
            return null;
        }

        // A match that is only part of another product's longer match ("tas" in "tas olahraga") does not count.
        $left = array_filter($best, fn ($hit, $id) => ! collect($best)->contains(
            fn ($other, $otherId) => $otherId !== $id && $other[1] > $hit[1] && str_contains($other[0], $hit[0])
        ), ARRAY_FILTER_USE_BOTH);

        if (count($left) !== 1) {
            return null;
        }

        $id = array_key_first($left);

        return ['product' => $products->firstWhere('id', $id), 'match' => $left[$id][0]];
    }

    /** @return list<string> the Meta ad ids a product lists */
    public static function adIds(Property $product): array
    {
        return array_values(array_filter(array_map(fn ($k) => trim((string) $k), (array) $product->ad_keywords), fn ($k) => preg_match(self::AD_ID, $k)));
    }

    /** @return list<string> the words and phrases that point to a product, lower case: its keywords and its name */
    public static function words(Property $product): array
    {
        $words = array_filter(array_map(fn ($k) => self::normalize((string) $k), (array) $product->ad_keywords), fn ($k) => mb_strlen($k) >= 3 && ! preg_match(self::AD_ID, $k));
        $name = self::normalize((string) $product->name);

        return array_values(array_unique([...$words, ...(mb_strlen($name) >= 3 ? [$name] : [])]));
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
