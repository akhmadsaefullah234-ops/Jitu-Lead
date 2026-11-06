<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\ShopTools;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Reports\DashboardData;
use App\Support\CurrentTenant;
use App\Support\Period;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The "AI almost said the payment arrived" guard, from 15 handoffs in 5 days (12 left without any reply): an order
 * "sudah kami terima" is not a payment claim, and a buyer is never left without an answer.
 */
class AiPaymentClaimGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private WaChannel $channel;

    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->inShop(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));
        $this->channel = $this->inShop(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));

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
        parent::tearDown();
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function says(string $text): WaMessage
    {
        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    /** @return list<string> */
    private function sent(): array
    {
        return $this->inShop(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    private function draftFor(WaMessage $m): ?AiDraft
    {
        return $this->inShop(fn () => AiDraft::query()->where('message_id', $m->getKey())->first());
    }

    private function asked(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'anthropic'))->count();
    }

    public static function sentences(): array
    {
        return [
            'order received' => ['Data pesanan kakak sudah kami terima', false],
            'thanks, order received' => ['Terima kasih, pesanan sudah kami terima', false],
            'order recorded, pay by transfer' => ['Pesanan sudah kami terima, silakan transfer ke BCA 123 a.n. Quicker Sport', false],
            'address received' => ['Alamat kakak sudah kami terima, mohon pilih cara bayar ya', false],
            'please transfer, order noted' => ['Mohon transfer dulu, pesanan kakak sudah masuk daftar kami', false],
            'not yet paid' => ['Pesanan belum lunas ya kak', false],
            'before it is paid' => ['Sebelum lunas, barang belum dikirim', false],
            'payment received' => ['pembayaran sudah kami terima', true],
            'payment of the buyer received' => ['Pembayaran kakak sudah kami terima, terima kasih', true],
            'transfer in' => ['transfer sudah masuk', true],
            'we have received the transfer' => ['Sudah kami terima transfernya ya kak', true],
            'paid' => ['sudah lunas', true],
            'DP in' => ['DP-nya sudah kami terima', true],
        ];
    }

    #[DataProvider('sentences')]
    public function test_the_payment_claim_guard_on_real_sentences(string $sentence, bool $held): void
    {
        $this->assertSame($held, ShopTools::claimsPaymentReceived($sentence), $sentence);
    }

    public function test_a_first_form_message_with_an_order_is_answered_and_not_handed_over(): void
    {
        $this->ai = [$this->text('Halo kak Sari, data pesanan kakak sudah kami terima: Kaos Lari 2 pcs. Mau bayar COD atau transfer, kak?')];

        $message = $this->says('Halo admin, saya Sari mau order: Kaos Lari Hitam 2 pcs, Jl. Mawar 5 Bandung 40134');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertStringContainsString('data pesanan kakak sudah kami terima', collect($this->sent())->last());
        $this->assertSame(0, $this->inShop(fn () => LeadActivity::query()->where('type', 'ai_handoff')->count()));
    }

    public function test_a_payment_claim_is_written_again_once_and_the_buyer_gets_the_new_answer(): void
    {
        $this->ai = [
            $this->text('Pembayaran kakak sudah kami terima, pesanan segera dikemas.'),
            $this->text('Pesanan kakak sudah kami catat. Mau bayar COD atau transfer, kak?'),
        ];

        $message = $this->says('Halo admin, saya Sari mau order: Kaos Lari 2 pcs');

        $this->assertSame(2, $this->asked());
        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertSame(['Pesanan kakak sudah kami catat. Mau bayar COD atau transfer, kak?'], array_slice($this->sent(), -1));
        $this->assertSame(0, $this->inShop(fn () => LeadActivity::query()->where('type', 'ai_handoff')->count()));
    }

    public function test_a_claim_that_stays_gets_a_short_safe_reply_and_an_agent_is_told(): void
    {
        $this->ai = [
            $this->text('Pembayaran sudah kami terima kak.'),
            $this->text('Transfer sudah masuk ya kak, terima kasih.'),
        ];

        $message = $this->says('Halo admin, saya Sari mau order: Kaos Lari 2 pcs');

        $this->assertSame(2, $this->asked(), 'Written again once, not more.');
        $this->assertSame([GenerateAiReply::SAFE_PAYMENT_REPLY], array_slice($this->sent(), -1), 'The buyer always hears something.');
        $draft = $this->draftFor($message);
        $this->assertSame(AiDraft::SENT, $draft->status);
        $this->assertStringContainsString('pembayaran harus dicek agen', $draft->reason);
        $handoff = $this->inShop(fn () => LeadActivity::query()->where('type', 'ai_handoff')->get());
        $this->assertCount(1, $handoff);
        $this->assertStringContainsString('AI menyerahkan chat ke agen: AI hampir menyatakan pembayaran diterima', $handoff->first()->body);
    }

    public function test_handoffs_are_counted_by_reason_and_those_without_a_reply_in_three_minutes(): void
    {
        $this->channel->forceFill(['ai_mode' => AiMode::Auto])->save();
        // One handoff with no reply at all (the AI said nothing), one answered by the safe reply.
        $this->ai = [
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'serahkan_ke_admin', 'input' => ['alasan' => 'Pertanyaan retur']]]],
        ];
        $silent = $this->says('Barang saya rusak, mau retur');
        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($silent)->status);
        $this->travel(10)->minutes();
        $this->ai = [$this->text('Pembayaran sudah kami terima.'), $this->text('Transfer sudah masuk.')];
        $this->says('Halo admin, saya Sari mau order: Kaos Lari 2 pcs');

        $this->travel(4)->minutes();
        $this->actingInTenant($this->shop->users()->first(), $this->shop);
        $data = $this->inShop(fn () => app(DashboardData::class)->automation(Period::make('today')));

        $this->assertSame(2, $data['handoffs']);
        $this->assertSame(1, $data['handoff_unanswered'], 'Only the one nobody answered.');
        $this->assertContains(1, array_values($data['handoff_reasons']));
        $this->assertTrue(collect(array_keys($data['handoff_reasons']))->contains(fn ($r) => str_contains($r, 'pembayaran diterima')));
    }
}
