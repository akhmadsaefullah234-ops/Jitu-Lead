<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Jobs\MatchProviderLocation;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\ProviderLocationCorrection;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\Carriers\CallbackEvent;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierOrder;
use App\Shipping\Carriers\CarrierProvider;
use App\Shipping\Carriers\EverproCarrier;
use App\Shipping\Carriers\OrderRequest;
use App\Shipping\Carriers\RateRequest;
use App\Shipping\Carriers\TrackResult;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\PackingState;
use App\Shipping\Shipments\ProviderLocations;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Our address (Kemendagri regions, postal code per kelurahan) matched once to the provider's own place:
 * pasti / perlu dipilih / tidak ketemu, an agent's choice kept per kelurahan and reused.
 */
class ProviderLocationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    /** postal code (or "area:<text>") => places */
    public static array $places = [];

    public static int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$places = [];
        self::$lookups = 0;
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private static function place(string $id, string $village, string $district, string $postal, string $city = 'Kota Bandung'): CarrierLocation
    {
        return new CarrierLocation($id, $village, $district, $city, 'Jawa Barat', $postal);
    }

    private function provider(): CarrierProvider
    {
        return new class implements CarrierProvider
        {
            public function key(): string
            {
                return 'everpro';
            }

            public function locations(?string $postalCode, ?string $area = null): array
            {
                ProviderLocationsTest::$lookups++;

                return $postalCode !== null ? (ProviderLocationsTest::$places[$postalCode] ?? []) : (ProviderLocationsTest::$places['area:'.$area] ?? []);
            }

            public function balance(): ?int
            {
                return null;
            }

            public function rates(RateRequest $request): array
            {
                return [];
            }

            public function pickupTimes(string $rateCode): array
            {
                return [];
            }

            public function createOrder(OrderRequest $order): CarrierOrder
            {
                throw new \LogicException;
            }

            public function generateAwb(string $clientOrderNo): ?string
            {
                return null;
            }

            public function awb(string $clientOrderNo): CarrierOrder
            {
                throw new \LogicException;
            }

            public function label(string $awb): string
            {
                return '';
            }

            public function track(string $awb): TrackResult
            {
                throw new \LogicException;
            }

            public function trackMany(array $awbs): array
            {
                return [];
            }

            public function cancel(string $clientOrderNo, string $reason): void {}

            public function parseCallback(Request $request): ?CallbackEvent
            {
                return null;
            }

            public function verifyCallback(Request $request): bool
            {
                return false;
            }
        };
    }

    private function lead(array $parts, string $name = 'Rina'): Lead
    {
        return $this->inShop(fn () => Lead::create([
            'name' => $name, 'phone' => '0812'.random_int(10000000, 99999999), 'owner_id' => $this->adminOf($this->shop)->getKey(),
            'stage_id' => (new RetailStages)->stage(RetailStages::SHIPPING)->getKey(), 'payment_type' => 'cod',
            'shipping_address_parts' => $parts + ['street' => 'Jl. Mawar', 'rt_rw' => '001/002', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat'],
        ]));
    }

    private function match(Lead $lead): ?string
    {
        return $this->inShop(fn () => app(ProviderLocations::class)->match($lead, provider: $this->provider()));
    }

    public function test_a_postal_code_with_one_matching_kelurahan_is_certain(): void
    {
        self::$places['40134'] = [self::place('SD-SEKELOA', 'Sekeloa', 'Coblong', '40134'), self::place('SD-LEBAK', 'Lebak Gede', 'Coblong', '40134')];
        $lead = $this->lead(['kelurahan' => 'Kel. Sekeloa', 'kecamatan' => 'Coblong', 'kode_pos' => '40134']);

        $this->assertSame(ProviderLocations::EXACT, $this->match($lead));

        $fresh = $this->inShop(fn () => $lead->fresh());
        $this->assertSame('SD-SEKELOA', $fresh->provider_location_id);
        $this->assertSame('Sekeloa, Coblong, Kota Bandung, Jawa Barat (40134)', $fresh->provider_location_label);
        $this->assertSame('Lokasi Everpro: pasti', $this->inShop(fn () => ProviderLocations::chip($fresh)));

        // Stored: matching again (same address) asks nothing, and Buat resi uses it without a new search.
        $this->match($fresh);
        $this->assertSame(1, self::$lookups);
        [$place] = $this->inShop(fn () => app(Shipments::class)->destination($this->provider(), $fresh));
        $this->assertSame('SD-SEKELOA', $place->id);
        $this->assertSame(1, self::$lookups);
    }

    public function test_several_equally_good_places_are_never_picked_silently(): void
    {
        self::$places['40134'] = [self::place('SD-A', 'Sekeloa', 'Coblong', '40134'), self::place('SD-B', 'Sekeloa', 'Coblong', '40134')];
        $lead = $this->lead(['kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kode_pos' => '40134']);

        $this->assertSame(ProviderLocations::MULTIPLE, $this->match($lead));

        $fresh = $this->inShop(fn () => $lead->fresh());
        $this->assertNull($fresh->provider_location_id);
        $this->assertCount(2, ProviderLocations::candidates($fresh));
        $this->assertStringContainsString('2 lokasi sama cocoknya', $fresh->provider_location_note);
        // Resi otomatis waits for the agent; the chip says why.
        $this->assertContains('kelurahan tujuan perlu dipilih', $this->inShop(fn () => app(AutoShipments::class)->problems($fresh)));
        $this->assertSame('kelurahan tujuan perlu dipilih', $this->inShop(fn () => PackingState::of($fresh, null)->reason));
    }

    public function test_a_different_spelling_is_matched_and_noted(): void
    {
        self::$places['17540'] = [self::place('SD-KDW', 'Kedungwaringin', 'Kedungwaringin', '17540', 'Kab. Bekasi'), self::place('SD-KDJ', 'Karangsambung', 'Kedungwaringin', '17540', 'Kab. Bekasi')];
        self::$places['40191'] = [self::place('SD-CBY', 'Cibeuying Kaler', 'Cimenyan', '40191')];

        $spaces = $this->lead(['kelurahan' => 'Kedung Waringin', 'kecamatan' => 'Kedung Waringin', 'kode_pos' => '17540', 'kota' => 'Kabupaten Bekasi']);
        $typo = $this->lead(['kelurahan' => 'Cibeunying Kaler', 'kecamatan' => 'Cimenyan', 'kode_pos' => '40191']);

        $this->assertSame(ProviderLocations::EXACT, $this->match($spaces));
        $this->assertSame('SD-KDW', $this->inShop(fn () => $spaces->fresh()->provider_location_id));
        $this->assertSame(ProviderLocations::EXACT, $this->match($typo));
        $this->assertStringContainsString('ejaan berbeda: "Cibeunying Kaler" = "Cibeuying Kaler"', $this->inShop(fn () => $typo->fresh()->provider_location_note));
    }

    public function test_without_a_postal_code_the_names_are_used_and_our_postal_code_difference_is_noted(): void
    {
        self::$places['area:Sekeloa, Coblong, Kota Bandung'] = [self::place('SD-SEKELOA', 'Sekeloa', 'Coblong', '40134')];
        $noPostal = $this->lead(['kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong']);
        $this->assertSame(ProviderLocations::EXACT, $this->match($noPostal));

        // Our postal code is unknown to the provider: found by name, the difference written down.
        $other = $this->lead(['kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kode_pos' => '40132']);
        $this->assertSame(ProviderLocations::EXACT, $this->match($other));
        $this->assertStringContainsString('kode pos kita 40132, Everpro 40134', $this->inShop(fn () => $other->fresh()->provider_location_note));

        // Nothing to go on, or nothing found.
        $nothing = $this->lead(['kelurahan' => 'Antah', 'kecamatan' => 'Berantah', 'kode_pos' => '99999']);
        $this->assertSame(ProviderLocations::NONE, $this->match($nothing));
        $this->assertSame('Lokasi Everpro: tidak ketemu', $this->inShop(fn () => ProviderLocations::chip($nothing->fresh())));
    }

    public function test_an_agents_choice_is_reused_for_the_same_kelurahan(): void
    {
        self::$places['40134'] = [self::place('SD-A', 'Sekeloa', 'Coblong', '40134'), self::place('SD-B', 'Sekeloa', 'Coblong', '40134')];
        $first = $this->lead(['kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kode_pos' => '40134']);
        $this->match($first);

        $this->inShop(fn () => app(ProviderLocations::class)->choose($first->fresh(), self::place('SD-B', 'Sekeloa', 'Coblong', '40134'), $this->adminOf($this->shop)));
        $this->assertSame([ProviderLocations::EXACT, 'SD-B'], $this->inShop(fn () => [$first->fresh()->provider_location_state, $first->fresh()->provider_location_id]));

        $lookups = self::$lookups;
        $second = $this->lead(['kelurahan' => 'sekeloa', 'kecamatan' => 'COBLONG', 'kode_pos' => '40134'], 'Budi');
        $this->assertSame(ProviderLocations::EXACT, $this->match($second));

        $fresh = $this->inShop(fn () => $second->fresh());
        $this->assertSame('SD-B', $fresh->provider_location_id);
        $this->assertSame('Pilihan agen sebelumnya untuk kelurahan ini.', $fresh->provider_location_note);
        $this->assertSame($lookups, self::$lookups, 'Not asked again.');
        $this->assertSame(1, $this->inShop(fn () => ProviderLocationCorrection::query()->sole()->used_count));
    }

    public function test_an_address_change_is_matched_on_the_queue_and_the_report_counts_it(): void
    {
        $this->inShop(fn () => CarrierAccount::query()->create(['provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'k', 'client_secret' => 's'], 'settings' => []]));
        $this->app->bind(EverproCarrier::class, fn () => $this->provider());
        self::$places['40134'] = [self::place('SD-A', 'Sekeloa', 'Coblong', '40134'), self::place('SD-B', 'Sekeloa', 'Coblong', '40134')];
        Queue::fake();

        $lead = $this->lead(['kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kode_pos' => '40134']);

        Queue::assertPushed(MatchProviderLocation::class, fn ($job) => $job->leadId === $lead->id);
        $this->match($lead);

        $this->artisan('shipping:locations --tenant=quicker-sport')
            ->expectsOutputToContain('Kelurahan yang sering tidak cocok')
            ->expectsOutputToContain('Koreksi agen tersimpan: 0')
            ->assertSuccessful();
    }
}
