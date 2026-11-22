<?php

namespace App\Ai;

use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Payments\PaymentInstructions;
use App\Shipping\ShippingQuote;
use Illuminate\Support\Collection;

/**
 * The tool a digital-product agency's AI may call (industry "digital"): catat_pembelian records what the buyer wants
 * to buy, priced from the catalogue, with no address, courier or shipping cost. It is only collected here; the lead
 * is written once the answer is really sent (App\Actions\RecordPurchase via GenerateAiReply::applyActions). Nothing
 * here, or in the AI's answer, ever marks a purchase paid: that is the admin's "Pembayaran diterima" (later the
 * payment gateway).
 */
class DigitalTools
{
    public const RECORD = 'catat_pembelian';

    public const DRAFT = 'draft';

    public const CONFIRMED = 'konfirmasi';

    /** How many catalogue products the model is shown. */
    public const MAX_PRODUCTS = 60;

    public const SYSTEM_SENDS_NOTE = 'Pembelian dicatat, bayar transfer. Rekening tujuan, nominal transfer (bisa termasuk kode unik) dan batas bayar DIKIRIM OTOMATIS oleh sistem '
        .'di pesan berikutnya. Konfirmasi singkat bahwa pembelian dicatat dan sebut total dari hasil ini, lalu katakan instruksi pembayaran dikirim di pesan berikut. '
        .'Jangan menulis nomor rekening, nama bank, atau kode unik sendiri. Jangan menyatakan pembayaran diterima atau akses aktif.';

    /** @var list<array{type: string, input: array, result: array}> */
    private array $actions = [];

    public function __construct(private Tenant $tenant, private Lead $lead) {}

    public static function for(Tenant $tenant, ?Lead $lead): ?self
    {
        return $tenant->industry()->key === 'digital' && $lead !== null ? new self($tenant, $lead) : null;
    }

