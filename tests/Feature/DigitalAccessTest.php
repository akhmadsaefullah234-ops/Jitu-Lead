<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Ai\DigitalTools;
use App\Automation\DigitalStages;
use App\Digital\DigitalAccesses;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Models\AiDraft;
use App\Models\DigitalAccess;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use App\WhatsApp\MessageContent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Secure digital download access (App\Digital\DigitalAccesses): the buyer's own page, limits, licence, resends and revoking. */
class DigitalAccessTest extends TestCase
{
    use RefreshDatabase;

    private const GAME = '<!doctype html><html><body><div id="app" data-lic="{{LISENSI}}"></div><p>{{LISENSI}} stays in text</p><i data-lic="{{LISENSI}}"></i></body></html>';

    private Tenant $digital;

    private Property $game;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('digital');
        $this->digital = app(ProvisionTenant::class)('Kelas Pintar', 'kelas-pintar', User::factory()->create(), 'digital');
        Storage::disk('digital')->put($this->digital->id.'/game.html', self::GAME);
        $this->game = $this->in(fn () => Property::create(['name' => 'Permainan Contoh', 'price_from' => 99000,
            'access_file_path' => $this->digital->id.'/game.html', 'access_file_name' => 'permainan-contoh.html']));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik, Kak.')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->digital, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function toolUse(string $name, array $input = []): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => $name, 'input' => (object) $input]]];
    }

    private function channel(AiMode $mode = AiMode::Off, ?Tenant $tenant = null): WaChannel
    {
        return $this->in(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+628990000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]), $tenant);
    }

    private function inbound(string $text, ?Tenant $tenant = null): WaMessage
    {
        $channel = $this->channel(tenant: $tenant);

        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), 'text', $text, null, CarbonImmutable::now(), 'Dina',
        )), $tenant);
    }

    private function digitalStage(string $key): Stage
    {
        return $this->in(fn () => (new DigitalStages)->stage($key));
    }

    /** A paid buyer of the game, with their access (sent through "Pembayaran diterima"). */
    private function paidLead(): Lead
    {
        $this->inbound('Halo kak');
        $lead = $this->in(function () {
            $lead = Lead::query()->firstOrFail();
            $lead->forceFill(['stage_id' => $this->digitalStage(DigitalStages::PAYMENT)->id, 'property_id' => $this->game->id, 'payment_proof_at' => now()])->save();

            return $lead;
        });
        $this->actingInTenant($this->adminOf($this->digital), $this->digital);
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('digitalPaymentReceived', ['access_link' => null, 'send' => true]);

        return $this->in(fn () => Lead::query()->findOrFail($lead->id));
    }

    private function access(Lead $lead): DigitalAccess
    {
        return $this->in(fn () => DigitalAccess::query()->where('lead_id', $lead->id)->latest('id')->firstOrFail());
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->pluck('body')->all());
    }

    /** A CS agent who owns the lead. */
    private function agent(Lead $lead): User
    {
        $agent = User::factory()->create();
        $this->digital->users()->attach($agent, ['role' => 'agent', 'status' => 'active']);
        $this->in(fn () => Lead::query()->whereKey($lead->id)->update(['owner_id' => $agent->id]));

        return $agent;
    }

    // ---- The buyer's page and download -------------------------------------------------------------------------

    public function test_payment_received_sends_the_buyers_own_page_with_the_default_limits(): void
    {
        $lead = $this->paidLead();
        $access = $this->access($lead);

        $this->assertSame(DigitalAccesses::DEFAULT_DOWNLOADS, $access->max_downloads);
        $this->assertTrue($access->expires_at->between(now()->addDays(89), now()->addDays(91)));
        $this->assertStringContainsString('/akses/'.$access->token, (string) collect($this->sent())->last());

        $this->get(DigitalAccesses::url($access))->assertOk()
            ->assertSee('Halo, Dina')->assertSee('Buka dengan Chrome')->assertSee('Unduh Permainan Contoh')->assertDontSee('game.html');
        $access->refresh();
        $this->assertNotNull($access->opened_at);
        $this->assertSame(0, $access->downloads, 'opening the page is not a download');

        // Nothing written for the buyer or the AI carries the token.
        $this->assertFalse($this->in(fn () => LeadActivity::query()->where('body', 'like', '%'.$access->token.'%')->exists()));
    }

    public function test_the_licence_is_put_in_once_at_download_and_the_file_never_changes(): void
    {
        $lead = $this->paidLead();
        $access = $this->access($lead);

        $response = $this->withHeader('User-Agent', 'Uji Chrome')->get(DigitalAccesses::downloadUrl($access))->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString('attachment; filename=permainan-contoh.html', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('data-lic="Lisensi Dina · Pesanan #'.$lead->id.'"', $body);
        $this->assertSame(2, substr_count($body, '{{LISENSI}}'), 'only the first data-lic marker is replaced');
        $this->assertSame(self::GAME, Storage::disk('digital')->get($this->digital->id.'/game.html'));

        $access->refresh();
        $this->assertSame(1, $access->downloads);
        $this->assertSame('Uji Chrome', $access->last_user_agent);
        $this->assertNotNull($access->last_ip);

        // The lead page shows the trail.
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertSee('unduhan 1/10')->assertSee('Uji Chrome');
    }

    public function test_a_name_with_quotes_cannot_break_out_of_the_attribute_and_no_name_uses_the_number(): void
    {
        $lead = $this->paidLead();
        $this->in(fn () => $lead->forceFill(['name' => 'Dina "><script>'])->save());
        $access = $this->access($lead);

        $body = $this->get(DigitalAccesses::downloadUrl($access))->streamedContent();
        $this->assertStringNotContainsString('"><script>', $body);
        $this->assertStringContainsString('Dina &quot;&gt;&lt;script&gt;', $body);

        $this->in(fn () => $lead->forceFill(['name' => ''])->save());
        $this->assertStringContainsString('Lisensi WA •••1234', DigitalAccesses::licence($access->fresh('lead')));
    }

    public function test_a_pdf_or_zip_is_served_as_it_is(): void
    {
        Storage::disk('digital')->put($this->digital->id.'/buku.pdf', '%PDF-1.4 {{LISENSI}} data-lic="{{LISENSI}}"');
        $this->in(fn () => $this->game->update(['access_file_path' => $this->digital->id.'/buku.pdf', 'access_file_name' => 'buku.pdf']));
        $access = $this->access($this->paidLead());

        $response = $this->get(DigitalAccesses::downloadUrl($access))->assertOk();
        $this->assertSame('%PDF-1.4 {{LISENSI}} data-lic="{{LISENSI}}"', $response->streamedContent());
    }

    public function test_the_download_limit_stops_the_link(): void
    {
        $this->in(fn () => $this->game->update(['access_max_downloads' => 2]));
        $access = $this->access($this->paidLead());

        $this->get(DigitalAccesses::downloadUrl($access))->assertOk();
        $this->get(DigitalAccesses::downloadUrl($access))->assertOk();
        $this->get(DigitalAccesses::downloadUrl($access))->assertStatus(410)->assertSee('batas unduhan habis');
        $this->get(DigitalAccesses::url($access))->assertStatus(410);
        $this->assertSame(2, $access->refresh()->downloads);
    }

    public function test_an_expired_link_no_longer_opens(): void
    {
        $this->in(fn () => $this->game->update(['access_days' => 3]));
        $access = $this->access($this->paidLead());
        $url = DigitalAccesses::url($access);
        $download = DigitalAccesses::downloadUrl($access);

        $this->travel(4)->days();
        $this->get($url)->assertForbidden();
        $this->get($download)->assertForbidden();

        // Even with a signature that outlives it (a tampered expiry is refused by the signature already).
        $this->in(fn () => $access->forceFill(['expires_at' => now()->subMinute()])->save());
        $this->assertFalse($access->refresh()->usable());
    }

    public function test_a_wrong_token_or_signature_is_refused(): void
    {
        $access = $this->access($this->paidLead());

        $this->get(route('digital.access', $access->token))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', DigitalAccesses::url($access)))->assertForbidden();
        $this->get('/akses/short')->assertNotFound();

        $forged = str_replace($access->token, str_repeat('a', 40), DigitalAccesses::url($access));
        $this->get($forged)->assertForbidden();

        $other = clone $access;
        $other->token = str_repeat('b', 40);
        $this->get(DigitalAccesses::url($other))->assertNotFound();
    }

    public function test_an_unpaid_lead_gets_no_access_and_an_old_link_stops(): void
    {
        $this->inbound('Halo kak');
        $lead = $this->in(fn () => tap(Lead::query()->firstOrFail(), fn (Lead $l) => $l->forceFill(['property_id' => $this->game->id])->save()));
        $this->assertNull($this->in(fn () => app(DigitalAccesses::class)->issue($lead)));

        $lead = $this->paidLead();
        $access = $this->access($lead);
        $this->in(fn () => $lead->forceFill(['paid_at' => null])->save());
        $this->get(DigitalAccesses::url($access))->assertStatus(410)->assertSee('pembayaran belum dikonfirmasi');
        $this->get(DigitalAccesses::downloadUrl($access))->assertStatus(410);
    }

    public function test_the_file_cannot_be_reached_by_a_direct_address(): void
    {
        foreach (['/storage/'.$this->digital->id.'/game.html', '/storage/digital/'.$this->digital->id.'/game.html', '/digital/'.$this->digital->id.'/game.html'] as $path) {
            $this->assertContains($this->get($path)->status(), [403, 404], $path);
        }
        $this->assertFalse(Storage::disk('public')->exists($this->digital->id.'/game.html'));
        $this->assertFalse((bool) config('filesystems.disks.digital.serve'));
    }

    // ---- Resends, refund and revoking ---------------------------------------------------------------------------

    public function test_staff_may_resend_three_times_then_only_an_admin(): void
    {
        $lead = $this->paidLead();
        $agent = $this->agent($lead);
        $this->actingInTenant($agent, $this->digital);

        foreach ([1, 2, 3] as $i) {
            Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('digitalAccessResend');
        }

        $this->assertSame(3, $this->access($lead)->resends);
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionDisabled('digitalAccessResend');
        $this->assertFalse($this->in(fn () => app(DigitalAccesses::class)->resend($lead, $agent, $agent->name))['ok']);
        $this->assertSame(3, $this->in(fn () => LeadActivity::query()->where('type', 'access_resent')->count()));
        $this->assertFalse($this->in(fn () => LeadActivity::query()->where('body', 'like', '%/akses/%')->exists()), 'no link in the activity');

        // An admin still can, and may issue a new link (the old one stops).
        $admin = $this->adminOf($this->digital);
        $this->actingInTenant($admin, $this->digital);
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('digitalAccessResend');
        $old = $this->access($lead);
        $this->assertSame(4, $old->resends);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('digitalAccessReissue');
        $this->assertNotNull($old->refresh()->revoked_at);
        $new = $this->access($lead);
        $this->assertNotSame($old->id, $new->id);
        $this->assertStringContainsString('/akses/'.$new->token, (string) collect($this->sent())->last());
        $this->get(DigitalAccesses::url($old))->assertStatus(410);

        $this->actingInTenant($agent, $this->digital);
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionHidden('digitalAccessReissue');
    }

    public function test_the_inbox_can_resend_too(): void
    {
        $lead = $this->paidLead();
        $this->actingInTenant($this->agent($lead), $this->digital);
        $count = count($this->sent());

        Livewire::test(Inbox::class, ['leadId' => $lead->id])->callAction('digitalAccessResend');
        $this->assertCount($count + 1, $this->sent());
    }

    public function test_moving_to_refund_revokes_access_with_an_activity(): void
    {
        $lead = $this->paidLead();
        $access = $this->access($lead);

        $this->in(fn () => app(MoveLeadToStage::class)(Lead::query()->findOrFail($lead->id), $this->digitalStage(DigitalStages::REFUND), $this->adminOf($this->digital),
            ['lost_reason_id' => LostReason::query()->where('label', 'Minta refund')->value('id')]));

        $this->assertNotNull($access->refresh()->revoked_at);
        $this->assertTrue($this->in(fn () => LeadActivity::query()->where('type', 'access_revoked')->where('body', 'like', '%Refund%')->exists()));
        $this->get(DigitalAccesses::url($access))->assertStatus(410)->assertSee('dicabut');
        $this->assertSame('lead sudah refund', $this->in(fn () => app(DigitalAccesses::class)->cannotResend($lead, $this->adminOf($this->digital))));
    }

    public function test_cabut_akses_by_hand(): void
    {
        $lead = $this->paidLead();
        $access = $this->access($lead);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('digitalAccessRevoke');

        $this->assertNotNull($access->refresh()->revoked_at);
        $this->assertSame('dicabut manual', $access->revoked_reason);
        $this->get(DigitalAccesses::downloadUrl($access))->assertStatus(410);
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionHidden('digitalAccessRevoke');
    }

    // ---- The AI ------------------------------------------------------------------------------------------------

    public function test_the_ai_can_only_trigger_a_resend_and_never_sees_the_link(): void
    {
        $lead = $this->paidLead();
        $this->in(fn () => WaChannel::query()->update(['ai_mode' => AiMode::Auto]));
        $this->ai = [$this->toolUse(DigitalTools::RESEND), $this->text('Siap Kak, tautan aksesnya saya kirim ulang di pesan berikut ya.')];

        $this->inbound('Kak tautannya kehapus, boleh kirim lagi?');

        $this->assertSame(1, $this->access($lead)->resends);
        $this->assertStringContainsString('/akses/'.$this->access($lead)->token, (string) collect($this->sent())->last());
        $this->assertSame(1, $this->in(fn () => DigitalAccess::query()->count()), 'no new link');

        // What the AI reads of the access message: that it was sent, without the link.
        $message = $this->in(fn () => WaMessage::query()->where('origin', DigitalAccesses::ORIGIN)->latest('id')->firstOrFail());
        $this->assertStringNotContainsString('/akses/', (string) MessageContent::forAi($message));
        $requests = Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'))->map(fn ($p) => json_encode($p[0]->data()))->implode('');
        $this->assertStringNotContainsString($this->access($lead)->token, $requests);
    }

    public function test_the_ai_hands_off_after_the_limit(): void
    {
        $lead = $this->paidLead();
        $this->in(fn () => $this->access($lead)->forceFill(['resends' => DigitalAccesses::MAX_RESENDS])->save());
        $tools = $this->in(fn () => DigitalTools::for($this->digital, Lead::query()->findOrFail($lead->id)));

        $result = $tools->run(DigitalTools::RESEND, []);
        $this->assertSame('perlu_admin', $result['status']);
        $this->assertSame([], $tools->actions());
    }

    public function test_cannot_open_dead_link_and_refund_always_go_to_a_person(): void
    {
        $this->channel(AiMode::Auto);
        $this->in(fn () => $this->digital->forceFill(['ai_handoff_keywords' => 'komplain'])->save());

        foreach (['Kak filenya tidak bisa buka', 'link mati kak', 'saya mau refund'] as $text) {
            $message = $this->inbound($text);
            $this->assertSame(AiDraft::HANDOFF, $this->in(fn () => AiDraft::query()->where('message_id', $message->id)->value('status')), $text);
        }
    }

    // ---- Product file and isolation ----------------------------------------------------------------------------

    public function test_the_product_file_upload_checks_type_and_size(): void
    {
        $this->actingInTenant($this->adminOf($this->digital), $this->digital);
        $base = ['name' => 'Produk Baru', 'status' => 'available'];

        Livewire::test(ManageProperties::class)->callAction('create', $base + ['access_file_path' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertHasActionErrors(['access_file_path']);
        Livewire::test(ManageProperties::class)->callAction('create', $base + ['access_file_path' => UploadedFile::fake()->create('besar.pdf', DigitalAccesses::FILE_MAX_KB + 1, 'application/pdf')])
            ->assertHasActionErrors(['access_file_path']);
        Livewire::test(ManageProperties::class)->callAction('create', $base + ['access_file_path' => UploadedFile::fake()->createWithContent('ludo.html', self::GAME)])
            ->assertHasNoActionErrors();

        $product = $this->in(fn () => Property::query()->where('name', 'Produk Baru')->firstOrFail());
        $this->assertStringStartsWith($this->digital->id.'/', $product->access_file_path);
        $this->assertSame('ludo.html', $product->access_file_name);
        $this->assertSame(strlen(self::GAME), (int) $product->access_file_size);
        Storage::disk('digital')->assertExists($product->access_file_path);
    }

    public function test_one_agency_cannot_point_at_or_open_anothers_file_or_access(): void
    {
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'digital');
        $stolen = $this->in(fn () => Property::create(['name' => 'Curian', 'access_file_path' => $this->digital->id.'/game.html']), $other);
        $this->assertNull($stolen->access_file_path);

        $lead = $this->paidLead();
        $this->assertSame(0, $this->in(fn () => DigitalAccess::query()->count(), $other));
        $this->assertSame(1, $this->in(fn () => DigitalAccess::query()->count()));

        // The other agency's staff cannot see this lead's access actions.
        $this->actingInTenant($this->adminOf($other), $other);
        $this->get(LeadResource::getUrl('edit', ['record' => $lead], tenant: $other))->assertNotFound();
    }

    // ---- Other industries --------------------------------------------------------------------------------------

    public function test_property_and_retail_agencies_are_unchanged(): void
    {
        foreach (['property' => 'properti-satu', 'retail' => 'toko-satu'] as $industry => $slug) {
            $tenant = app(ProvisionTenant::class)('Agensi '.$industry, $slug, User::factory()->create(), $industry);
            $this->in(function () use ($tenant) {
                $product = Property::create(['name' => 'Produk', 'access_url' => 'https://example.com/x']);
                $lead = Lead::create(['name' => 'Dina', 'phone' => '089900001234', 'property_id' => $product->id, 'stage_id' => Stage::query()->orderBy('position')->value('id')]);
                $lead->forceFill(['paid_at' => now()])->save();
                $this->assertNull(app(DigitalAccesses::class)->issue($lead));
                $this->assertNull(DigitalTools::for($tenant, $lead));

                $lost = Stage::query()->where('type', 'lost')->firstOrFail();
                app(MoveLeadToStage::class)($lead, $lost, $this->adminOf($tenant), ['lost_reason_id' => LostReason::query()->value('id')]);
                $this->assertFalse(LeadActivity::query()->where('type', 'access_revoked')->exists());
            }, $tenant);

            $this->assertStringNotContainsString('link mati', (string) $tenant->fresh()->ai_handoff_keywords);
        }
    }
}
