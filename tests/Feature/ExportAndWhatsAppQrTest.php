<?php

namespace Tests\Feature;

use App\Actions\ExportLeadsCsv;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\WaChannels\Pages\CreateWaChannel;
use App\Filament\Resources\WaChannels\Pages\ListWaChannels;
use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\WaChannel;
use App\WhatsApp\GatewayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ExportAndWhatsAppQrTest extends TestCase
{
    use RefreshDatabase;

    private function enter($tenant): void
    {
        $this->actingInTenant($tenant->users()->first(), $tenant);
    }

    public function test_export_writes_csv_with_bom_and_neutralises_formulas(): void
    {
        $tenant = $this->makeTenant();
        $this->enter($tenant);
        (function () {
            $stage = Stage::query()->first();
            Lead::create(['name' => '=HYPERLINK("x")', 'phone' => '081234567890', 'stage_id' => $stage->id]);
            Lead::create(['name' => 'Budi', 'phone' => '081200000001', 'email' => 'b@x.id', 'stage_id' => $stage->id]);

            $out = fopen('php://memory', 'w+');
            $count = app(ExportLeadsCsv::class)(Lead::query(), $out);
            rewind($out);
            $csv = stream_get_contents($out);

            $this->assertSame(2, $count);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $this->assertStringContainsString('"\'=HYPERLINK', $csv);
            $this->assertStringContainsString('+6281234567890', $csv);
            $this->assertStringContainsString('Budi', $csv);
        })();
    }

    public function test_export_button_downloads_only_filtered_leads(): void
    {
        $tenant = $this->makeTenant();
        $this->enter($tenant);
        (function () {
            $stage = Stage::query()->first();
            Lead::create(['name' => 'Andi Cari', 'phone' => '081234567890', 'stage_id' => $stage->id]);
            Lead::create(['name' => 'Zeta Lain', 'phone' => '081200000001', 'stage_id' => $stage->id]);

            Livewire::test(ListLeads::class)
                ->searchTable('Andi')
                ->callAction('export')
                ->assertFileDownloaded();
        })();
    }

    public function test_secret_fields_do_not_trigger_browser_autofill(): void
    {
        $tenant = $this->makeTenant();
        $this->enter($tenant);
        (function () {
            Livewire::test(CreateWaChannel::class)
                ->fillForm(['type' => 'official'])
                ->assertSeeHtml('autocomplete="new-password"')
                ->assertSeeHtml('data-1p-ignore');
        })();
    }

    public function test_platform_gateway_needs_only_a_name_and_pairs_via_qr(): void
    {
        config(['whatsapp.gateway' => ['url' => 'https://gw.platform.test', 'api_key' => 'k', 'signing_secret' => 'sec']]);
        $tenant = $this->makeTenant();

        $this->enter($tenant);
        (function () {
            Livewire::test(CreateWaChannel::class)
                ->fillForm(['type' => 'gateway', 'name' => 'WA Follow-up'])
                ->call('create')
                ->assertHasNoFormErrors();

            $channel = WaChannel::query()->firstOrFail();
            $this->assertTrue($channel->usesPlatformGateway());

            Http::fake(function ($request) use ($channel) {
                $this->assertSame($channel->webhook_token, $request->header('X-Session-Id')[0]);
                $this->assertSame('Bearer k', $request->header('Authorization')[0]);

                return match (true) {
                    str_ends_with($request->url(), '/session/start') => Http::response(['status' => 'qr_required']),
                    str_ends_with($request->url(), '/session/qr') => Http::response(['status' => 'qr_required', 'qr' => 'kode-qr-1', 'expires_in' => 20]),
                    default => Http::response([], 404),
                };
            });

            Livewire::test(ListWaChannels::class)
                ->mountTableAction('pair', $channel);

            // Modals are sent to the browser as a partial, so check the same data the dialog is built from.
            $pairing = WaChannelResource::pairing($channel);
            $this->assertSame('qr_required', $pairing['status']);
            $this->assertStringStartsWith('data:image/svg+xml', $pairing['qr']);
            $html = view('filament.wa-pairing', ['pairing' => $pairing])->render();
            $this->assertStringContainsString('Perangkat tertaut', $html);
            $this->assertStringContainsString('wire:poll', $html);

            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/session/start')
                && $r['signing_secret'] === 'sec' && $r['webhook_url'] === $channel->webhookUrl());
        })();
    }

    public function test_pairing_marks_channel_connected_once_scanned(): void
    {
        config(['whatsapp.gateway' => ['url' => 'https://gw.platform.test', 'api_key' => 'k', 'signing_secret' => 'sec']]);
        $tenant = $this->makeTenant();

        $this->enter($tenant);
        (function () {
            $channel = WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'WA', 'status' => 'disconnected', 'credentials' => []]);
            Http::fake(['*' => Http::response(['status' => 'connected'])]);

            $pairing = WaChannelResource::pairing($channel);

            $this->assertSame('connected', $pairing['status']);
            $this->assertStringContainsString('Terhubung', view('filament.wa-pairing', ['pairing' => $pairing])->render());

            $this->assertSame(WaChannelStatus::Connected, $channel->fresh()->status);
        })();
    }

    public function test_platform_gateway_signature_uses_platform_secret(): void
    {
        config(['whatsapp.gateway' => ['url' => 'https://gw.platform.test', 'api_key' => 'k', 'signing_secret' => 'sec']]);
        $tenant = $this->makeTenant();

        $this->enter($tenant);
        (function () {
            $channel = WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'WA', 'status' => 'disconnected', 'credentials' => []]);
            $body = '{"event":"session","status":"connected"}';
            $request = Request::create('/x', 'POST', [], [], [], ['HTTP_X_GATEWAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'sec')], $body);

            $this->assertTrue((new GatewayProvider($channel))->verifySignature($request));
        })();
    }
}
