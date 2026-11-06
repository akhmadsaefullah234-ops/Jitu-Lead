<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Actions\LearnFromReply;
use App\Actions\ProvisionTenant;
use App\Ai\BuyerFacts;
use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Filament\Resources\AiSuggestions\Pages\ManageAiSuggestions;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\AiSuggestion;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Privacy\KnowledgeScan;
use App\Privacy\PersonalData;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 6 Nov 2026, Quicker Sport: learned knowledge item #54 held buyer A's order ("saya zainal abidin mau order ...
 * Kota Sorong, Papua Barat Daya"), and the AI greeted buyer B (lead #481, Syahrudin, Kab. Buol) as "Kak Zainal" and
 * spoke of "pesanan sebelumnya ke Kota Sorong". Learned knowledge is cleaned of personal data, old entries are
 * flagged, and an answer naming another buyer's name or city is written again or goes to a person.
 */
class BuyerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** Item #54 as it was kept. */
    private const ORDER_A = 'Halo admin, saya zainal abidin mau order sepatu lari hitam ukuran 42 1 pasang. Alamat Jl. Basuki Rahmat No. 7 RT 03/RW 02, Kel. Klawasi, Kec. Sorong Barat, Kota Sorong, Papua Barat Daya 98412. HP 0813-4455-6677';

    private const REPLY_A = 'Baik kak Zainal, pesanannya kami catat ya. Ongkir ke Kota Sorong, Papua Barat Daya Rp 85.000, total Rp 274.000. Mau transfer atau COD kak?';

    private Tenant $shop;

    private Property $shoe;

    private WaChannel $channel;

    /** @var list<array> */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->shoe = $this->inShop(fn () => Property::create(['name' => 'Sepatu Lari', 'price_from' => 189000, 'weight_grams' => 800]));
        $this->channel = $this->inShop(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
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

    // ---- Helpers -----------------------------------------------------------------------------------------------

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function says(string $text, string $phone = '6281234567890', string $name = 'Syahrudin'): WaMessage
    {
        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), $name,
        )));
    }

    /** An agent's hand-written reply in the chat of $question. */
    private function agentReplies(WaMessage $question, string $body): WaMessage
    {
        return $this->inShop(fn () => WaConversation::query()->findOrFail($question->conversation_id)->messages()->create([
            'channel_id' => $this->channel->id, 'direction' => 'out', 'type' => 'text', 'body' => $body, 'status' => MessageStatus::Sent, 'sent_at' => now(),
        ]));
    }

    /** Buyer B: lead #481, Syahrudin in Kab. Buol, with the AI on from now. */
    private function buyerB(array $lead = []): Lead
    {
        $this->says('Halo kak');

        return $this->inShop(function () use ($lead) {
            $row = Lead::query()->firstOrFail();
            $row->forceFill($lead + [
                'name' => 'Syahrudin', 'property_id' => $this->shoe->id, 'quantity' => 1, 'variant' => 'Hitam 42',
                'shipping_address' => 'Desa Lakea, Kec. Lakea, Kab. Buol, Sulawesi Tengah',
                'shipping_province' => 'Sulawesi Tengah',
            ])->save();
            $this->channel->forceFill(['ai_mode' => AiMode::Auto])->save();

            return $row->fresh();
        });
    }

    /** Item #54 and #22 as they were before this change: learned, active, with buyers' data in them. */
    private function oldLearnedItems(): array
    {
        return $this->inShop(fn () => [
            AiKnowledgeItem::create(['title' => 'Halo admin, saya zainal abidin mau order', 'question' => self::ORDER_A, 'content' => self::REPLY_A, 'source' => 'learned', 'active' => true]),
            AiKnowledgeItem::create(['title' => 'Kirim ke Padang', 'question' => 'Kak kirim ke alamat saya ya, Jl. Veteran No. 21, Kec. Padang Barat, Kota Padang', 'content' => 'Siap kak Rahmi, dikirim ke Kota Padang besok.', 'source' => 'learned', 'active' => true]),
        ]);
    }

    private function draftFor(WaMessage $m): ?AiDraft
    {
        return $this->inShop(fn () => AiDraft::query()->where('message_id', $m->getKey())->first());
    }

    /** @return list<string> */
    private function sent(): array
    {
        return $this->inShop(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    /** @return list<array> */
    private function asked(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), 'anthropic'))->map(fn (Request $r) => $r->data())->values()->all();
    }

    // ---- 1. Learning from an agent's reply -----------------------------------------------------------------------

    public function test_an_order_message_is_never_learned_and_a_general_question_is_kept_without_personal_data(): void
    {
        $order = $this->says(self::ORDER_A, '6281300000001', 'Zainal');
        $reply = $this->agentReplies($order, self::REPLY_A);

        $this->assertNull($this->inShop(fn () => app(LearnFromReply::class)(WaConversation::query()->findOrFail($order->conversation_id), $reply)));
        $this->assertSame(0, $this->inShop(fn () => AiSuggestion::count()), 'An order, form or address is one buyer\'s, never an example.');

        $question = $this->says('Bisa kirim ke Sorong kak? Berapa lama sampainya?', '6281300000001', 'Zainal');
        $reply = $this->agentReplies($question, 'Bisa kak Zainal, ke Kota Sorong sekitar 5-7 hari. WA kami 0813-4455-6677 ya');

        $suggestion = $this->inShop(fn () => app(LearnFromReply::class)(WaConversation::query()->findOrFail($question->conversation_id), $reply));

        $this->assertSame('Bisa kirim ke [kota] kak? Berapa lama sampainya?', $suggestion->question);
        $this->assertSame('Bisa kak [nama], ke [kota] sekitar 5-7 hari. WA kami [nomor HP] ya', $suggestion->answer);
    }

    public function test_the_cleaner_takes_out_names_numbers_addresses_and_cities_and_keeps_the_store_own(): void
    {
        $clean = PersonalData::scrubPair(self::ORDER_A, self::REPLY_A.' Transfer ke BCA 7001234567 a.n. Quicker Sport', "Quicker Sport\nRekening BCA 7001234567 a.n. Quicker Sport");

        $this->assertSame('Halo admin, saya [nama] mau order sepatu lari hitam ukuran 42 1 pasang. Alamat [alamat]. HP [nomor HP]', $clean['question']);
        $this->assertStringStartsWith('Baik kak [nama], pesanannya kami catat ya. Ongkir ke [kota] Rp 85.000, total Rp 274.000.', $clean['answer']);
        $this->assertStringContainsString('BCA 7001234567 a.n. Quicker Sport', $clean['answer'], 'The store\'s own account is not a buyer\'s.');
        $this->assertTrue(PersonalData::isPersonalMessage(self::ORDER_A));

        $form = PersonalData::scrub("Nama: Rina Wati\nNo WA: 6281299998888\nEmail rina@contoh.id\nRek BRI 1234 5678 9012\nAlamat: Jl. Veteran 21, Padang");
        $this->assertSame("Nama: [nama]\nNo WA: [nomor HP]\nEmail [email]\nRek BRI [nomor rekening]\nAlamat: [alamat]", $form);

        // A general question stays as it is.
        foreach (['Bisa COD kak?', 'Ukuran 42 ready? Harganya berapa?', 'Saya mau order 2 pasang bisa diskon?', 'Pengirimannya ke kota mana saja?'] as $general) {
            $this->assertSame($general, PersonalData::scrub($general), $general);
            $this->assertFalse(PersonalData::isPersonalMessage($general), $general);
        }
    }

    public function test_the_approval_shows_a_cleaned_preview_and_refuses_to_save_personal_data(): void
    {
        $suggestion = $this->inShop(fn () => AiSuggestion::create(['question' => self::ORDER_A, 'answer' => self::REPLY_A, 'status' => 'pending']));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        // The preview is already cleaned; the order message itself cannot be saved as a question.
        Livewire::test(ManageAiSuggestions::class)
            ->mountAction(TestAction::make('approve')->table($suggestion))
            ->assertActionDataSet(['question' => 'Halo admin, saya [nama] mau order sepatu lari hitam ukuran 42 1 pasang. Alamat [alamat]. HP [nomor HP]'])
            ->callMountedAction()
            ->assertHasActionErrors(['question']);

        // Something typed back in is refused too.
        Livewire::test(ManageAiSuggestions::class)
            ->callAction(TestAction::make('approve')->table($suggestion), ['question' => 'Ongkir ke Sorong berapa?', 'answer' => 'Ke Sorong Rp 85.000 kak, hubungi 0813-4455-6677'])
            ->assertHasActionErrors(['answer']);
        $this->assertSame(0, $this->inShop(fn () => AiKnowledgeItem::count()));

        // A general question goes in, cleaned.
        Livewire::test(ManageAiSuggestions::class)
            ->callAction(TestAction::make('approve')->table($suggestion), ['question' => 'Ongkir ke Papua berapa?', 'answer' => 'Ke Papua mulai Rp 85.000 kak.'])
            ->assertHasNoActionErrors();

        $item = $this->inShop(fn () => AiKnowledgeItem::first());
        $this->assertSame('learned', $item->source);
        $this->assertSame('Ongkir ke [kota] berapa?', $item->question);
    }

    // ---- 2. The scan of old knowledge ----------------------------------------------------------------------------

    public function test_old_entries_with_personal_data_are_flagged_in_red_and_can_be_switched_off(): void
    {
        [$a, $b] = $this->oldLearnedItems();
        $own = $this->inShop(fn () => AiKnowledgeItem::create(['title' => 'Alamat toko', 'content' => 'Toko kami di Jl. Merdeka 5, Kota Bandung. WA 0812-1111-2222.', 'source' => 'manual', 'active' => true]));
        $general = $this->inShop(fn () => AiKnowledgeItem::create(['title' => 'Bisa COD?', 'question' => 'Bisa COD kak?', 'content' => 'Bisa kak, COD tersedia.', 'source' => 'learned', 'active' => true]));

        $this->assertSame([$a->id, $b->id], $this->inShop(fn () => KnowledgeScan::ids()), 'The store\'s own address and a general question are not flagged.');

        Artisan::call('ai:scan-knowledge');
        $out = Artisan::output();
        $this->assertStringContainsString("quicker-sport #{$a->id} [learned]", $out);
        $this->assertStringContainsString('nomor HP: 0813-4455-6677', $out);
        $this->assertStringContainsString("quicker-sport #{$b->id} [learned]", $out);
        $this->assertStringNotContainsString("#{$own->id} ", $out);
        $this->assertStringContainsString('2 catatan memuat data pribadi pembeli.', $out);
        $this->assertTrue($a->fresh()->active, 'The scan alone changes nothing.');

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(ManageAiKnowledge::class)
            ->assertSee('2 catatan pengetahuan memuat data pribadi pembeli.')
            ->assertSee('Ada data pembeli')
            ->callAction('deactivatePersonal');

        $this->assertFalse($a->fresh()->active);
        $this->assertFalse($b->fresh()->active);
        $this->assertTrue($own->fresh()->active);
        $this->assertTrue($general->fresh()->active);
        Livewire::test(ManageAiKnowledge::class)->assertDontSee('catatan pengetahuan memuat data pribadi pembeli.');
    }

    // ---- 3 and 4. The AI talks only about this buyer ---------------------------------------------------------------

    public function test_the_ai_never_greets_buyer_b_with_buyer_a_name_or_city(): void
    {
        $this->oldLearnedItems();
        $this->buyerB();
        $this->ai = [
            // #481 as it happened.
            $this->text('Halo Kak Zainal, pesanan sebelumnya ke Kota Sorong, Papua Barat Daya sudah kami catat ya.'),
            $this->text('Halo Kak Syahrudin, sepatu lari hitam 42 ready ya. Mau dikirim ke Kab. Buol, Sulawesi Tengah?'),
        ];

        $message = $this->says('Kak sepatunya ready?');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertSame(['Halo Kak Syahrudin, sepatu lari hitam 42 ready ya. Mau dikirim ke Kab. Buol, Sulawesi Tengah?'], $this->sent());

        // The learned examples reach the model cleaned, next to this buyer's own data and the rules.
        $first = $this->asked()[0]['system'];
        $this->assertStringNotContainsStringIgnoringCase('zainal', $first);
        $this->assertStringNotContainsStringIgnoringCase('sorong', $first);
        $this->assertStringNotContainsStringIgnoringCase('padang', $first);
        $this->assertStringContainsString('saya [nama] mau order', $first);
        $this->assertStringContainsString(BuyerFacts::RULE, $first);
        $this->assertStringContainsString(BuyerFacts::NO_EARLIER_ORDER, $first);
        $this->assertStringContainsString('- Nama: Syahrudin', $first);
        $this->assertStringContainsString(GenerateAiReply::KNOWLEDGE_HEADER, $first);

        $retry = $this->asked()[1]['system'];
        $this->assertStringContainsString(BuyerFacts::note(['Zainal', 'sorong', 'papua barat daya']), $retry);
        $this->assertStringContainsString(BuyerFacts::EARLIER_ORDER_NOTE, $retry);
    }

    public function test_an_answer_with_a_city_from_another_entry_is_refused_and_goes_to_a_person(): void
    {
        $this->oldLearnedItems();
        $this->buyerB();
        $this->ai = [
            $this->text('Ada hitam dan putih kak, stok di Kota Padang ready ya.'),
            $this->text('Ada hitam dan putih kak, ready di Padang.'),
        ];

        $message = $this->says('Warnanya ada apa aja kak?');

        $draft = $this->draftFor($message);
        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertSame('AI hampir menyebut nama atau tempat yang bukan milik pembeli ini: padang', $draft->reason);
        $this->assertSame([], $this->sent(), 'Buyer B never reads another buyer\'s city.');
        $this->assertCount(2, $this->asked());
    }

    public function test_an_earlier_order_is_only_mentioned_when_the_lead_holds_one(): void
    {
        $lead = $this->buyerB();
        $this->ai = [$this->text('Siap kak, sama seperti pesanan sebelumnya ya.'), $this->text('Siap kak, pesanan terakhir kakak sudah kami catat.')];

        $message = $this->says('Mau order lagi kak');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertSame('AI hampir menyebut pesanan sebelumnya yang tidak ada di data lead', $this->draftFor($message)->reason);
        $this->assertSame([], $this->sent());

        // A buyer whose earlier parcel was sent may hear about it.
        $this->inShop(fn () => $lead->forceFill(['shipped_at' => now()->subWeek(), 'tracking_number' => 'JNE123456789'])->save());
        $this->ai = [$this->text('Siap kak Syahrudin, sama seperti pesanan sebelumnya ya.')];

        $again = $this->says('Yang hitam 42 lagi kak');

        $this->assertSame(AiDraft::SENT, $this->draftFor($again)->status);
        $this->assertSame(['Siap kak Syahrudin, sama seperti pesanan sebelumnya ya.'], $this->sent());
        $this->assertStringContainsString('- Pesanan sebelumnya: ada', collect($this->asked())->last()['system']);
    }

    public function test_the_buyer_own_name_city_and_province_pass_the_guard(): void
    {
        $lead = $this->inShop(fn () => new Lead(['name' => 'Zainal Abidin', 'shipping_address' => 'Jl. Basuki Rahmat 7, Kota Sorong']));
        $facts = new BuyerFacts($lead);

        $this->assertSame([], $facts->foreign('Halo Kak Zainal, ongkir ke Kota Sorong, Papua Barat Daya Rp 85.000 ya.', ''));
        $this->assertSame([], $facts->foreign('Siap Pak Abidin, dikirim dari Jakarta.', 'Barang dikirim dari Jakarta.'));
        $this->assertSame(['Rahmi', 'padang'], $facts->foreign('Halo Kak Rahmi, dikirim ke Kota Padang ya.', ''));
        $this->assertSame([], $facts->foreign('Baik kak, terima kasih. Silakan Kak Terima kasih.', ''));
    }
}
