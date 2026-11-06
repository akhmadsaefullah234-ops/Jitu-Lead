<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Leads\AddressCheck;
use App\Leads\ChatOrderParser;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Order details read from the chat onto the lead (App\Leads\ChatShippingDetails), with the AI on or off: format
 * orders, address pieces sent later, a new address replacing the old one, the read when a lead goes to Dikemas &
 * dikirim, and the leads:extract-shipping backfill.
 */
class ChatShippingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '6281234567890';

    private Tenant $shop;

    private Property $bag;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->bag = $this->inShop(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 150000, 'weight_grams' => 1000]));
        $this->assertSame(0, Artisan::call('regions:import', [
            '--wilayah' => base_path('tests/fixtures/regions/wilayah.sql'),
            '--kodepos' => base_path('tests/fixtures/regions/wilayah_kodepos.sql'),
        ]));
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake(['gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n])]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function channel(): WaChannel
    {
        return $this->inShop(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(string $text): WaMessage
    {
        $channel = $this->channel();

        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize(self::PHONE), 'text', $text, null, CarbonImmutable::now(), 'Rina',
        )));
    }

    /** A message the CS or the AI sent. */
    private function outbound(string $text): void
    {
        $this->inShop(fn () => WaConversation::query()->where('lead_id', $this->lead()->id)->firstOrFail()->messages()->create([
            'channel_id' => $this->channel()->id, 'direction' => 'out', 'type' => 'text', 'body' => $text, 'status' => 'sent', 'sent_at' => now(),
        ]));
    }

    private function lead(): Lead
    {
        return $this->inShop(fn () => Lead::query()->where('phone', PhoneNumber::normalize(self::PHONE))->firstOrFail());
    }

    private function update(array $attributes): void
    {
        $this->inShop(fn () => $this->lead()->forceFill($attributes)->save());
    }

    private function activities(): array
    {
        return $this->inShop(fn () => LeadActivity::query()->where('lead_id', $this->lead()->id)->where('type', 'note')->orderBy('id')->pluck('body')->all());
    }

    public function test_a_ctwa_format_order_fills_the_lead_without_the_ai(): void
    {
        $this->inbound('Halo kak, mau order');
        $this->outbound('Silakan isi format order ya kak: Nama, Alamat, Warna, Jumlah, Pembayaran');
        $this->inbound("Nama : Budi Santoso\nNo HP : 081234567890\nProduk : Tas Badminton\nAlamat : Jl. Melati No 3 RT 02 RW 05, Kel. Karadenan, Kec. Cibinong, Kab. Bogor\nWarna : Putih Merah\nJumlah : 2\nPembayaran : COD");

        $lead = $this->lead();
        $this->assertSame('Budi Santoso', $lead->name);
        $this->assertSame('Jl. Melati No 3 RT 02 RW 05, Kel. Karadenan, Kec. Cibinong, Kab. Bogor', $lead->shipping_address);
        $this->assertEquals([
            'rt_rw' => '002/005', 'house' => '3', 'kelurahan' => 'Karadenan', 'kecamatan' => 'Cibinong', 'kota' => 'Kabupaten Bogor', 'kode_pos' => '16913', 'street' => 'Jl. Melati', 'provinsi' => 'Jawa Barat',
        ], $lead->shipping_address_parts, 'Split, placed with the regions, the postal code found from the kelurahan.');
        $this->assertSame('Jawa Barat', $lead->shipping_province);
        $this->assertSame(['Putih Merah', 2, 'cod', $this->bag->id], [$lead->variant, $lead->quantity, $lead->payment_type, $lead->property_id]);
        $this->assertTrue(ChatShippingDetails::complete($lead));

        $note = collect($this->activities())->last();
        $this->assertStringStartsWith(ChatShippingDetails::ACTIVITY.': ', $note);
        $this->assertStringContainsString('RT/RW', $note);
    }

    public function test_rt_rw_and_a_landmark_sent_later_complete_the_form_address(): void
    {
        $this->inbound('Halo kak');
        $this->update([
            'shipping_address' => 'Jl. Raya Karadenan, Kel. Karadenan, Kec. Cibinong, Kab. Bogor',
            'shipping_address_parts' => ['street' => 'Jl. Raya Karadenan', 'kelurahan' => 'Karadenan', 'kecamatan' => 'Cibinong', 'kota' => 'Kabupaten Bogor', 'provinsi' => 'Jawa Barat'],
        ]);

        $this->outbound('Boleh dilengkapi RT/RW dan patokan rumahnya kak?');
        $this->inbound('Rt1 rw 2');
        $this->inbound('Tk anyar jaya');
        $this->inbound('ok kak');

        $lead = $this->lead();
        $this->assertSame('Jl. Raya Karadenan, RT/RW 001/002, (Tk anyar jaya), Kel. Karadenan, Kec. Cibinong, Kab. Bogor', $lead->shipping_address, 'Completed, not overwritten with a piece.');
        $this->assertSame('001/002', $lead->shipping_address_parts['rt_rw']);
        $this->assertSame('Tk anyar jaya', $lead->shipping_address_parts['landmark']);
        $this->assertSame('Karadenan', $lead->shipping_address_parts['kelurahan']);
        $this->assertSame('16913', $lead->shipping_address_parts['kode_pos']);
        $this->assertCount(2, array_filter($this->activities(), fn ($b) => str_starts_with($b, ChatShippingDetails::ACTIVITY)), '"ok kak" changes nothing.');
    }

    public function test_a_dusun_address_without_rt_rw_or_landmark_is_marked_incomplete_until_the_landmark_comes(): void
    {
        $this->inbound('Halo kak, mau order');
        $this->outbound('Silakan isi format order ya kak: Nama, Alamat, Warna, Jumlah, Pembayaran');
        $this->inbound("Nama : Siti Aminah\nAlamat : Dusun Krajan, Kel. Karadenan, Kec. Cibinong, Kab. Bogor\nWarna : Hitam\nJumlah : 1\nPembayaran : COD");

        $lead = $this->lead();
        $this->assertSame('Krajan', $lead->shipping_address_parts['dusun'] ?? null, json_encode($lead->shipping_address_parts));
        $this->assertArrayNotHasKey('rt_rw', $lead->shipping_address_parts);
        $this->assertFalse(ChatShippingDetails::complete($lead), 'dusun without RT/RW and without landmark: belum lengkap');
        $this->assertStringContainsString('Alamat belum lengkap: '.AddressCheck::MESSAGE, (string) collect($this->activities())->last());

        $this->outbound('Patokan rumahnya apa kak?');
        $this->inbound('Depan masjid Al Ikhlas');

        $lead = $this->lead();
        $this->assertSame('Depan masjid Al Ikhlas', $lead->shipping_address_parts['landmark'] ?? null);
        $this->assertTrue(ChatShippingDetails::complete($lead), 'dusun with a landmark: lengkap');
        $this->assertStringNotContainsString('belum lengkap', (string) collect($this->activities())->last());
    }

    public function test_an_address_with_rt_rw_and_no_landmark_is_complete(): void
    {
        $this->inbound('Halo kak, mau order');
        $this->inbound("Nama : Siti Aminah\nAlamat : Jl. Raya Karadenan RT 02 RW 05, Kel. Karadenan, Kec. Cibinong, Kab. Bogor\nWarna : Hitam\nJumlah : 1");

        $lead = $this->lead();
        $this->assertTrue(ChatShippingDetails::complete($lead));
        $this->assertStringNotContainsString('belum lengkap', implode(' ', $this->activities()));
    }

    public function test_a_different_address_replaces_the_old_one_which_stays_in_the_activity(): void
    {
        $this->inbound('Halo kak');
        $this->update(['shipping_address' => 'Jl. Kenanga 9, Kel. Karadenan, Kec. Cibinong, Kab. Bogor', 'shipping_address_parts' => ['kelurahan' => 'Karadenan', 'kecamatan' => 'Cibinong']]);

        $this->inbound('Alamat: Perum Griya Asri Blok C2 No 7, Kel. Pondok Rajeg, Kec. Cibinong, Kab. Bogor 16914');

        $lead = $this->lead();
        $this->assertSame('Perum Griya Asri Blok C2 No 7, Kel. Pondok Rajeg, Kec. Cibinong, Kab. Bogor 16914', $lead->shipping_address);
        $this->assertSame('Pondok Rajeg', $lead->shipping_address_parts['kelurahan']);
        $this->assertSame('16914', $lead->shipping_address_parts['kode_pos']);
        $activity = $this->inShop(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('type', 'note')->latest('id')->first());
        $this->assertStringContainsString('Alamat sebelumnya: Jl. Kenanga 9, Kel. Karadenan', $activity->body);
        $this->assertSame('Jl. Kenanga 9, Kel. Karadenan, Kec. Cibinong, Kab. Bogor', $activity->meta['previous_address']);
    }

    private const FORM_ADDRESS = 'Jl. Beliang XI B, RT/RW 001/022, (Depan Jl. Bondang), Kel. Palangka, Kec. Jekan Raya, Kota Palangka Raya, Kalimantan Tengah 74874';

    private function formLead(string $address = self::FORM_ADDRESS, array $parts = []): void
    {
        $this->inbound('Halo kak');
        $this->update(['shipping_address' => $address, 'shipping_address_parts' => $parts + [
            'street' => 'Jl. Beliang XI B', 'rt_rw' => '001/022', 'landmark' => 'Depan Jl. Bondang', 'kelurahan' => 'Palangka', 'kecamatan' => 'Jekan Raya',
            'kota' => 'Kota Palangka Raya', 'provinsi' => 'Kalimantan Tengah', 'kode_pos' => '74874',
        ]]);
    }

    public function test_the_forms_own_opening_message_never_shortens_the_form_address(): void
    {
        $this->formLead();
        $before = $this->lead()->shipping_address_parts;

        $this->inbound("Halo admin, saya David mau order Tas Badminton:\nVarian: Putih Merah\nJumlah: 1\nPembayaran: COD\nAlamat : Jl. Beliang XI B, RT/RW 001/022, (Depan jl. Bondang), Kalimantan Tengah 74874\nCatatan: -\nMohon info total dan ongkirnya.");

        $this->assertSame(self::FORM_ADDRESS, $this->lead()->shipping_address);
        $this->assertSame($before, $this->lead()->shipping_address_parts);
        $this->assertSame([], array_filter($this->activities(), fn ($b) => str_contains($b, 'dari chat')));

        // Read again with the whole chat (the backfill, moving to Dikemas): still nothing lost.
        $result = $this->inShop(fn () => app(ChatShippingDetails::class)->fromConversation($this->lead(), save: false));
        $this->assertArrayNotHasKey('shipping_address', $result['changes']);
        $this->assertGreaterThanOrEqual($result['parts_before'], $result['parts_after']);
    }

    public function test_a_shorter_repeat_only_fills_what_is_empty_and_a_stated_new_address_replaces(): void
    {
        $this->formLead('Jl. Beliang XI B, Kel. Palangka, Kec. Jekan Raya, Kota Palangka Raya, Kalimantan Tengah 74874', ['rt_rw' => '', 'landmark' => '']);

        // Shorter, not said to be new: only the RT/RW it adds is kept.
        $this->inbound('Alamat: Jl. Beliang XI B RT 1 RW 22, Palangka Raya');
        $this->assertSame('Jl. Beliang XI B, RT/RW 001/022, Kel. Palangka, Kec. Jekan Raya, Kota Palangka Raya, Kalimantan Tengah 74874', $this->lead()->shipping_address);
        $this->assertSame('Palangka', $this->lead()->shipping_address_parts['kelurahan']);

        $this->inbound('Rt1 rw 2');
        $this->assertSame('001/002', $this->lead()->shipping_address_parts['rt_rw'], 'A piece sent later completes it.');

        // Said to be new: replaced even though shorter, the old one kept.
        $this->inbound('Kak alamatnya ganti ya: Jl. Tjilik Riwut Km 5 No 12, Kota Palangka Raya');
        $lead = $this->lead();
        $this->assertSame('Jl. Tjilik Riwut Km 5 No 12, Kota Palangka Raya', $lead->shipping_address);
        $this->assertArrayNotHasKey('kelurahan', $lead->shipping_address_parts, 'Parts of the old address do not stay with the new one.');
        $this->assertStringContainsString('Alamat sebelumnya: Jl. Beliang XI B, RT/RW 001/002', collect($this->activities())->last());
    }

    public function test_moving_to_dikemas_reads_the_whole_chat_once(): void
    {
        $this->inbound('Halo kak');
        // Messages from before this feature: in the chat, not on the lead.
        $this->inShop(fn () => WaConversation::query()->where('lead_id', $this->lead()->id)->firstOrFail()->messages()->create([
            'channel_id' => $this->channel()->id, 'direction' => 'in', 'type' => 'text', 'status' => 'delivered', 'sent_at' => now(),
            'body' => "nama: Rina\nalamat: Kp. Babakan RT 03/04, Desa Wanaherang, Kec. Gunungputri, Kab. Bogor\nwarna: hitam\njumlah: 1",
        ]));
        $this->assertNull($this->lead()->shipping_address);

        $this->inShop(fn () => app(MoveLeadToStage::class)($this->lead(), (new RetailStages)->stage(RetailStages::SHIPPING), User::query()->first()));

        $lead = $this->lead();
        $this->assertSame('Kp. Babakan RT 03/04, Desa Wanaherang, Kec. Gunungputri, Kab. Bogor', $lead->shipping_address);
        $this->assertSame(['Wanaherang', 'Gunungputri', '16965', 'Babakan'], [$lead->shipping_address_parts['kelurahan'], $lead->shipping_address_parts['kecamatan'], $lead->shipping_address_parts['kode_pos'], $lead->shipping_address_parts['dusun']]);
        $this->assertSame(['Rina', 'hitam', 1], [$lead->name, $lead->variant, $lead->quantity]);
    }

    public function test_an_address_typed_in_one_piece_is_split_into_its_parts(): void
    {
        $this->inbound('Halo kak');
        $this->update(['shipping_address' => 'Jl. Mawar 5 rt 2 rw 3, Pondok Rajeg, Kec. Cibinong, Kab. Bogor, Jawa Barat']);

        $this->inShop(fn () => app(ChatShippingDetails::class)->structure($this->lead()));

        $parts = $this->lead()->shipping_address_parts;
        $this->assertSame(['002/003', 'Cibinong', 'Kabupaten Bogor', 'Jawa Barat'], [$parts['rt_rw'], $parts['kecamatan'], $parts['kota'], $parts['provinsi']]);
        $this->assertSame('Jl. Mawar 5 rt 2 rw 3, Pondok Rajeg, Kec. Cibinong, Kab. Bogor, Jawa Barat', $this->lead()->shipping_address, 'The text itself stays.');
    }

    public function test_nothing_changes_once_the_parcel_has_a_tracking_number(): void
    {
        $this->inbound('Halo kak');
        $this->update(['tracking_number' => 'JNE0001234', 'shipping_address' => 'Jl. Kenanga 9, Kab. Bogor']);

        $this->inbound('Alamat: Perum Griya Asri Blok C2 No 7, Kel. Pondok Rajeg, Kec. Cibinong, Kab. Bogor 16914');

        $this->assertSame('Jl. Kenanga 9, Kab. Bogor', $this->lead()->shipping_address);
    }

    public function test_the_backfill_previews_by_default_and_saves_with_apply(): void
    {
        $this->inbound('Halo kak');
        $this->inbound("Nama: Budi\nAlamat: Jl. Melati 3 RT 2/5, Kel. Karadenan, Kec. Cibinong, Kab. Bogor");
        // As before this feature: the details are only in the chat.
        $this->update(['shipping_address' => null, 'shipping_address_parts' => null, 'name' => 'Rina', 'stage_id' => $this->inShop(fn () => (new RetailStages)->stage(RetailStages::ORDER)->id)]);

        $this->artisan('leads:extract-shipping', ['--tenant' => 'quicker-sport'])
            ->expectsOutputToContain('Pratinjau: belum ada yang disimpan')
            ->doesntExpectOutputToContain('Melati')
            ->assertSuccessful();
        $this->assertNull($this->lead()->shipping_address, 'A dry run saves nothing.');

        $this->artisan('leads:extract-shipping', ['--tenant' => (string) $this->shop->id, '--stage' => ['order'], '--apply' => true])
            ->expectsOutputToContain('Perubahan disimpan.')->assertSuccessful();
        $this->assertSame('Jl. Melati 3 RT 2/5, Kel. Karadenan, Kec. Cibinong, Kab. Bogor', $this->lead()->shipping_address);
        $this->assertSame('Budi', $this->lead()->name);

        // One lead only, by id; the table shows how many address parts there are before and after.
        $this->update(['shipping_address' => null, 'shipping_address_parts' => null]);
        $this->artisan('leads:extract-shipping', ['--tenant' => 'quicker-sport', '--lead' => [(string) $this->lead()->id], '--apply' => true])
            ->expectsOutputToContain('0 → ')->expectsOutputToContain('1 lead diperiksa, 1 dengan data baru dari chat. Perubahan disimpan.')->assertSuccessful();
        $this->assertNotNull($this->lead()->shipping_address);
        $this->artisan('leads:extract-shipping', ['--tenant' => 'quicker-sport', '--lead' => ['999999']])
            ->expectsOutputToContain('0 lead diperiksa')->assertSuccessful();
    }

    public function test_the_parser_reads_bare_answers_only_in_context(): void
    {
        $parser = new ChatOrderParser;

        $this->assertSame([], $parser->parse('Tk anyar jaya'), 'Without being asked, a few words are not a landmark.');
        $this->assertSame([], $parser->parse('nanti saya kirim', 'patokannya apa kak?'));
        $this->assertSame([], $parser->parse('bisa COD?'));
        $this->assertSame(['payment' => 'cod'], $parser->parse('COD aja kak'));
        $this->assertSame(['payment' => 'transfer'], $parser->parse('transfer'));
        $this->assertSame(['parts' => ['kode_pos' => '16913']], $parser->parse('16913', 'kode posnya berapa kak'));
        $this->assertSame(['parts' => ['house' => '12']], $parser->parse('12', 'nomor rumahnya kak?'));
        $this->assertSame('3', $parser->parse("Nama: A\nAlamat: Jl. Kenari No.3, Kec. Cibinong\nNo HP: 081234567890")['parts']['house'], 'The phone is not a house number.');
    }
}
