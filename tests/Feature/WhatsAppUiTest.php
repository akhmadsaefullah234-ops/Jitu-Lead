<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\WaChannels\Pages\CreateWaChannel;
use App\Filament\Resources\WaChannels\Pages\EditWaChannel;
use App\Filament\Resources\WaChannels\Pages\ListWaChannels;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function gateway(): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    public function test_agent_only_sees_chats_of_own_leads_and_cannot_open_others(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $mine = $this->makeLead($this->tenant, ['name' => 'Milik Agen', 'owner_id' => $agent->getKey()]);
        $other = $this->makeLead($this->tenant, ['name' => 'Milik Admin']);
        $this->inTenant(function () use ($mine, $other) {
            WaConversation::create(['lead_id' => $mine->getKey(), 'phone' => $mine->phone]);
            WaConversation::create(['lead_id' => $other->getKey(), 'phone' => $other->phone]);
        });

        $this->actingInTenant($agent, $this->tenant);
        Livewire::test(Inbox::class)->assertSee('Milik Agen')->assertDontSee('Milik Admin');
        Livewire::test(Inbox::class, ['leadId' => $other->getKey()])->assertDontSee('Milik Admin')->assertSee('Pilih percakapan');
    }

    public function test_agent_sends_message_and_inbox_marks_read(): void
    {
        $this->gateway();
        $agent = $this->member($this->tenant, Role::Agent);
        $lead = $this->makeLead($this->tenant, ['owner_id' => $agent->getKey()]);
        $conversation = $this->inTenant(fn () => WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone, 'unread_count' => 3]));

        Http::fake(['gw.example.com/*' => Http::sequence()->push(['id' => 'x1'])->push(['id' => 'x2'])]);
        $this->actingInTenant($agent, $this->tenant);

        Livewire::withQueryParams(['lead' => $lead->getKey()])->test(Inbox::class)
            ->assertSee('Follow-up lewat nomor gateway')
            ->set('draft', 'Selamat pagi')
            ->call('send')
            ->assertSet('draft', '');

        $this->assertSame(0, $conversation->fresh()->unread_count);
        $this->assertSame(2, $conversation->messages()->count()); // intro + message
    }

    public function test_only_admin_reaches_channel_settings(): void
    {
        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        Livewire::test(ListWaChannels::class)->assertForbidden();

        $this->actingInTenant($this->member($this->tenant, Role::Manager), $this->tenant);
        Livewire::test(ListWaChannels::class)->assertForbidden();

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(ListWaChannels::class)->assertOk();
    }

    public function test_creating_gateway_stores_encrypted_credentials_and_rejects_internal_url(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(CreateWaChannel::class)
            ->fillForm(['type' => 'gateway', 'name' => 'GW', 'cred' => ['base_url' => 'https://127.0.0.1', 'api_key' => 'k', 'signing_secret' => 's']])
            ->call('create')->assertHasFormErrors(['cred.base_url']);

        Livewire::test(CreateWaChannel::class)
            ->fillForm(['type' => 'gateway', 'name' => 'GW', 'cred' => ['base_url' => 'https://gw.example.com', 'api_key' => 'rahasia', 'signing_secret' => 's']])
            ->call('create')->assertHasNoFormErrors();

        $channel = $this->inTenant(fn () => WaChannel::firstOrFail());
        $this->assertSame('rahasia', $channel->credential('api_key'));
        $this->assertSame($this->tenant->getKey(), $channel->tenant_id);
        $this->assertSame(WaChannelStatus::Disconnected, $channel->status);
    }

    public function test_editing_never_exposes_secrets_and_blank_keeps_them(): void
    {
        $channel = $this->gateway();
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        $component = Livewire::test(EditWaChannel::class, ['record' => $channel->getKey()]);
        $component->assertSet('data.cred.base_url', 'https://gw.example.com');
        $this->assertNull($component->get('data.cred.api_key'));
        $component->assertDontSee('"k"');

        $component->fillForm(['name' => 'Baru', 'cred' => ['api_key' => '']])->call('save')->assertHasNoFormErrors();

        $fresh = $this->inTenant(fn () => $channel->fresh());
        $this->assertSame('Baru', $fresh->name);
        $this->assertSame('k', $fresh->credential('api_key'));
    }

    public function test_other_tenant_channel_is_not_editable(): void
    {
        $channel = $this->gateway();
        $other = $this->makeTenant('nusa-properti');
        $this->actingInTenant($this->adminOf($other), $other);

        Livewire::test(EditWaChannel::class, ['record' => $channel->getKey()])->assertNotFound();
    }
}
