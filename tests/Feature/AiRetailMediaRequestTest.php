<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\MediaRequest;
use App\Ai\ReplySignals;
use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
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
use Tests\TestCase;

/**
 * Pekerjaan 2 (23 Nov 2026): a shop's AI closes orders by itself. A buyer asking for a photo or video of the product
 * gets it from the media library or the knowledge's video link instead of a handoff; a COD "ok" at night is
 * recorded and moved to packing without a person. A property agency and a shop without the photo are unchanged.
 */
class AiRetailMediaRequestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Property $kaos;

    private WaChannel $channel;

    /** @var list<array> */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik, Kak.')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function open(string $industry = 'retail'): void
    {
        $this->tenant = app(ProvisionTenant::class)('Quicker Sport', $industry === 'retail' ? 'quicker-sport' : 'griya-contoh', User::factory()->create(), $industry);
        $this->kaos = $this->in(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function handoff(string $why = 'Pembeli minta foto produk'): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => ReplySignals::HANDOFF_TOOL, 'input' => ['alasan' => $why]]]];
    }

    private function says(string $text): WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    private function buyer(array $lead = []): Lead
    {
        $this->says('Halo kak');

        return $this->in(function () use ($lead) {
            $row = Lead::query()->firstOrFail();
            $row->forceFill($lead + ['name' => 'Sari', 'property_id' => $this->kaos->id, 'quantity' => 2, 'variant' => 'Hitam'])->save();
            $this->channel->forceFill(['ai_mode' => AiMode::Auto])->save();

            return $row->fresh();
        });
    }

    private function photo(string $title, string $file = 'foto.jpg'): int
    {
        return $this->in(fn () => MediaAsset::create([
            'folder_id' => MediaFolder::query()->value('id') ?? MediaFolder::create(['name' => 'Foto produk'])->id,
            'title' => $title, 'kind' => 'link', 'url' => 'https://example.com/'.$file, 'active' => true,
        ])->id);
    }

    private function draftFor(WaMessage $m): ?AiDraft
    {
        return $this->in(fn () => AiDraft::query()->where('message_id', $m->getKey())->first());
    }

    /** @return list<string> */
    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    private function handoffs(): int
    {
        return $this->in(fn () => LeadActivity::query()->where('type', 'ai_handoff')->count());
    }

    /** @return list<array> */
    private function asked(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), 'anthropic'))->map(fn (Request $r) => $r->data())->values()->all();
    }

    public function test_a_photo_request_is_answered_from_the_library_even_when_the_model_wants_a_person(): void
    {
        $this->open();
        $other = $this->photo('Tas Badminton Putih', 'tas.jpg');
        $hitam = $this->photo('Kaos Lari Hitam', 'kaos-hitam.jpg');
        $this->buyer();
        $this->ai = [$this->handoff(), $this->handoff()];

        $ask = $this->says('Kak ada foto aslinya?');

        $draft = $this->draftFor($ask);
        $this->assertSame(AiDraft::SENT, $draft->status);
        $this->assertSame([$hitam], $draft->media_asset_ids);
        $this->assertNotContains($other, $draft->media_asset_ids);
        $this->assertContains('Ini foto produknya ya kak 😊', $this->sent());
        $this->assertSame(0, $this->handoffs());
        $this->assertStringContainsString(MediaRequest::NOTE, $this->asked()[0]['system']);
        $this->assertCount(2, $this->asked(), 'Asked once more before the fixed reply.');
    }

    public function test_the_retry_answer_with_its_own_attachment_is_sent(): void
    {
        $this->open();
        $hitam = $this->photo('Kaos Lari Hitam');
        $this->buyer();
        $this->ai = [$this->handoff(), $this->text("Ini fotonya kak 😊\nLAMPIRAN: {$hitam}")];

        $ask = $this->says('Boleh minta gambarnya kak');

        $this->assertSame([$hitam], $this->draftFor($ask)->media_asset_ids);
        $this->assertContains('Ini fotonya kak 😊', $this->sent());
        $this->assertSame(0, $this->handoffs());
    }

    public function test_an_answer_without_an_attachment_gets_the_products_photo(): void
    {
        $this->open();
        $hitam = $this->photo('Kaos Lari Hitam');
        $this->buyer();
        $this->ai = [$this->text('Siap kak, ini fotonya ya.')];

        $ask = $this->says('Ada real pict kak?');

        $this->assertSame([$hitam], $this->draftFor($ask)->media_asset_ids);
    }

    public function test_a_video_request_gets_the_video_link_from_the_knowledge(): void
    {
        $this->open();
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Video Kaos Lari', 'content' => 'Video review Kaos Lari: https://www.youtube.com/watch?v=abc123XYZ', 'active' => true, 'property_id' => $this->kaos->id]));
        $this->buyer();
        $this->ai = [$this->handoff('Pembeli minta video'), $this->handoff('Pembeli minta video')];

        $this->says('Ada videonya kak?');

        $this->assertContains("Ini videonya ya kak 😊\nhttps://www.youtube.com/watch?v=abc123XYZ", $this->sent());
        $this->assertSame(0, $this->handoffs());
    }

    public function test_without_a_photo_of_the_product_the_chat_still_goes_to_a_person(): void
    {
        $this->open();
        $this->photo('Tas Badminton Putih');
        $this->buyer();
        $this->ai = [$this->handoff()];

        $ask = $this->says('Kak ada fotonya?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($ask)->status);
        $this->assertSame(1, $this->handoffs());
        $this->assertStringNotContainsString(MediaRequest::NOTE, $this->asked()[0]['system']);
    }

    public function test_a_property_agency_photo_request_is_unchanged(): void
    {
        $this->open('properti');
        $this->photo('Kaos Lari Hitam');
        $this->buyer();
        $this->ai = [$this->handoff()];

        $ask = $this->says('Ada foto rumahnya?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($ask)->status);
        $this->assertStringNotContainsString(MediaRequest::NOTE, $this->asked()[0]['system']);
        $this->assertStringNotContainsString(MediaRequest::RULE, $this->asked()[0]['system']);
    }

    public function test_a_large_library_still_lists_the_products_own_photo(): void
    {
        $this->open();
        foreach (range(1, 35) as $i) {
            $this->photo('Raket Model '.$i, "raket-$i.jpg");
        }
        $hitam = $this->photo('Kaos Lari Hitam');
        $this->buyer();
        $this->ai = [$this->text('Kaosnya bahan dryfit kak.')];

        $this->says('Bahannya apa kak?');

        $this->assertStringContainsString("{$hitam} | Kaos Lari Hitam", $this->asked()[0]['system']);
    }

    public function test_a_cod_ok_at_midnight_is_recorded_and_moved_to_packing_without_a_person(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 23:40:00', 'Asia/Jakarta'));
        $this->open();
        $lead = $this->buyer([
            'shipping_address' => 'Jl. Sam Ratulangi No. 12 RT 002/RW 001, Kel. Wenang Utara, Kec. Wenang, Kota Manado, Sulawesi Utara 95111',
            'shipping_province' => 'Sulawesi Utara',
            'shipping_address_parts' => ['kelurahan' => 'Wenang Utara', 'kecamatan' => 'Wenang', 'kota' => 'Kota Manado', 'provinsi' => 'Sulawesi Utara', 'kode_pos' => '95111'],
            'payment_type' => 'cod', 'courier' => 'JNE REG',
        ]);
        $this->in(fn () => $lead->conversation()->first()->messages()->create([
            'channel_id' => $this->channel->id, 'direction' => 'out', 'type' => 'text', 'status' => MessageStatus::Sent, 'sent_at' => now(),
            'body' => 'Ringkasan pesanan: Kaos Lari Hitam x2, kirim ke Jl. Sam Ratulangi No. 12, Kota Manado. Ongkir JNE REG Rp 62.000, total Rp 240.000, bayar di tempat (COD). Sudah sesuai kak?',
        ]));
        $this->ai = [$this->text('Siap kak, pesanannya kami catat. Total dibayar saat paket diterima, segera kami kemas ya.')];

        $ok = $this->says('Ok kak');

        $this->assertSame(AiDraft::SENT, $this->draftFor($ok)->status);
        $this->assertSame('Dikemas & dikirim', $this->in(fn () => $lead->fresh()->stage?->name));
        $this->assertSame(0, $this->handoffs());
    }
}
