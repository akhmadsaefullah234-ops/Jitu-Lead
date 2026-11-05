<?php

namespace App\Ai;

use App\Models\Lead;
use App\Models\Tenant;
use App\Regions\RegionDirectory;
use App\Shipping\Regions;
use App\Shipping\Shipping;
use App\Shipping\ShippingQuote;
use Throwable;

/**
 * Shipping checked by the server before the AI answers, from what we already know: the address a buyer filled
 * in a form or landing page, or a city named on its own ("Palembang"). The result goes to the model as a
 * cek_ongkir call it already made, so it answers price, shipping and total questions without asking for the
 * address again, and every amount it may quote is in the result. Must run inside the tenant.
 */
class LeadQuote
{
    /** Words that mean the buyer asks what it costs, or picks how to pay. */
    private const ASKS = '/\b(?:harga\w*|berapa\w*|brp|total\w*|biaya\w*|ongkir\w*|ongkos\w*|bayar\w*|pembayaran|cod|transfer|tf|trf|rincian)\b/iu';

    /**
     * @param  array{kota: string, provinsi: ?string, kecamatan: ?string}  $destination
     * @param  list<array{product_id?: int, produk?: string, varian?: ?string, jumlah: int}>  $items
     */
    public function __construct(
        public readonly ShippingQuote $quote,
        public readonly array $destination,
        public readonly array $items,
        public readonly bool $fromChat = false,
    ) {}

    public static function asks(string $text): bool
    {
        return preg_match(self::ASKS, $text) === 1;
    }

    /**
     * The check for this message: a city typed on its own is the destination; otherwise, when the buyer asks about
     * cost or payment, the lead's own address. Returns the provinces to ask about when the city is ambiguous.
     *
     * @return self|list<string>|null
     */
    public static function forMessage(Tenant $tenant, Lead $lead, string $text, ?ProductContext $products = null): self|array|null
    {
        $place = self::place($text);

        if (is_array($place) && array_is_list($place)) {
            return $place;
        }

        if ($place !== null) {
            return self::run($tenant, $lead, $place, $products, true);
        }

        return self::asks($text) ? self::forLead($tenant, $lead, $products) : null;
    }

    /** The check for the lead's own address, or null when it holds no city or no product. */
    public static function forLead(Tenant $tenant, Lead $lead, ?ProductContext $products = null): ?self
    {
        $destination = self::destination($lead);

        return $destination === null ? null : self::run($tenant, $lead, $destination, $products);
    }

    /** @return array{kota: string, provinsi: ?string, kecamatan: ?string}|null */
    public static function destination(Lead $lead): ?array
    {
        $parts = (array) $lead->shipping_address_parts;
        $province = $lead->shipping_province ?: ($parts['provinsi'] ?? null);
        $city = trim((string) ($parts['kota'] ?? ''));

        if ($city === '' && filled($lead->shipping_address)) {
            // A typed address: the city is usually one of the last parts ("..., Kec. Coblong, Kota Bandung 40134").
            $segments = array_slice(array_reverse(preg_split('/[,\n]+/u', (string) $lead->shipping_address)), 0, 4);

            foreach ($segments as $segment) {
                $segment = trim(preg_replace('/\b\d{5}\b/', '', $segment));

                if ($segment === '' || preg_match('/\d/', $segment)) {
                    continue;
                }

                $match = RegionDirectory::matchCity($segment, $province);

                if ($match !== null || Regions::provinceOfCity($segment) !== null) {
                    $city = $match['name'] ?? $segment;
                    $province ??= $match['province'] ?? Regions::provinceOfCity($segment);
                    break;
                }
            }
        }

        return $city === '' ? null : ['kota' => $city, 'provinsi' => $province, 'kecamatan' => $parts['kecamatan'] ?? null];
    }

