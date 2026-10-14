<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\CloudApiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Pictures customers send in WhatsApp must be kept and shown in the Inbox, to the right people only. */
class WhatsAppImageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->makeTenant();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function gateway(): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function official(): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Official, 'name' => 'Resmi', 'phone' => '+6281100000001',
            'status' => WaChannelStatus::Connected, 'position' => 0,
            'credentials' => ['phone_number_id' => '111', 'access_token' => 'tok', 'app_secret' => 'sekret', 'verify_token' => 'vt'],
        ]));
    }

    /** @param  array<string, mixed>  $media */
    private function gatewayImage(WaChannel $channel, string $id, array $media, ?string $caption = 'rumahnya'): void
    {
        $body = json_encode(['event' => 'message', 'id' => $id, 'from' => '081299990000', 'type' => 'image', 'text' => $caption, 'media' => $media, 'timestamp' => time()]);

        $this->call('POST', "/webhooks/whatsapp/gateway/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();
    }

    private function message(): WaMessage
    {
        return $this->inTenant(fn () => WaMessage::query()->latest('id')->firstOrFail());
    }

    public function test_a_gateway_picture_is_stored_as_a_file_not_in_the_database(): void
    {
        $this->gatewayImage($this->gateway(), 'g1', ['mime_type' => 'image/png', 'data' => self::PNG]);

        $message = $this->message();

        $this->assertSame('image', $message->type);
        $this->assertSame('rumahnya', $message->body);
        $this->assertSame('image/png', $message->media['mime_type']);
        $this->assertStringStartsWith("wa-media/{$this->tenant->getKey()}/", $message->media['path']);
        $this->assertArrayNotHasKey('data', $message->media);
        Storage::disk('local')->assertExists($message->media['path']);
        $this->assertStringNotContainsString(self::PNG, (string) DB::table('wa_messages')->value('media'));
    }

    public function test_something_that_is_not_a_picture_is_never_stored_whatever_it_claims(): void
    {
        $this->gatewayImage($this->gateway(), 'g2', ['mime_type' => 'image/png', 'data' => base64_encode('<?php echo 1;')]);

        $message = $this->message();

        $this->assertSame('unsupported', $message->media['error']);
        $this->assertArrayNotHasKey('path', $message->media);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_gateway_that_could_not_fetch_the_picture_still_delivers_the_message(): void
    {
        $this->gatewayImage($this->gateway(), 'g3', ['mime_type' => 'image/jpeg', 'error' => 'download_failed']);

        $this->assertSame('download_failed', $this->message()->media['error']);
        $this->assertSame(1, $this->inTenant(fn () => Lead::query()->count()));
    }

    public function test_only_people_who_may_open_the_lead_can_see_the_picture(): void
    {
        $this->gatewayImage($this->gateway(), 'g4', ['mime_type' => 'image/png', 'data' => self::PNG]);
        $message = $this->message();
        $lead = $this->inTenant(fn () => $message->conversation->lead);
        $url = route('wa.media', $message->getKey());

        $admin = $this->adminOf($this->tenant);
        $owner = $this->member($this->tenant, Role::Agent);
        $other = $this->member($this->tenant, Role::Agent);
        $this->inTenant(fn () => $lead->forceFill(['owner_id' => $owner->getKey()])->save());
        $stranger = $this->member($this->makeTenant('lain'), Role::Admin);

        $this->get($url)->assertNotFound();
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get($url)->assertNotFound();

        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($admin)->get($url)->assertOk();
    }

    public function test_the_inbox_shows_the_picture_and_its_caption(): void
    {
        $this->gatewayImage($this->gateway(), 'g5', ['mime_type' => 'image/png', 'data' => self::PNG]);
        $message = $this->message();
        $lead = $this->inTenant(fn () => $message->conversation->lead);

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(Inbox::class, ['leadId' => $lead->getKey()])
            ->assertSeeHtml('src="'.route('wa.media', $message->getKey()).'"')
            ->assertSee('rumahnya');
    }

    public function test_an_official_api_picture_is_downloaded_from_metas_servers(): void
    {
        Http::fake([
            'graph.facebook.com/*/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/png']),
            'https://lookaside.fbsbx.com/*' => Http::response(base64_decode(self::PNG)),
        ]);
        $channel = $this->official();
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111'],
            'contacts' => [['wa_id' => '6281299990000', 'profile' => ['name' => 'Budi']]],
            'messages' => [['from' => '6281299990000', 'id' => 'wamid.1', 'timestamp' => (string) time(), 'type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/png', 'caption' => 'lihat']]],
        ]]]]]]);

        $this->call('POST', "/webhooks/whatsapp/official/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'sekret'),
        ]), $body)->assertOk();

        $message = $this->message();

        Storage::disk('local')->assertExists($message->media['path']);
        $this->assertSame('lihat', $message->body);
    }

    public function test_the_official_downloader_refuses_addresses_outside_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['url' => 'https://evil.example.com/steal'])]);

        $this->assertNull((new CloudApiProvider($this->official()))->downloadMedia('MEDIA2'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example.com'));
    }
}
