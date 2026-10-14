<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\RunFollowUps;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The AI and the automatic follow-ups can send items from the media library. */
class MediaAiFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Tenant $tenant;

    private int $n = 0;

    private string $aiAnswer = "Harga Cluster Mawar mulai 450 juta, Kak. Ini price list-nya.\nLAMPIRAN: %FILE%";

    private int $fileId = 0;

    /** @var list<string> */
    private array $aiSystems = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->makeTenant();
        $this->tenant->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);

        Http::fake([
            'api.anthropic.com/*' => function (Request $r) {
                $this->aiSystems[] = (string) $r['system'];

                return Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => str_replace('%FILE%', (string) $this->fileId, $this->aiAnswer)]]]);
            },
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function gateway(AiMode $mode = AiMode::Off): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    /** @return array{0: MediaAsset, 1: MediaAsset} price list file, maps link */
    private function library(): array
    {
        return $this->inTenant(function () {
            $path = 'media-library/'.$this->tenant->getKey().'/pl.pdf';
            Storage::disk('local')->put($path, self::PDF);
            $file = MediaAsset::create([
                'folder_id' => MediaFolder::query()->where('name', 'Price list')->value('id'), 'title' => 'Price list Mawar', 'kind' => 'file', 'path' => $path,
                'original_name' => 'price-list.pdf', 'size_bytes' => strlen(self::PDF), 'active' => true,
                'description' => 'Kirim bila klien menanyakan harga Cluster Mawar.',
            ]);
            $link = MediaAsset::create([
                'folder_id' => MediaFolder::query()->where('name', 'Link Google Maps')->value('id'), 'title' => 'Lokasi kantor', 'kind' => 'link',
                'url' => 'https://maps.app.goo.gl/abc', 'active' => true,
            ]);

            $this->fileId = $file->getKey();

            return [$file, $link];
        });
    }

    private function inbound(WaChannel $channel, string $text): WaMessage
    {
        return $this->inTenant(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', $text, null, CarbonImmutable::now(), 'Budi',
        )));
    }

    private function gatewayCalls(string $type): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'gw.example.com') && ($r['type'] ?? null) === $type)->count();
    }

    private function knowledge(): void
    {
        $this->inTenant(fn () => AiKnowledgeItem::create(['title' => 'Harga Cluster Mawar', 'content' => 'Mulai 450 juta.']));
    }

    public function test_the_ai_is_told_what_the_library_holds_and_sends_the_item_it_picks_in_automatic_mode(): void
    {
        $this->knowledge();
        $this->library();
        $channel = $this->gateway(AiMode::Auto);

        $this->inbound($channel, 'Berapa harga cluster mawar?');

        $this->assertStringContainsString($this->fileId.' | Price list Mawar | Price list | Kirim bila klien menanyakan harga Cluster Mawar.', $this->aiSystems[0]);
        $this->assertSame(1, $this->gatewayCalls('text'), 'The text answer goes out');
        $this->assertSame(1, $this->gatewayCalls('document'), 'and so does the price list');

        $texts = $this->inTenant(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body', 'type')->all());
        $this->assertSame('Harga Cluster Mawar mulai 450 juta, Kak. Ini price list-nya.', $texts['text'], 'The LAMPIRAN line is never shown to the customer');
        $this->assertSame('document', $this->inTenant(fn () => WaMessage::query()->where('direction', 'out')->latest('id')->value('type')));
    }

    public function test_ids_the_model_invents_are_ignored_and_at_most_two_items_go_out(): void
    {
        $this->knowledge();
        [$file, $link] = $this->library();
        $this->aiAnswer = "Silakan, Kak.\nLAMPIRAN: 999, {$file->getKey()}, {$link->getKey()}, {$file->getKey()}, 5";
        $channel = $this->gateway(AiMode::Auto);

        $this->inbound($channel, 'Boleh minta price list dan lokasi?');

        $this->assertSame(1, $this->gatewayCalls('document'));
        $this->assertSame(2, $this->gatewayCalls('text'), 'The answer and the link; no third item, no made-up id');
    }

    public function test_a_line_without_ids_is_still_removed_from_the_answer(): void
    {
        $this->knowledge();
        $this->library();
        $this->aiAnswer = "Baik, Kak.\nLAMPIRAN: tidak ada";

        $this->inbound($this->gateway(AiMode::Auto), 'Halo, cluster mawar?');

        $this->assertSame('Baik, Kak.', $this->inTenant(fn () => WaMessage::query()->where('direction', 'out')->value('body')));
        $this->assertSame(0, $this->gatewayCalls('document'));
    }

    public function test_draft_mode_only_suggests_and_the_agent_sends_text_and_item_together(): void
    {
        $this->knowledge();
        [$file] = $this->library();
        $channel = $this->gateway(AiMode::Draft);

        $message = $this->inbound($channel, 'Berapa harga cluster mawar?');
        $draft = $this->inTenant(fn () => AiDraft::query()->where('message_id', $message->getKey())->firstOrFail());

        $this->assertSame([$file->getKey()], $draft->media_asset_ids);
        $this->assertSame(0, $this->gatewayCalls('document'), 'Draft mode sends nothing');

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(Inbox::class, ['leadId' => $message->conversation->lead_id])
            ->assertSee('Ikut terkirim: Price list Mawar')
            ->call('useAiDraft', $draft->getKey())
            ->call('send');

        $this->assertSame(1, $this->gatewayCalls('text'));
        $this->assertSame(1, $this->gatewayCalls('document'));
    }

    public function test_without_a_library_the_ai_prompt_has_no_attachment_section(): void
    {
        $this->knowledge();
        $this->aiAnswer = 'Mulai 450 juta, Kak.';

        $this->inbound($this->gateway(AiMode::Auto), 'Berapa harga cluster mawar?');

        $this->assertStringNotContainsString('LAMPIRAN yang boleh dikirim', $this->aiSystems[0]);
        $this->assertSame(0, $this->gatewayCalls('document'));
    }

    public function test_a_follow_up_sends_its_library_item_right_after_the_text(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
        $this->gateway();
        [$file] = $this->library();
        $rule = $this->inTenant(fn () => FollowUpRule::create([
            'name' => 'H+1', 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(), 'delay_days' => 1, 'send_time' => '09:00',
            'body' => 'Halo {nama}, ini price list kami.', 'media_asset_id' => $file->getKey(),
        ]));
        $lead = $this->makeLead($this->tenant, ['name' => 'Budi Santoso']);
        $lead->forceFill(['stage_entered_at' => Carbon::parse('2026-10-11 12:00', 'Asia/Jakarta')])->save();

        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0], app(RunFollowUps::class)($this->tenant));

        $this->assertSame(2, $this->gatewayCalls('text'), 'The number introduces the agency first, then the follow-up');
        $this->assertSame(1, $this->gatewayCalls('document'));
        $this->assertSame('H+1 + Price list Mawar', $this->inTenant(fn () => FollowUpLog::query()->where('follow_up_rule_id', $rule->getKey())->value('note')));
    }

    public function test_a_missing_file_does_not_undo_the_follow_up_that_already_went_out(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
        $this->gateway();
        [$file] = $this->library();
        Storage::disk('local')->delete($file->path);
        $this->inTenant(fn () => FollowUpRule::create([
            'name' => 'H+1', 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(), 'delay_days' => 1, 'send_time' => '09:00',
            'body' => 'Halo {nama}.', 'media_asset_id' => $file->getKey(),
        ]));
        $lead = $this->makeLead($this->tenant, ['name' => 'Budi']);
        $lead->forceFill(['stage_entered_at' => Carbon::parse('2026-10-11 12:00', 'Asia/Jakarta')])->save();

        $this->assertSame(1, app(RunFollowUps::class)($this->tenant)['sent']);

        $note = $this->inTenant(fn () => FollowUpLog::query()->value('note'));
        $this->assertStringContainsString('lampiran gagal', $note);
        $this->assertSame(2, $this->gatewayCalls('text'));
    }

    public function test_a_rule_cannot_point_at_another_agencys_file(): void
    {
        $other = $this->makeTenant('lain');
        $foreign = app(CurrentTenant::class)->run($other, fn () => MediaAsset::create([
            'folder_id' => MediaFolder::query()->value('id'), 'title' => 'Milik lain', 'kind' => 'link', 'url' => 'https://example.com', 'active' => true,
        ]));

        $this->expectException(\LogicException::class);
        $this->inTenant(fn () => FollowUpRule::create([
            'name' => 'X', 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(), 'delay_days' => 1, 'body' => 'Halo', 'media_asset_id' => $foreign->getKey(),
        ]));
    }
}