    /**
     * A message that is only a city or regency name ("Palembang", "Kab Bekasi kak"): its destination, the provinces
     * to choose from when it is in more than one, or null when it is not a place on its own.
     *
     * @return array{kota: string, provinsi: ?string, kecamatan: null}|list<string>|null
     */
    public static function place(string $text): ?array
    {
        $words = trim(preg_replace(['/[^\p{L}\s.]/u', '/\s+/u'], ' ', $text));

        if ($words === '' || mb_strlen($words) > 40 || preg_match('/[\d?]/', $text) || count(explode(' ', $words)) > 4 || ReplySignals::acknowledgement($text)) {
            return null;
        }

        $name = trim(preg_replace(['/^(?:kirim\s+)?(?:ke|di)\s+/iu', '/\s+(?:kak|ka|kakak|min|gan|sis|mbak|mba|mas|bang|pak|bu|ya|yaa)\.?$/iu'], '', $words));

        if (mb_strlen($name) < 3 || preg_match(self::ASKS, $name)) {
            return null;
        }

        $provinces = RegionDirectory::cityProvinces($name);

        if (count($provinces) > 1) {
            return $provinces;
        }

        $match = RegionDirectory::matchCity($name);
        $province = $match['province'] ?? $provinces[0] ?? Regions::provinceOfCity($name);

        return $province === null ? null : ['kota' => $match['name'] ?? $name, 'provinsi' => $province, 'kecamatan' => null];
    }

    /** @return list<array{product_id?: int, produk?: string, varian?: ?string, jumlah: int}> */
    public static function items(Lead $lead, ?ProductContext $products = null): array
    {
        $items = collect((array) $lead->order_items)->filter(fn ($i) => is_array($i) && (filled($i['product_id'] ?? null) || filled($i['name'] ?? null)))
            ->map(fn (array $i) => array_filter(['product_id' => $i['product_id'] ?? null, 'produk' => $i['name'] ?? null, 'varian' => $i['variant'] ?? null,
                'jumlah' => max(1, (int) ($i['quantity'] ?? 1))], fn ($v) => $v !== null))
            ->values()->all();

        if ($items !== []) {
            return $items;
        }

        $product = $lead->property_id ?? $products?->product()?->getKey();

        return $product === null ? [] : [array_filter(['product_id' => (int) $product, 'varian' => $lead->variant, 'jumlah' => max(1, (int) ($lead->quantity ?: 1))], fn ($v) => $v !== null)];
    }

    /** As a cek_ongkir call, for the list of tool calls the answer is checked against. */
    public function call(): array
    {
        return ['name' => ShopTools::CHECK, 'input' => ['items' => $this->items] + array_filter($this->destination), 'result' => $this->quote->toArray()];
    }

    /** What the model is told about the check. */
    public function instructions(): string
    {
        if (! $this->quote->ok()) {
            return 'CEK ONGKIR SUDAH DIJALANKAN untuk '.($this->fromChat ? 'kota yang baru disebut pembeli' : 'alamat pembeli yang tercatat')
                .': '.$this->quote->message.' Ikuti petunjuk itu.';
        }

        return implode("\n", [
            'CEK ONGKIR SUDAH DIJALANKAN untuk '.($this->fromChat ? 'kota yang tampaknya baru disebut pembeli sebagai tujuan' : 'alamat kirim pembeli yang sudah tercatat').' (tujuan: '.$this->quote->destination().'). Hasil '.ShopTools::CHECK.':',
            json_encode($this->quote->toArray(), JSON_UNESCAPED_UNICODE),
            'Bila pembeli menanyakan harga, biaya, total, atau ongkir, atau memilih COD atau transfer, jawab dari hasil ini tanpa menanyakan alamat lagi: '
                .'rincian harga produk x jumlah, ongkir tiap kurir, dan total tiap kurir, persis seperti angka di hasil. Sebut COD bila PENGETAHUAN menyatakan COD tersedia. '
                .'Panggil '.ShopTools::CHECK.' lagi hanya bila pembeli mengganti produk, jumlah, atau tujuan.',
        ]);
    }

    private static function run(Tenant $tenant, Lead $lead, array $destination, ?ProductContext $products, bool $fromChat = false): ?self
    {
        $items = self::items($lead, $products);

        if ($items === []) {
            return null;
        }

        try {
            $quote = Shipping::quote($tenant, $destination['kota'], $destination['provinsi'], $items, $destination['kecamatan']);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return new self($quote, $destination, $items, $fromChat);
    }
}
