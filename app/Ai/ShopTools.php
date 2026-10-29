<?php

namespace App\Ai;

use App\Models\Property;
use App\Models\Tenant;
use App\Shipping\Shipping;
use App\Shipping\ShippingQuote;
use App\Shipping\ShippingSettings;

/**
 * The tools a shop's AI assistant may call: cek_ongkir prices a parcel and catat_pesanan records a confirmed
 * order. Both go through App\Shipping\Shipping, so the AI never prices anything itself. catat_pesanan is only
 * collected here; the caller applies it once the answer is really sent (App\Actions\RecordOrder).
 */
class ShopTools
{
    public const CHECK = 'cek_ongkir';

    public const RECORD = 'catat_pesanan';

    /** How many catalogue products the model is shown. */
    public const MAX_PRODUCTS = 60;

    /** Words that mean the answer talks about shipping cost. */
    private const SHIPPING_WORDS = '/ongkir|ongkos\s*kirim|biaya\s*(kirim|pengiriman)|tarif\s*(kirim|pengiriman)|total\s*(bayar|pembayaran)?/iu';

    /** @var list<array{name: string, input: array, result: array}> */
    private array $orders = [];

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): ?self
    {
        return Shipping::available($tenant) ? new self($tenant) : null;
    }

    /** @return list<array{name: string, description: string, input_schema: array}> */
    public function definitions(): array
    {
        $items = [
            'type' => 'array',
            'description' => 'Produk yang dipesan.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'Id produk dari daftar PRODUK.'],
                    'produk' => ['type' => 'string', 'description' => 'Nama produk bila id tidak diketahui.'],
                    'varian' => ['type' => 'string', 'description' => 'Ukuran atau varian, mis. L, Hitam.'],
                    'jumlah' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => ['jumlah'],
            ],
        ];
        $destination = [
            'kota' => ['type' => 'string', 'description' => 'Kota atau kabupaten tujuan, mis. Kab. Bekasi.'],
            'provinsi' => ['type' => 'string', 'description' => 'Provinsi tujuan bila diketahui.'],
            'kecamatan' => ['type' => 'string', 'description' => 'Kecamatan tujuan bila diketahui.'],
        ];

        return [
            [
                'name' => self::CHECK,
                'description' => 'Hitung ongkir dari tabel tarif toko untuk produk dan tujuan tertentu. Mengembalikan ongkir, estimasi, dan total per kurir, '
                    .'atau status tujuan_belum_jelas / perlu_konfirmasi_admin. Satu-satunya sumber angka ongkir dan total.',
                'input_schema' => ['type' => 'object', 'properties' => ['items' => $items] + $destination, 'required' => ['items', 'kota']],
            ],
            [
                'name' => self::RECORD,
                'description' => 'Catat pesanan yang sudah dipastikan pembeli (produk, varian, jumlah, alamat lengkap, kurir) ke data lead, dengan ongkir dan total '
                    .'dari tabel tarif, lalu pindahkan lead ke tahap Menunggu pembayaran.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['items' => $items] + $destination + [
                        'alamat' => ['type' => 'string', 'description' => 'Alamat kirim lengkap: nama jalan, nomor, RT/RW, kelurahan.'],
                        'kurir' => ['type' => 'string', 'description' => 'Kurir yang dipilih pembeli, persis seperti di hasil cek_ongkir.'],
                    ],
                    'required' => ['items', 'kota', 'alamat', 'kurir'],
                ],
            ],
        ];
    }

    /**
     * Runs one tool call. catat_pesanan is checked (priced again, courier and address present) but not written
     * yet: it is kept in orders() for the caller. Must run inside the tenant.
     */
    public function run(string $name, array $input): array
    {
        $quote = Shipping::quote($this->tenant, $input['kota'] ?? null, $input['provinsi'] ?? null, (array) ($input['items'] ?? []), $input['kecamatan'] ?? null);

        if ($name === self::CHECK) {
            return $quote->toArray();
        }

        if ($name !== self::RECORD) {
            return ['status' => 'error', 'pesan' => 'Tool tidak dikenal.'];
        }

        if (! $quote->ok()) {
            return $quote->toArray();
        }

        $option = $quote->option((string) ($input['kurir'] ?? ''));

        if ($option === null) {
            return ['status' => 'kurir_belum_dipilih', 'pesan' => 'Tanyakan kurir: '.implode(', ', array_map(fn ($o) => $o->courier, $quote->options)).'.'];
        }

        if (blank($input['alamat'] ?? null)) {
            return ['status' => 'alamat_belum_lengkap', 'pesan' => 'Tanyakan alamat kirim lengkap.'];
        }

        $total = $quote->subtotal() !== null ? $quote->subtotal() + $option->price : null;
        $result = array_filter([
            'status' => 'tercatat',
            'pesan' => 'Pesanan dicatat. Minta pembeli melakukan pembayaran.',
            'kurir' => $option->courier,
            'ongkir' => $option->price,
            'ongkir_teks' => ShippingQuote::rupiah($option->price),
            'total' => $total,
            'total_teks' => $total !== null ? ShippingQuote::rupiah($total) : null,
        ], fn ($v) => $v !== null);

        $this->orders[] = ['name' => $name, 'input' => $input, 'result' => $result];

        return $result;
    }

    /** @return list<array{name: string, input: array, result: array}> the orders the AI recorded in this answer */
    public function orders(): array
    {
        return $this->orders;
    }

    /** Extra system prompt for a shop: the shipping rules and the catalogue the tools refer to. */
    public function instructions(): string
    {
        $settings = ShippingSettings::of($this->tenant);
        $products = Property::query()->orderBy('name')->limit(self::MAX_PRODUCTS)->get()
            ->map(fn (Property $p) => "{$p->getKey()} | {$p->name} | ".($p->price_from !== null ? ShippingQuote::rupiah((int) $p->price_from) : 'harga belum diisi')
                .' | '.($p->weight_grams ? $p->weight_grams.' g' : 'berat bawaan'))
            ->implode("\n");

        return implode("\n", array_filter([
            'ONGKIR DAN PESANAN:',
            'Untuk ongkir WAJIB memanggil tool '.self::CHECK.'. Jangan menghitung, menjumlahkan, mengalikan, atau menebak ongkir maupun total sendiri. Sebut hanya angka yang tertulis di hasil tool, apa adanya.',
            'Bila kota/kabupaten tujuan belum jelas, tanyakan kota atau kecamatannya dulu, jangan memanggil tool.',
            'Bila hasil tool berstatus '.ShippingQuote::NEEDS_ADMIN.' atau '.ShippingQuote::NOT_AVAILABLE.', balas hanya dengan kata HANDOFF.',
            'Setelah pembeli memastikan produk, varian, jumlah, alamat lengkap, dan kurir, panggil '.self::RECORD.', lalu sampaikan total dari hasil tool dan minta pembeli membayar.',
            'Saat memakai tool, kamu boleh menulis satu kalimat singkat dulu. Bila tidak ada tool yang cocok untuk permintaan pembeli, katakan begitu, jangan menebak. Jangan menulis tag XML atau tag sistem apa pun di jawaban.',
            $settings->origin() ? 'Barang dikirim dari '.$settings->origin().'.' : null,
            $products !== '' ? "PRODUK (id | nama | harga | berat):\n".$products : null,
        ]));
    }

    /**
     * True when the answer quotes a shipping cost or total that no tool returned: it mentions shipping and holds an
     * amount of money that is neither in a tool result of this answer nor written in what the model was given
     * (the agency's knowledge and catalogue, and the chat so far).
     *
     * @param  list<array{name: string, input: array, result: array}>  $calls
     */
    public static function inventsShippingCost(string $answer, array $calls, string $given): bool
    {
        if (! preg_match(self::SHIPPING_WORDS, $answer)) {
            return false;
        }

        $amounts = self::amounts($answer);

        if ($amounts === []) {
            return false;
        }

        $allowed = array_merge(self::amounts($given), ...array_map(fn (array $c) => self::amounts(json_encode($c['result'], JSON_UNESCAPED_UNICODE)), $calls));

        return array_diff($amounts, $allowed) !== [];
    }

    /** True when any shipping check in this answer came back "konfirmasi admin" (or not available). */
    public static function needsAdmin(array $calls): bool
    {
        return collect($calls)->contains(fn (array $c) => in_array($c['result']['status'] ?? null, [ShippingQuote::NEEDS_ADMIN, ShippingQuote::NOT_AVAILABLE], true));
    }

    /**
     * Amounts of money in a text, as whole rupiah: "Rp 11.000", "11000", "11rb", "1,5 juta". Small numbers
     * (kg, days, quantities) are left out.
     *
     * @return list<int>
     */
    public static function amounts(string $text): array
    {
        preg_match_all('/(?<![\d.,])(\d+(?:[.,]\d+)*)\s*(ribu|rb|k|juta|jt)?(?![\p{L}\d])/iu', $text, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $match) {
            [, $number, $unit] = $match + [2 => ''];
            $unit = mb_strtolower($unit);
            $multiplier = match ($unit) {
                'ribu', 'rb', 'k' => 1000, 'juta', 'jt' => 1000000, default => 1
            };
            $value = $multiplier === 1
                ? (int) preg_replace('/[.,]/', '', $number)
                : (int) round((float) str_replace(',', '.', preg_replace('/\.(?=\d{3}\b)/', '', $number)) * $multiplier);

            if ($value >= 1000) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }
}
