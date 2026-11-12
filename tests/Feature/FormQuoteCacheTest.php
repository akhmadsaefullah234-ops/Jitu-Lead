<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\LeadForms\FormFields;
use App\LeadForms\FormQuote;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\User;
use App\Regions\RegionDirectory;
use App\Shipping\Shipping;
use App\Shipping\ShippingItem;
use App\Shipping\ShippingOption;
use App\Shipping\ShippingProvider;
use App\Shipping\ShippingQuote;
use App\Shipping\ShippingRequest;
use App\Shipping\ShippingSettings;
use App\Shipping\ZoneShippingRates;
use App\Support\CurrentTenant;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * The form's order summary keeps its shipping quote in the cache as plain data (ShippingQuote::toData), because
 * the cache reads no objects back (cache.serializable_classes is false). Before, a second buyer with the same
 * address within 10 minutes got "Ongkir akan dikonfirmasi lewat WhatsApp" instead of the price.
 */
class FormQuoteCacheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $bag;

    /** How often the shipping provider was asked for a price. */
    private int $priced = 0;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();

        // As in production: a store that serializes, and no classes read back.
        $this->cacheDir = storage_path('framework/testing/form-quote-cache-'.getmypid());
        config(['cache.serializable_classes' => false, 'cache.default' => 'file', 'cache.stores.file.path' => $this->cacheDir]);
        Cache::purge('file');
        Cache::setDefaultDriver('file');
        Cache::flush();

        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->bag = $this->in(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 199000, 'weight_grams' => 300, 'shipping_promos' => [
            ['type' => 'discount', 'amount' => 30000, 'area' => 'outside_java_bali', 'min_quantity' => 1],
        ]]));

        foreach ([
            ['73', 'Sulawesi Selatan', 1, null, null], ['73.71', 'Kota Makassar', 2, '73', null], ['73.71.01', 'Mariso', 3, '73.71', null],
            ['73.71.01.1001', 'Bontorannu', 4, '73.71.01', '90121'],
        ] as [$code, $name, $level, $parent, $postal]) {
            Region::query()->insert(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent, 'postal_code' => $postal, 'search' => RegionDirectory::searchKey($name)]);
        }
        RegionDirectory::flush();

        // Counts the provider's quotes, still priced by the shop's zone table.
        $this->app->bind(ZoneShippingRates::class, function ($app, array $params) {
            $zone = new ZoneShippingRates($params['settings']);

            return new class($zone, fn () => $this->priced++) implements ShippingProvider
            {
                public function __construct(private ShippingProvider $inner, private \Closure $count) {}

                public function quote(ShippingRequest $request): ShippingQuote
                {
                    ($this->count)();

                    return $this->inner->quote($request);
                }
            };
        });

        $this->form();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_the_cache_stays_closed_to_classes(): void
    {
        $this->assertFalse((require config_path('cache.php'))['serializable_classes']);
    }

    public function test_an_ok_quote_with_a_promo_and_the_primary_courier_round_trips(): void
    {
        $quote = $this->in(fn () => Shipping::quote($this->shop, 'Kota Makassar', 'Sulawesi Selatan', [['product_id' => $this->bag->id, 'jumlah' => 2]]));

        $this->assertTrue($quote->ok());
        $this->assertNotNull($quote->primaryCourier);
        $this->assertGreaterThan(0, $quote->options[0]->discount(), 'the product promo outside Java and Bali');
        $this->assertRoundTrips($quote);
    }

    public function test_a_quote_with_several_options_items_and_every_field_round_trips(): void
    {
        $product = $this->in(fn () => Property::query()->find($this->bag->id));
        $quote = new ShippingQuote(ShippingQuote::OK, '', [
            new ShippingOption('JNE REG', 47000, 2, 3, 77000, 'Diskon ongkir luar Jawa & Bali'),
            new ShippingOption('SiCepat REG', 52000, 3, null),
            new ShippingOption('J&T EZ', 50000, null, null, 50000),
        ], 'Kota Makassar', 'Sulawesi Selatan', 'Sulawesi', 600, 1250, 2, [
            new ShippingItem($product, 'Tas Badminton', 'Hitam', 2),
            new ShippingItem(null, 'Shuttlecock (tidak di katalog)', null, 1),
        ], ShippingSettings::EVERPRO, 'kode pos tujuan belum diketahui', 'JNE REG');

        $this->assertSame($this->constructorFields(ShippingQuote::class), array_keys($quote->toData()), 'every constructor field is kept');
        $this->assertSame($this->constructorFields(ShippingOption::class), array_keys($quote->options[0]->toData()));
        $this->assertSame(30000, $quote->options[0]->discount());
        $this->assertRoundTrips($quote);

        $back = $this->in(fn () => ShippingQuote::fromData($quote->toData()));
        $this->assertSame([30000, 0, 0], array_map(fn (ShippingOption $o) => $o->discount(), $back->options));
        $this->assertSame(['2–3 hari', '3 hari', null], array_map(fn (ShippingOption $o) => $o->eta(), $back->options));
        $this->assertNull($back->subtotal(), 'the shuttlecock has no price');
        $this->assertSame($this->bag->id, $back->items[0]->product?->id);
        $this->assertSame($quote->summary(), $back->summary());
        $this->assertSame($quote->toArray(), $back->toArray(), 'the AI tool answer is the same');
    }

    public function test_quotes_that_are_not_ok_round_trip(): void
    {
        foreach ([
            new ShippingQuote(ShippingQuote::UNCLEAR_DESTINATION, 'Kota tujuan belum disebutkan.'),
            new ShippingQuote(ShippingQuote::NEEDS_ADMIN, 'Ongkir ke Kepulauan Seribu perlu dikonfirmasi admin.', city: 'Kepulauan Seribu', province: 'DKI Jakarta', zone: 'Jabodetabek'),
            new ShippingQuote(ShippingQuote::NOT_AVAILABLE, 'Produk dan jumlahnya belum diketahui.', city: 'Bandung', province: 'Jawa Barat'),
        ] as $quote) {
            $this->assertRoundTrips($quote);
            $this->assertFalse($this->in(fn () => ShippingQuote::fromData($quote->toData()))->ok());
        }
    }

    public function test_data_of_another_shape_is_refused(): void
    {
        $data = (new ShippingQuote(ShippingQuote::OK, '', [new ShippingOption('JNE REG', 47000, 2, 3)]))->toData();

        foreach ([
            array_diff_key($data, ['status' => 1]),
            ['billableKg' => '2'] + $data,
            ['options' => [['courier' => 'JNE REG']]] + $data,
            ['options' => ['JNE REG']] + $data,
            ['items' => [['product_id' => 'x', 'name' => 'Tas', 'variant' => null, 'quantity' => 1]]] + $data,
        ] as $bad) {
            try {
                $this->in(fn () => ShippingQuote::fromData($bad));
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_second_buyer_with_the_same_address_gets_the_price_from_the_cache(): void
    {
        $keys = $this->writtenKeys();

        $first = $this->summary();
        $this->assertSame(1, $this->priced);
        $this->assertSame(['ok', 'harga', 'jumlah', 'subtotal', 'kurir', 'ongkir', 'ongkir_normal', 'promo', 'total'], array_keys(array_filter($first, fn ($k) => ! str_ends_with($k, '_teks'), ARRAY_FILTER_USE_KEY)));
        $this->assertCount(1, $keys->all);
        $this->assertStringStartsWith('form-quote.v2.', $keys->all[0]);
        $this->assertSame('form-quote.v2.', FormQuote::KEY_PREFIX);
        $this->assertIsArray(Cache::get($keys->all[0]), 'plain data, no object');

        $second = $this->summary();
        $this->assertSame(1, $this->priced, 'priced once: the second answer is the cached one');
        $this->assertArrayNotHasKey('pesan', $second);
        $this->assertSame($first, $second);
        $this->assertSame([$first['kurir'], $first['ongkir'], 398000 + $first['ongkir']], [$second['kurir'], $second['ongkir'], $second['total']]);

        // Another quantity is another entry.
        $this->summary(jumlah: 3);
        $this->assertSame(2, $this->priced);
    }

    public function test_an_old_object_entry_or_bad_data_is_priced_again_not_sent_to_whatsapp(): void
    {
        $keys = $this->writtenKeys();
        $first = $this->summary();
        $key = $keys->all[0];

        // What production holds under the old key: the object itself, read back as __PHP_Incomplete_Class.
        $object = $this->in(fn () => Shipping::quote($this->shop, 'Kota Makassar', 'Sulawesi Selatan', [['product_id' => $this->bag->id, 'jumlah' => 2]]));
        $this->priced = 1;
        Cache::put($key, $object, 600);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get($key), 'the bug: the cache gives back no ShippingQuote');

        foreach ([$object, ['status' => 'ok'], 'rusak'] as $n => $stale) {
            Cache::put($key, $stale, 600);

            $again = $this->summary();
            $this->assertSame(2 + $n, $this->priced, 'priced again');
            $this->assertArrayNotHasKey('pesan', $again);
            $this->assertSame($first, $again);
            $this->assertIsArray(Cache::get($key), 'the entry is valid data again');
        }
    }

    private function assertRoundTrips(ShippingQuote $quote): void
    {
        $back = $this->in(fn () => ShippingQuote::fromData($quote->toData()));

        $this->assertEquals($quote, $back);
        $this->assertSame($quote->toData(), $back->toData());

        // Through the cache as production keeps it.
        Cache::put('round-trip', $quote->toData(), 60);
        $this->assertEquals($quote, $this->in(fn () => ShippingQuote::fromData(Cache::get('round-trip'))));
    }

    /** @return list<string> */
    private function constructorFields(string $class): array
    {
        return array_map(fn (\ReflectionParameter $p) => $p->getName(), (new ReflectionClass($class))->getConstructor()->getParameters());
    }

    private function writtenKeys(): object
    {
        $keys = new class
        {
            public array $all = [];
        };
        Event::listen(KeyWritten::class, function (KeyWritten $e) use ($keys) {
            if (str_starts_with($e->key, 'form-quote')) {
                $keys->all[] = $e->key;
            }
        });

        return $keys;
    }

    private function summary(int $jumlah = 2): array
    {
        return $this->getJson('/f/quicker-sport/pendek/ringkasan?'.http_build_query([
            'alamat' => ['kota' => '73.71', 'kecamatan' => '73.71.01', 'kelurahan' => '73.71.01.1001'], 'jumlah' => $jumlah,
        ]))->assertOk()->json();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function form(): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Tas Badminton Premium (Form Pendek)', 'slug' => 'pendek', 'status' => 'active', 'design' => ['title' => 'Order'],
            'property_id' => $this->bag->id,
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'jumlah', 'type' => 'number', 'label' => 'Jumlah', 'required' => true, 'maps_to' => 'quantity'],
                ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address', 'mode' => 'region',
                    'parts' => ['street', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'], 'required_parts' => ['street', 'kota', 'kecamatan', 'kelurahan'],
                    'address_options' => ['hide_postal', 'city_first']],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
    }
}