    /** @return list<array{name: string, description: string, input_schema: array}> */
    public function definitions(): array
    {
        return [[
            'name' => self::RECORD,
            'description' => 'Catat pembelian produk digital ke data lead. Tanpa alamat, kurir, atau ongkir. status "draft": pembeli sudah menyebut produk dan jumlahnya tapi belum '
                .'memastikan; rangkum lalu minta konfirmasi. status "konfirmasi": pembeli sudah memastikan mau membeli; total dihitung sistem dari katalog, lead pindah ke '
                .'Menunggu pembayaran. Tool ini tidak pernah menandai lunas.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Produk yang dibeli.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'product_id' => ['type' => 'integer', 'description' => 'Id produk dari daftar PRODUK DIGITAL.'],
                                'produk' => ['type' => 'string', 'description' => 'Nama produk bila id tidak diketahui.'],
                                'jumlah' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Jumlah lisensi atau akses; bawaan 1.'],
                            ],
                        ],
                    ],
                    'status' => ['type' => 'string', 'enum' => [self::DRAFT, self::CONFIRMED]],
                    'nama' => ['type' => 'string', 'description' => 'Nama pembeli bila disebut.'],
                    'email' => ['type' => 'string', 'description' => 'Email untuk pengiriman akses bila disebut.'],
                    'perangkat' => ['type' => 'string', 'description' => 'Perangkat pembeli bila disebut, mis. Android, iPhone, Windows, Mac.'],
                ],
                'required' => ['items', 'status'],
            ],
        ]];
    }

    public function run(string $name, array $input): array
    {
        if ($name !== self::RECORD) {
            return ['status' => 'error', 'pesan' => 'Tool tidak dikenal.'];
        }

        $priced = self::price((array) ($input['items'] ?? []));

        if ($priced['lines'] === []) {
            return ['status' => 'produk_belum_jelas', 'pesan' => 'Tanyakan produk mana yang dibeli, sebut sesuai daftar PRODUK DIGITAL.'];
        }

        $draft = ($input['status'] ?? null) !== self::CONFIRMED;
        $result = array_filter([
            'status' => $draft ? 'draft_tercatat' : 'tercatat',
            'pesan' => $draft
                ? 'Rangkum pembeliannya (produk, jumlah, total) lalu minta pembeli memastikan. Jangan menanyakan alamat atau kurir.'
                : (PaymentInstructions::applies($this->tenant) ? self::SYSTEM_SENDS_NOTE
                    : 'Pembelian dicatat. Minta pembeli membayar sebesar total dengan cara bayar yang tertulis di pengetahuan. Jangan menyatakan pembayaran diterima atau akses aktif.'),
            'produk' => collect($priced['lines'])->map(fn ($l) => $l['name'].($l['quantity'] > 1 ? ' x'.$l['quantity'] : ''))->implode(', '),
            'total' => $priced['total'],
            'total_teks' => $priced['total'] !== null ? ShippingQuote::rupiah($priced['total']) : null,
            'catatan' => $priced['total'] === null ? 'Harga belum diisi di katalog: jangan menyebut total, katakan admin akan mengonfirmasi harganya.' : null,
        ], fn ($v) => $v !== null);

        $this->actions[] = ['type' => 'purchase', 'input' => $input, 'result' => $result];

        return $result;
    }

    /**
     * The catalogue products named, with their price and quantity, and the total (null when one has no price).
     *
     * @return array{lines: list<array{product_id: int, name: string, price: ?int, quantity: int}>, total: ?int}
     */
    public static function price(array $items): array
    {
        $catalogue = Property::query()->orderBy('name')->get();
        $lines = [];

        foreach ($items as $item) {
            $product = self::find($catalogue, (array) $item);

            if ($product !== null) {
                $lines[] = ['product_id' => (int) $product->getKey(), 'name' => (string) $product->name,
                    'price' => $product->price_from !== null ? (int) $product->price_from : null, 'quantity' => max(1, (int) ($item['jumlah'] ?? 1))];
            }
        }

        $total = $lines !== [] && collect($lines)->every(fn ($l) => $l['price'] !== null)
            ? (int) collect($lines)->sum(fn ($l) => $l['price'] * $l['quantity']) : null;

        return ['lines' => $lines, 'total' => $total];
    }

    /** @param  Collection<int, Property>  $catalogue */
    private static function find(Collection $catalogue, array $item): ?Property
    {
        if (filled($item['product_id'] ?? null) && ($p = $catalogue->firstWhere('id', (int) $item['product_id']))) {
            return $p;
        }

        $name = mb_strtolower(trim((string) ($item['produk'] ?? '')));

        return $name === '' ? null : ($catalogue->first(fn (Property $p) => mb_strtolower($p->name) === $name)
            ?? $catalogue->first(fn (Property $p) => str_contains(mb_strtolower($p->name), $name) || str_contains($name, mb_strtolower($p->name))));
    }

    /** @return list<array{type: string, input: array, result: array}> what this answer recorded, written once it is sent */
    public function actions(): array
    {
        return $this->actions;
    }

    public function forget(): void
    {
        $this->actions = [];
    }

    /** Extra system prompt: the rules and the catalogue the tool refers to. */
    public function instructions(): string
    {
        $products = Property::query()->orderBy('name')->limit(self::MAX_PRODUCTS)->get()
            ->map(fn (Property $p) => "{$p->getKey()} | {$p->name} | ".($p->price_from !== null ? ShippingQuote::rupiah((int) $p->price_from) : 'harga belum diisi'))
            ->implode("\n");

        return implode("\n", array_filter([
            'PEMBELIAN PRODUK DIGITAL:',
            'Produk ini digital: JANGAN menanyakan alamat, kota, kurir, atau ongkir, dan jangan menyebut pengiriman barang.',
            'Begitu pembeli menyebut produk yang mau dibeli, panggil '.self::RECORD.' dengan status draft, rangkum produk dan total dari hasil tool, lalu minta pembeli memastikan.',
            'Setelah pembeli memastikan, panggil '.self::RECORD.' dengan status konfirmasi dan sampaikan total dari hasil tool. Sebut hanya angka yang tertulis di hasil tool.',
            'Bila pembeli menyebut email atau perangkatnya, isi di '.self::RECORD.'. Minta email bila belum ada, untuk pengiriman akses.',
            'Jangan pernah menyatakan pembayaran sudah diterima, lunas, atau akses sudah aktif. Bila pembeli mengirim atau menyebut bukti transfer, katakan admin akan mengeceknya dulu dan akses dikirim setelah pembayaran dikonfirmasi.',
            PaymentInstructions::applies($this->tenant) ? 'REKENING: nomor rekening dan instruksi bayar dikirim otomatis oleh sistem setelah pembelian dikonfirmasi. Jangan pernah menulis nomor rekening atau nama bank sendiri.' : null,
            $products !== '' ? "PRODUK DIGITAL (id | nama | harga):\n".$products : null,
        ]));
    }
}
