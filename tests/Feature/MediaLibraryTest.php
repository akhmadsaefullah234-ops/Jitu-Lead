<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\SendWhatsAppMessage;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\MediaAssets\Pages\ManageMediaAssets;
use App\Models\Lead;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use App\WhatsApp\WhatsAppException;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The media library, and sending pictures and files (or saved links) to a customer. */
class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->makeTenant();
        config(['whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function channel(WaChannelType $type): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create($type === WaChannelType::Gateway ? [
            'type' => $type, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ] : [
            'type' => $type, 'name' => 'Resmi', 'phone' => '+6281100000001', 'status' => WaChannelStatus::Connected, 'position' => 0,
            'credentials' => ['phone_number_id' => '111', 'access_token' => 'tok', 'app_secret' => 'sekret', 'verify_token' => 'vt'],
        ]));
    }

    /** A customer who wrote just now, so the chat window is open. */
    private function conversation(WaChannel $channel): WaConversation
    {
        $this->inTenant(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            externalId: 'in1', from: '+6281299990000', type: 'text', body: 'Info rumah', media: null, sentAt: CarbonImmutable::now(), contactName: 'Budi',
        )));

        return $this->inTenant(fn () => WaConversation::query()->firstOrFail());
    }

    private function asset(string $title, string $folder = 'Price list', string $kind = 'file', string $name = 'harga.pdf', string $content = self::PDF): MediaAsset
    {
        return $this->inTenant(function () use ($title, $folder, $kind, $name, $content) {
            $path = null;
            if ($kind === 'file') {
                $path = 'media-library/'.$this->tenant->getKey().'/'.md5($title).'.'.pathinfo($name, PATHINFO_EXTENSION);
                Storage::disk('local')->put($path, $content);
            }

            return MediaAsset::create([
                'folder_id' => MediaFolder::query()->where('name', $folder)->value('id'), 'title' => $title, 'kind' => $kind,
                'path' => $path, 'original_name' => $kind === 'file' ? $name : null, 'url' => $kind === 'link' ? 'https://maps.app.goo.gl/abc' : null,
                'size_bytes' => strlen($content), 'active' => true,
            ]);
        });
    }

    public function test_every_agency_starts_with_the_standard_folders(): void
    {
        $names = $this->inTenant(fn () => MediaFolder::query()->orderBy('position')->pluck('name')->all());

        $this->assertSame(['Price list', 'Foto rumah', 'Denah', 'Link Google Maps', 'Brosur', 'Lainnya'], $names);
    }

    public function test_an_admin_adds_a_link_and_a_file_to_the_library(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $folder = $this->inTenant(fn () => MediaFolder::query()->where('name', 'Link Google Maps')->value('id'));

        Livewire::test(ManageMediaAssets::class)
            ->callAction(CreateAction::class, ['folder_id' => $folder, 'title' => 'Lokasi kantor', 'kind' => 'link', 'url' => 'https://maps.app.goo.gl/xyz', 'active' => true])
            ->assertHasNoActionErrors();

        $this->assertSame('https://maps.app.goo.gl/xyz', $this->inTenant(fn () => MediaAsset::query()->where('title', 'Lokasi kantor')->value('url')));

        Livewire::test(ManageMediaAssets::class)
            ->callAction(CreateAction::class, ['folder_id' => $folder, 'title' => 'Aneh', 'kind' => 'link', 'url' => 'javascript:alert(1)', 'active' => true])
            ->assertHasActionErrors(['url']);

        Livewire::test(ManageMediaAssets::class)
            ->callAction(CreateAction::class, ['folder_id' => $folder, 'title' => 'Price list Mawar', 'kind' => 'file', 'path' => UploadedFile::fake()->createWithContent('harga.pdf', self::PDF), 'active' => true])
            ->assertHasNoActionErrors();

        $asset = $this->inTenant(fn () => MediaAsset::query()->where('title', 'Price list Mawar')->firstOrFail());
        $this->assertSame('harga.pdf', $asset->original_name);
        $this->assertSame(strlen(self::PDF), $asset->size_bytes);
        Storage::disk('local')->assertExists($asset->path);

        $this->inTenant(fn () => $asset->delete());
        Storage::disk('local')->assertMissing($asset->path);
    }

    public function test_a_program_disguised_as_a_picture_is_refused(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $folder = $this->inTenant(fn () => MediaFolder::query()->value('id'));

        Livewire::test(ManageMediaAssets::class)
            ->callAction(CreateAction::class, ['folder_id' => $folder, 'title' => 'Jahat', 'kind' => 'file', 'path' => UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo 1;'), 'active' => true])
            ->assertHasActionErrors(['path']);

        $this->assertSame(0, $this->inTenant(fn () => MediaAsset::query()->count()));
    }

    public function test_a_file_goes_to_the_customer_through_the_gateway_and_stays_in_the_chat(): void
    {
        Http::fake(['gw.example.com/*' => Http::response(['id' => 'wamid.9'])]);
        $conversation = $this->conversation($this->channel(WaChannelType::Gateway));
        $asset = $this->asset('Price list Mawar');

        $message = $this->inTenant(fn () => app(SendWhatsAppMessage::class)->sendFile($conversation, $this->adminOf($this->tenant), $asset->path, $asset->original_name, 'Ini price list-nya'));

        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('document', $message->type);
        $this->assertSame('Ini price list-nya', $message->body);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'document' && $r['text'] === 'Ini price list-nya'
            && $r['media']['filename'] === 'harga.pdf' && $r['media']['mime_type'] === 'application/pdf' && base64_decode($r['media']['data']) === self::PDF);

        // The chat has its own copy, so deleting the library item does not break it.
        $this->inTenant(fn () => $asset->delete());
        Storage::disk('local')->assertExists($message->media['path']);
        $this->assertNotSame($asset->path, $message->media['path']);
    }

    public function test_the_official_api_gets_the_file_first_and_then_a_message_with_its_id(): void
    {
        Http::fake([
            'graph.facebook.com/*/111/media' => Http::response(['id' => 'MID1']),
            'graph.facebook.com/*/111/messages' => Http::response(['messages' => [['id' => 'wamid.5']]]),
        ]);
        $conversation = $this->conversation($this->channel(WaChannelType::Official));
        $asset = $this->asset('Foto fasad', 'Foto rumah', 'file', 'fasad.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $message = $this->inTenant(fn () => app(SendWhatsAppMessage::class)->sendFile($conversation, $this->adminOf($this->tenant), $asset->path, $asset->original_name));

        $this->assertSame('image', $message->type);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/111/media') && $r->isMultipart());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/111/messages') && $r['type'] === 'image' && $r['image']['id'] === 'MID1');
    }

    public function test_a_file_cannot_open_a_conversation_that_needs_a_paid_template(): void
    {
        Http::fake();
        $channel = $this->channel(WaChannelType::Official);
        $conversation = $this->inTenant(fn () => WaConversation::create(['lead_id' => Lead::create(['name' => 'Baru', 'phone' => '+6281377770000', 'stage_id' => Stage::query()->value('id')])->getKey(), 'phone' => '+6281377770000']));
        $asset = $this->asset('Price list');

        $this->expectException(WhatsAppException::class);
        $this->inTenant(fn () => app(SendWhatsAppMessage::class)->sendFile($conversation, $this->adminOf($this->tenant), $asset->path, $asset->original_name));
    }

    public function test_a_file_type_that_is_not_allowed_is_never_sent(): void
    {
        Http::fake(['gw.example.com/*' => Http::response(['id' => 'x'])]);
        $conversation = $this->conversation($this->channel(WaChannelType::Gateway));
        Storage::disk('local')->put('wa-outbox/x.exe', 'MZ binary');

        try {
            $this->inTenant(fn () => app(SendWhatsAppMessage::class)->sendFile($conversation, $this->adminOf($this->tenant), 'wa-outbox/x.exe', 'x.exe'));
            $this->fail('A program was sent');
        } catch (WhatsAppException $e) {
            $this->assertStringContainsString('tidak didukung', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_inbox_sends_a_saved_link_as_text_and_a_saved_file_as_a_document(): void
    {
        Http::fake(['gw.example.com/*' => Http::sequence()->push(['id' => 'wamid.7'])->push(['id' => 'wamid.8'])]);
        $conversation = $this->conversation($this->channel(WaChannelType::Gateway));
        $link = $this->asset('Lokasi kantor', 'Link Google Maps', 'link');
        $file = $this->asset('Price list Mawar');
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(Inbox::class, ['leadId' => $conversation->lead_id])
            ->callAction('sendAttachment', ['asset_id' => $link->getKey(), 'caption' => 'Silakan datang'])
            ->assertHasNoActionErrors()
            ->callAction('sendAttachment', ['asset_id' => $file->getKey()])
            ->assertHasNoActionErrors();

        Http::assertSent(fn ($r) => ($r['type'] ?? null) === 'text' && str_contains($r['text'], "Silakan datang\n\nLokasi kantor\nhttps://maps.app.goo.gl/abc"));
        Http::assertSent(fn ($r) => ($r['type'] ?? null) === 'document' && $r['text'] === 'Price list Mawar');
        $this->assertSame(['text', 'document'], $this->inTenant(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('type')->all()));
    }

    public function test_a_sent_file_can_be_downloaded_by_the_agency_only(): void
    {
        Http::fake(['gw.example.com/*' => Http::response(['id' => 'wamid.8'])]);
        $conversation = $this->conversation($this->channel(WaChannelType::Gateway));
        $asset = $this->asset('Price list Mawar');
        $message = $this->inTenant(fn () => app(SendWhatsAppMessage::class)->sendFile($conversation, $this->adminOf($this->tenant), $asset->path, $asset->original_name));

        $this->actingAs($this->adminOf($this->tenant))->get(route('wa.media', $message->getKey()))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="harga.pdf"');

        $this->actingAs($this->member($this->makeTenant('lain'), Role::Admin))->get(route('wa.media', $message->getKey()))->assertNotFound();
    }

    public function test_agents_can_use_the_library_but_not_change_it(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $asset = $this->asset('Price list');
        $this->actingInTenant($agent, $this->tenant);

        $this->assertTrue($agent->can('viewAny', MediaAsset::class));
        $this->assertTrue($agent->can('view', $asset));
        $this->assertFalse($agent->can('create', MediaAsset::class));
        $this->assertFalse($agent->can('delete', $asset));
    }
}
