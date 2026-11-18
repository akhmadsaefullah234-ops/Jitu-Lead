<?php

namespace App\Ai;

use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Lead;
use App\Models\Property;
use App\Shipping\ShippingQuote;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Illuminate\Support\Collection;

/**
 * What the AI is told about the product a chat is about: the lead's product with its catalogue details, or,
 * when it is not known yet and there is more than one, that it should ask which one. The AI names a product
 * it learns from the chat with a "PRODUK: <id>" line, which GenerateAiReply turns into an action. Must run
 * inside the tenant.
 */
class ProductContext
{
    public const MAX_PRODUCTS = 60;

    /** The line the AI writes when the buyer names or switches product. */
    public const MARKER = '/^[ \t]*PRODUK:[ \t]*([\d][\d, \t]*)[ \t]*$/mu';

    /** @var Collection<int, Property>|null */
    private ?Collection $products = null;

    /** The type the client's message names (property agencies only), read before the lead's own product. */
    private ?Property $named = null;

    public function __construct(private ?Lead $lead) {}

    /**
     * What the client just wrote: a property agency's type named in it ("harga tipe 36/72?") becomes the product
     * the chat is about, even before the lead has one. Shops are left as they were.
     */
    public function asked(string $text): self
    {
        $this->named = self::forTypes() && $this->products()->count() > 1 ? PropertyTypes::named($text, $this->products()) : null;

        return $this;
    }

    /** The type the client's last message named, if any. */
    public function named(): ?Property
    {
        return $this->named;
    }

    /** "RINGKASAN TIPE" for a property agency with a few types (PropertyTypes::MAX); null otherwise. */
    public function summary(): ?string
    {
        return self::forTypes() ? PropertyTypes::summary($this->products()) : null;
    }

    private static function forTypes(): bool
    {
        return Industry::current()->key === 'properti';
    }

    /** @return Collection<int, Property> */
    public function products(): Collection
    {
        return $this->products ??= Property::query()->orderBy('name')->limit(self::MAX_PRODUCTS)->get();
    }

    /** The product the chat is about: the type the client just named, the lead's, or the only one in the catalogue. */
    public function product(): ?Property
    {
        if ($this->named !== null) {
            return $this->named;
        }

        $own = $this->lead?->property_id ? Property::query()->find($this->lead->property_id) : null;

        return $own ?? ($this->products()->count() === 1 ? $this->products()->first() : null);
    }

    public function instructions(): ?string
    {
        $products = $this->products();

        if ($products->isEmpty()) {
            return null;
        }

        $term = Industry::t('catalog_item');
        $product = $this->product();
        $lines = [];

        if ($product !== null) {
            $lines[] = "PRODUK PELANGGAN: Pelanggan ini menanyakan {$term}: {$product->name} (id {$product->getKey()}).";
            $lines[] = self::describe($product);
            $lines[] = "Jawab tentang {$term} ini kecuali pelanggan menyebut {$term} lain. Untuk rincian harga pakai harga di atas: bila ada harga normal dan harga promo, sebut harga normal lalu harga promonya. Jangan memakai harga atau promo {$term} lain.";
        } else {
            $names = $products->take(10)->pluck('name')->implode(', ');
            $lines[] = "PRODUK PELANGGAN: belum diketahui {$term} mana yang ditanyakan pelanggan ini.";
            $summary = $this->summary() !== null;
            $lines[] = match (true) {
                $this->asksWithList() => "Bila dari chat belum jelas, tanyakan dulu dengan sopan {$term} mana yang dimaksud, sebutkan pilihannya: {$names}. Bila foto {$term} ada di LAMPIRAN, boleh disertakan. Jangan menyebut harga sebelum {$term}nya jelas.",
                // Arahan AI > "Tanyakan tipe lewat daftar" off: the agency's own flow (greeting, name, purpose) leads.
                default => "Jangan menyodorkan daftar atau pilihan bernomor {$term} kepada pelanggan, dan jangan menunda jawaban sampai {$term}nya jelas. Ikuti alur di arahan agensi. Bila pelanggan menanyakan harga, jawab dengan harga umum yang tertulis di PENGETAHUAN".($summary ? ' (RINGKASAN TIPE memuat harga dan luas setiap '.$term.'; bila belum menyebut '.$term.', jawab singkat dari situ)' : '').". Bila pelanggan menyebut {$term} tertentu, jawab tentang {$term} itu.",
            };
        }

        if ($products->count() > 1) {
            $lines[] = 'DAFTAR '.mb_strtoupper($term).($this->asksWithList() ? '' : ' (hanya untuk baris PRODUK, jangan disodorkan ke pelanggan)')." (id | nama):\n".$products->map(fn (Property $p) => $p->getKey().' | '.$p->name)->implode("\n");
            $lines[] = "Bila pelanggan menyebut, memilih, atau beralih ke {$term} lain dari daftar, tulis di baris terakhir jawabanmu: PRODUK: <id>. Bila ia menambah {$term}, tulis id yang paling ia minati lebih dulu, mis. PRODUK: 3, 7. Jangan tulis baris itu bila {$term}nya tidak berubah.";
        }

        return implode("\n", $lines);
    }

    /** Arahan AI > "Tanyakan tipe lewat daftar" (on unless the agency turned it off). */
    private function asksWithList(): bool
    {
        return AiSettings::of(app(CurrentTenant::class)->get())->asksProductWithList();
    }

    /** One product's catalogue details as the AI reads them. */
    public static function describe(Property $p): string
    {
        $retail = Industry::current()->key !== 'properti';
        $price = $p->price_from !== null ? ShippingQuote::rupiah((int) $p->price_from) : null;
        $details = collect((array) $p->details)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ');

        return implode("\n", array_filter([
            match (true) {
                $price !== null && $p->compare_price > $p->price_from => 'Harga normal (dicoret): '.ShippingQuote::rupiah((int) $p->compare_price)."\nHarga promo: {$price}",
                $price !== null => "Harga: {$price}",
                default => 'Harga: belum diisi (jangan menyebut harga)',
            },
            filled($p->promo_text) ? 'Promo: '.$p->promo_text : null,
            filled($p->property_type) ? ($retail ? 'Kategori: ' : 'Tipe: ').$p->property_type : null,
            ! $retail && filled($p->location) ? 'Lokasi: '.$p->location : null,
            $details !== '' ? ($retail ? 'Varian & rincian: ' : 'Rincian: ').$details : null,
            ($retail ? 'Stok: ' : 'Status: ').(($retail ? PropertyResource::RETAIL_STATUSES : PropertyResource::STATUSES)[$p->status] ?? $p->status),
            $retail && $p->weight_grams ? 'Berat: '.$p->weight_grams.' g' : null,
        ]));
    }

    /**
     * The "PRODUK: 3, 7" lines an answer ends with, taken out of it: the ids that really are products of this
     * agency, in order.
     *
     * @return array{0: string, 1: list<int>}
     */
    public function split(string $answer): array
    {
        $ids = [];

        if (preg_match_all(self::MARKER, $answer, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/[^\d]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $id) {
                    $ids[] = (int) $id;
                }
            }
            $answer = trim(preg_replace(self::MARKER, '', $answer));
            $answer = preg_replace("/\n{3,}/", "\n\n", $answer);
        }

        $known = $this->products()->pluck('id')->all();

        return [$answer, array_values(array_unique(array_filter($ids, fn (int $id) => in_array($id, $known, true))))];
    }
}
