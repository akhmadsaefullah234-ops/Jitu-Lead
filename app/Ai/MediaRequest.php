<?php

namespace App\Ai;

use App\Media\Library;
use App\Models\MediaAsset;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * A shop buyer asking to see the product ("ada fotonya kak?", "minta video real pict"). The AI answers it from the
 * media library (Pustaka media) or a video link written in the agency's knowledge, instead of handing the chat to a
 * person. Used for retail chats only (App\Actions\GenerateAiReply).
 */
final class MediaRequest
{
    private const ASKS = '/(?<![\p{L}])(?:foto|fotonya|poto|potonya|photo|gambar|gambarnya|pic|pict|picture|real\s*pic\w*|video|videonya|vidio|vidionya|vid)(?![\p{L}])/iu';

    private const VIDEO = '/(?<![\p{L}])(?:video|videonya|vidio|vidionya|vid)(?![\p{L}])/iu';

    /** Hosts of video links the agency may have written in its knowledge. */
    private const VIDEO_LINK = '~https?://(?:www\.|m\.|vt\.|vm\.)?(?:youtube\.com|youtu\.be|tiktok\.com|instagram\.com|vimeo\.com|drive\.google\.com|facebook\.com|fb\.watch)/[^\s<>"\')\]]+~iu';

    /** Told to the model when the buyer asks to see the product and the library or the knowledge has something. */
    public const NOTE = 'FOTO/VIDEO: pembeli meminta foto atau video produk. Jangan menyerahkan ke admin karena ini. Kirim lampiran yang cocok dengan baris terakhir "LAMPIRAN: <id>" (id dari daftar LAMPIRAN), '
        .'atau sebut tautan video yang tertulis di PENGETAHUAN apa adanya. Tulis satu kalimat singkat pengantar, mis. "Ini fotonya kak 😊".';

    /** What the buyer gets when the model still hands off although the library has the product's photo. */
    public const REPLY = 'Ini %s produknya ya kak 😊';

    public const VIDEO_REPLY = 'Ini videonya ya kak 😊';

    /** The rule in the shop prompt, shown when the agency has a media library or video links. */
    public const RULE = 'FOTO DAN VIDEO: bila pembeli meminta foto, gambar, atau video produk, kirim lampiran yang cocok dari daftar LAMPIRAN (baris terakhir "LAMPIRAN: <id>") '
        .'atau sebut tautan video yang ada di PENGETAHUAN. Jangan menyerahkan ke admin hanya karena permintaan foto atau video bila lampiran atau tautannya ada. '
        .'Bila tidak ada sama sekali, baru serahkan ke admin.';

    public static function asks(string $text): bool
    {
        return preg_match(self::ASKS, $text) === 1;
    }

    public static function wantsVideo(string $text): bool
    {
        return preg_match(self::VIDEO, $text) === 1;
    }

    /**
     * Up to $max library items for the product the chat is about (its name in the item's title, description or
     * folder), the variant the buyer named first. A video is preferred when one was asked for. Without a product
     * only items named in the buyer's own words are picked; nothing is guessed.
     *
     * @param  Collection<int, MediaAsset>  $library
     * @return list<int>
     */
    public static function pick(Collection $library, ?Property $product, string $text, ?string $variant = null, int $max = 2): array
    {
        $video = self::wantsVideo($text);
        $productWords = self::words((string) $product?->name);
        $textWords = self::words($text);
        $variants = collect(explode(';', (string) $variant))->map(fn ($v) => mb_strtolower(trim($v)))->filter(fn ($v) => mb_strlen($v) >= 2);

        return $library
            ->map(function (MediaAsset $asset) use ($video, $productWords, $textWords, $variants) {
                $type = $asset->isLink() ? 'link' : Library::whatsAppType((string) $asset->original_name);

                if (! in_array($type, ['image', 'video', 'link'], true)) {
                    return null;
                }

                $about = mb_strtolower($asset->title.' '.$asset->description.' '.$asset->folder?->name);
                $hits = fn (array $words) => count(array_filter($words, fn ($w) => preg_match('/(?<![\p{L}\d])'.preg_quote($w, '/').'(?![\p{L}\d])/u', $about) === 1));
                $product = $productWords !== [] ? $hits($productWords) / count($productWords) : 0;
                $named = $hits($textWords);

                // The product's own photos; without a product, only what the buyer named.
                if ($productWords !== [] ? $product < 0.5 : $named === 0) {
                    return null;
                }

                $score = $product * 10 + $named
                    + ($variants->contains(fn ($v) => preg_match('/(?<![\p{L}])'.preg_quote($v, '/').'(?![\p{L}])/u', $about) === 1) ? 5 : 0)
                    + ($video ? (in_array($type, ['video', 'link'], true) ? 4 : 0) : ($type === 'image' ? 4 : 0));

                return ['id' => (int) $asset->getKey(), 'score' => $score];
            })
            ->filter()
            ->sortByDesc('score')
            ->take($max)
            ->pluck('id')
            ->values()
            ->all();
    }

    /** @return list<string> video links written in a text (the agency's knowledge) */
    public static function videoLinks(string $text): array
    {
        preg_match_all(self::VIDEO_LINK, $text, $m);

        return array_values(array_unique(array_map(fn ($u) => rtrim($u, '.,;:!?'), $m[0])));
    }

    /**
     * The library the model is shown, at most $limit items: for a retail chat the items of the product the chat is
     * about come first, so a shop with a large library still has that product's photos in the list.
     *
     * @param  Collection<int, MediaAsset>  $all
     * @return Collection<int, MediaAsset>
     */
    public static function rank(Collection $all, ?Property $product, int $limit): Collection
    {
        if ($all->count() <= $limit || $product === null) {
            return $all->take($limit)->values();
        }

        $own = collect(self::pick($all, $product, '', null, $limit));

        return $all->filter(fn (MediaAsset $a) => $own->contains($a->getKey()))
            ->merge($all->reject(fn (MediaAsset $a) => $own->contains($a->getKey())))
            ->take($limit)->values();
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $stop = ['kak', 'ka', 'min', 'ada', 'ga', 'gak', 'nggak', 'dong', 'ya', 'yang', 'dan', 'untuk', 'mau', 'minta', 'boleh', 'bisa', 'lihat', 'liat', 'kirim', 'kirimin',
            'foto', 'fotonya', 'poto', 'photo', 'gambar', 'gambarnya', 'pic', 'pict', 'picture', 'real', 'video', 'videonya', 'vidio', 'vid', 'produk', 'barang', 'barangnya', 'nya', 'ini', 'itu', 'sih', 'aja'];

        preg_match_all('/[\p{L}\d]{3,}/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_diff($m[0], $stop)));
    }
}
