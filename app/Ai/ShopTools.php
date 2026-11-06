<?php

namespace App\Ai;

use App\Leads\AddressCheck;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Shipping\CourierChoice;
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

    public const PAYMENTS = ['transfer', 'cod'];

    /** How many catalogue products the model is shown. */
    public const MAX_PRODUCTS = 60;

    /** Words that mean the answer talks about shipping cost. */
    private const SHIPPING_WORDS = '/ongkir|ongkos\s*kirim|biaya\s*(kirim|pengiriman)|tarif\s*(kirim|pengiriman)|total\s*(bayar|pembayaran)?/iu';

    /** @var list<array{name: string, input: array, result: array}> */
    private array $orders = [];

    /** What the buyer wrote in this chat, when known: a courier is priced only when the buyer asked for it. */
    private ?string $buyerText = null;

    /** @param  ?Lead  $lead  the chat's lead: a check for its own city uses its postal code and kelurahan */
    public function __construct(private Tenant $tenant, private ?Lead $lead = null) {}

    public static function for(Tenant $tenant, ?Lead $lead = null): ?self
    {
        return Shipping::available($tenant) ? new self($tenant, $lead) : null;
    }

    /** The buyer's messages so far. A cek_ongkir for another courier is then priced only when the buyer named one or asked for others. */
    public function buyerSaid(string $text): void
    {
        $this->buyerText = $text;
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
            'kelurahan' => ['type' => 'string', 'description' => 'Kelurahan/desa tujuan bila diketahui.'],
            'kode_pos' => ['type' => 'string', 'description' => 'Kode pos tujuan bila diketahui.'],
        ];
        $primary = ShippingSettings::of($this->tenant)->primaryCourier();

        return [
            [
                'name' => self::CHECK,
                'description' => 'Hitung ongkir toko untuk produk dan tujuan tertentu, dengan kurir utama toko ('.$primary.') kecuali pembeli meminta kurir lain. '
                    .'Mengembalikan ongkir, estimasi, dan total, atau status tujuan_belum_jelas / perlu_konfirmasi_admin. Satu-satunya sumber angka ongkir dan total.',
                'input_schema' => ['type' => 'object', 'properties' => ['items' => $items] + $destination + [
                    'kurir' => ['type' => 'string', 'description' => 'Isi HANYA bila pembeli sendiri menyebut ekspedisi tertentu (mis. SiCepat, J&T) atau bertanya kurir lain (isi "'.CourierChoice::ALL.'"). '
                        .'Kosongkan untuk kurir utama toko.'],
                ], 'required' => ['items', 'kota']],
            ],
            [
                'name' => self::RECORD,
                'description' => 'Catat pesanan ke data lead. status "draft": data pesanan sudah lengkap (nama, alamat dengan RT/RW, atau nomor rumah/patokan bila tanpa RT/RW, '
                    .'produk, warna/varian, jumlah) tapi pembeli belum konfirmasi; lead pindah ke Order masuk. status "konfirmasi": pembeli sudah memastikan pesanan '
                    .'dan cara bayar; ongkir dan total dihitung ulang oleh sistem, lalu lead pindah ke Menunggu pembayaran (transfer) atau Dikemas & dikirim (cod).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['items' => $items] + $destination + [
                        'status' => ['type' => 'string', 'enum' => ['draft', 'konfirmasi'], 'description' => 'draft bila belum dikonfirmasi pembeli, konfirmasi bila sudah.'],
                        'nama' => ['type' => 'string', 'description' => 'Nama penerima.'],
                        'alamat' => ['type' => 'string', 'description' => 'Alamat kirim lengkap: nama jalan, nomor rumah atau patokan, RT/RW (atau dusun), kelurahan.'],
                        'kurir' => ['type' => 'string', 'description' => 'Kurir yang diminta pembeli, persis seperti di hasil cek_ongkir. Kosongkan bila pembeli tidak menyebut kurir: kurir utama toko ('.$primary.') dipakai.'],
                        'pembayaran' => ['type' => 'string', 'enum' => self::PAYMENTS, 'description' => 'Cara bayar yang dipilih pembeli: transfer atau cod (bayar di tempat). Bawaan transfer.'],
                    ],
                    'required' => ['items', 'kota', 'alamat', 'status'],
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
        $courier = filled($input['kurir'] ?? null) ? trim((string) $input['kurir']) : null;

        // Another courier is priced only when the buyer asked for one; the AI never offers a choice on its own.
        if ($name === self::CHECK && $courier !== null && $this->buyerText !== null && CourierChoice::fromText($this->buyerText) === null) {
            $courier = null;
        }

        $place = LeadQuote::exactPlace($this->lead, $input['kota'] ?? null);
        $quote = Shipping::quote($this->tenant, $input['kota'] ?? null, $input['provinsi'] ?? null, (array) ($input['items'] ?? []), $input['kecamatan'] ?? null,
            $courier, $input['kode_pos'] ?? $place['kode_pos'] ?? null, $input['kelurahan'] ?? $place['kelurahan'] ?? null);

        if ($name === self::CHECK) {
            return $quote->toArray();
        }

        if ($name !== self::RECORD) {
            return ['status' => 'error', 'pesan' => 'Tool tidak dikenal.'];
        }

        if (! $quote->ok()) {
            return $quote->toArray();
        }

        if (($input['status'] ?? null) === 'draft') {
            if ($problem = self::orderProblem($input)) {
                return ['status' => 'data_belum_lengkap', 'pesan' => $problem];
            }

            $result = ['status' => 'draft_tercatat', 'pesan' => 'Data pesanan dicatat. Rangkum pesanannya dengan kurir '.($quote->options[0]->courier ?? 'utama toko')
                .' lalu minta pembeli konfirmasi dan pilih cara bayar (transfer atau COD). Jangan menanyakan kurir.'];
            $this->orders[] = ['name' => $name, 'input' => $input, 'result' => $result];

            return $result;
        }

        // No courier named: the shop's primary courier, the only one priced.
        $option = $courier === null ? ($quote->options[0] ?? null) : $quote->option($courier);

        if ($option === null) {
            return ['status' => 'kurir_belum_dipilih', 'pesan' => 'Tanyakan kurir: '.implode(', ', array_map(fn ($o) => $o->courier, $quote->options)).'.'];
        }

        if (blank($input['alamat'] ?? null)) {
            return ['status' => 'alamat_belum_lengkap', 'pesan' => 'Tanyakan alamat kirim lengkap.'];
        }

        $total = $quote->subtotal() !== null ? $quote->subtotal() + $option->price : null;
        $result = array_filter([
            'status' => 'tercatat',
            'pesan' => ($input['pembayaran'] ?? null) === 'cod'
                ? 'Pesanan dicatat, bayar di tempat (COD). Sampaikan total yang dibayar saat paket diterima dan bahwa pesanan segera dikemas.'
                : 'Pesanan dicatat. Minta pembeli transfer sebesar total. Jangan menyatakan pembayaran diterima.',
            'kurir' => $option->courier,
            'ongkir' => $option->price,
            'ongkir_teks' => ShippingQuote::rupiah($option->price),
            'potongan_ongkir_teks' => $option->discount() > 0 ? ShippingQuote::rupiah($option->discount()) : null,
            'promo_ongkir' => $option->discount() > 0 ? $option->promo : null,
            'total' => $total,
            'total_teks' => $total !== null ? ShippingQuote::rupiah($total) : null,
        ], fn ($v) => $v !== null);

        $this->orders[] = ['name' => $name, 'input' => $input, 'result' => $result];

        return $result;
    }

    /**
     * What is still missing before a draft order may be written: the buyer's name, the colour or
     * size and quantity of every item, and an address with RT/RW (or, without one, a house number or landmark).
     */
    public static function orderProblem(array $input): ?string
    {
        if (blank($input['nama'] ?? null)) {
            return 'Tanyakan nama penerima.';
        }

        foreach ((array) ($input['items'] ?? []) as $item) {
            if (blank($item['varian'] ?? null) || (int) ($item['jumlah'] ?? 0) < 1) {
                return 'Tanyakan warna/ukuran dan jumlah setiap produk.';
            }
        }

        return self::addressProblem((string) ($input['alamat'] ?? ''));
    }

    /**
     * An address with RT/RW is enough (house number and landmark are optional). A dusun address without one (or with
     * RT/RW 0/000) needs a house number or a landmark ("dekat masjid") to reach the door; any other address is asked
     * for its RT/RW. See App\Leads\AddressCheck.
     */
    public static function addressProblem(string $address): ?string
    {
        if (AddressCheck::textHasRtRw($address)) {
            return null;
        }

        if (AddressCheck::textHasDusun($address) || preg_match(AddressCheck::RT_RW_PATTERN, $address)) {
            return AddressCheck::textHasLandmark($address) ? null : 'Tanyakan nomor rumah atau patokan alamat kirim (alamat tanpa RT/RW).';
        }

        return 'Tanyakan RT/RW alamat kirim. Bila tidak ada RT/RW, tanyakan dusun/kampung dan nomor rumah atau patokan.';
    }

    /** Drops the orders of an answer that was not used, before the model is asked again. */
    public function forget(): void
    {
        $this->orders = [];
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
            'Ongkir di hasil tool sudah dipotong promo ongkir produk. Bila ada promo_ongkir, sebut promonya beserta ongkir_normal_teks dan potongan_ongkir_teks; jangan memotong ongkir lagi.',
            'Bila kota/kabupaten tujuan belum jelas, tanyakan kota atau kecamatannya dulu, jangan memanggil tool.',
            'Bila pembeli menulis singkatan atau nama tidak resmi (mis. Jaksel, Tangsel, Kab Bdg, Jogja), sebut tujuannya dengan nama resmi persis seperti kolom "tujuan" di hasil '.self::CHECK.', dan pakai nama resmi itu di alamat pesanan.',
            'Bila hasil tool berstatus '.ShippingQuote::NEEDS_ADMIN.' atau '.ShippingQuote::NOT_AVAILABLE.', panggil tool '.ReplySignals::HANDOFF_TOOL.'.',
            'KURIR: pakai kurir utama toko'.($settings->primaryCourier() ? ' ('.$settings->primaryCourier().')' : '').'. Jangan menawarkan pilihan kurir, jangan menyebut kurir lain, dan jangan menanyakan kurir. '
                .'Hanya bila pembeli sendiri menyebut ekspedisi tertentu (mis. SiCepat, J&T) atau bertanya kurir lain, panggil '.self::CHECK.' dengan kurir itu (atau "'.CourierChoice::ALL.'" untuk kurir lain).',
            'Begitu data pesanan lengkap (nama penerima, alamat dengan RT/RW, atau nomor rumah/patokan bila tanpa RT/RW, kota, produk, warna/ukuran, jumlah) tapi pembeli belum konfirmasi, panggil '.self::RECORD.' dengan status draft, lalu rangkum pesanannya dan minta pembeli konfirmasi dan pilih cara bayar (transfer atau COD).',
            'Setelah pembeli memastikan pesanan dan cara bayar, panggil '.self::RECORD.' dengan status konfirmasi dan pembayaran transfer atau cod, lalu sampaikan total dari hasil tool. Untuk transfer minta pembeli membayar; untuk COD sampaikan pesanan segera dikemas.',
            'Jangan pernah menyatakan pembayaran sudah diterima, sudah masuk, atau lunas. Bila pembeli mengirim atau menyebut bukti transfer, katakan admin akan mengeceknya dulu.',
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

    /**
     * True when the answer tells the buyer their payment arrived ("pembayaran sudah kami terima", "transfer sudah
     * masuk", "sudah lunas"). Only a person who checked the account may say that, so such an answer is not sent as it is.
     * A payment word has to sit right next to the claim, in the same clause: "pesanan / data / alamat sudah kami
     * terima (catat)" is only an order being received, and "belum lunas" is the opposite of a claim.
     */
    public static function claimsPaymentReceived(string $answer): bool
    {
        $pay = '(?:pembayaran|transfer|dana|uang|bayaran|dp|bukti\s+(?:transfer|bayar|pembayaran))\w*';
        $got = '(?:sudah|telah|udah)\s+(?:kami\s+)?(?:terima|diterima|masuk)';

        return (bool) preg_match('/\b'.$pay.'\b(?:(?!pesanan|order|data|alamat)[^.!?,;\n]){0,30}?\b'.$got.'\b|\b'.$got.'\b\s+(?:ya\s+)?'.$pay.'\b|(?<!belum\s)(?<!tidak\s)\blunas\b/iu', $answer);
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
