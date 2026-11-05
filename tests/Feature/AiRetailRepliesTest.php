<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\ReplySignals;
use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The shop AI on WhatsApp (Quicker Sport), from the handoffs of 2-5 Nov 2026: shipping is checked from what the
 * lead already holds, internal text never reaches a buyer, "ok" never needs a person, and one handed-off
 * question does not silence the next ones.
 */
class AiRetailRepliesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $kaos;

    private WaChannel $channel;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->kaos = $this->inShop(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));
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

    private function tool(string $name, array $input = []): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => $name, 'input' => $input]]];
    }

    private function says(string $text): WaMessage
    {
        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    /** A buyer who already wrote once (the AI was off), with what a form filled in; the AI is on from now. */
    private function buyer(array $lead = []): Lead
    {
        $this->says('Halo kak');

        return $this->inShop(function () use ($lead) {
            $row = Lead::query()->firstOrFail();
            $row->forceFill($lead + ['name' => 'Sari', 'property_id' => $this->kaos->id, 'quantity' => 2, 'variant' => 'Hitam'])->save();
            $this->channel->forceFill(['ai_mode' => AiMode::Auto])->save();

            return $row->fresh();
        });
    }

    /** #412: a landing-page order to Manado. */
    private function manado(): array
    {
        return [
            'shipping_address' => 'Jl. Sam Ratulangi No. 12 RT 002/RW 001, Kel. Wenang Utara, Kec. Wenang, Kota Manado, Sulawesi Utara 95111',
            'shipping_province' => 'Sulawesi Utara',
            'shipping_address_parts' => ['kelurahan' => 'Wenang Utara', 'kecamatan' => 'Wenang', 'kota' => 'Kota Manado', 'provinsi' => 'Sulawesi Utara', 'kode_pos' => '95111'],
        ];
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

    /** @return list<array> the requests the model got */
    private function asked(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), 'anthropic'))->map(fn (Request $r) => $r->data())->values()->all();
    }

    private function handoffs(): int
    {
        return $this->inShop(fn () => LeadActivity::query()->where('type', 'ai_handoff')->count());
    }

    // ---- 1. Shipping from what the lead holds --------------------------------------------------------------------

    public function test_a_form_lead_asking_the_cost_gets_the_breakdown_without_being_asked_the_address(): void
    {
        $this->buyer($this->manado());
        // 2 x 300 g = 1 kg to the Sulawesi zone: JNE REG Rp 62.000, SiCepat REG Rp 80.000; 2 x Rp 89.000 = Rp 178.000.
        $this->ai = [$this->text('Kaos Lari 2 x Rp 89.000 = Rp 178.000. Ongkir ke Kota Manado: JNE REG Rp 62.000 (total Rp 240.000), SiCepat REG Rp 80.000 (total Rp 258.000). Bisa COD juga, Kak.')];

        $message = $this->says('Berapa biayanya');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertStringContainsString('total Rp 240.000', collect($this->sent())->last() ?? '');
        $system = $this->asked()[0]['system'];
        $this->assertStringContainsString('CEK ONGKIR SUDAH DIJALANKAN untuk alamat kirim pembeli yang sudah tercatat (tujuan: Kota Manado, Sulawesi Utara)', $system);
        $this->assertStringContainsString('"ongkir":62000', $system);
        $this->assertStringContainsString('"total":240000', $system);
        $this->assertStringContainsString('tanpa menanyakan alamat lagi', $system);
        $this->assertSame(0, $this->handoffs());
    }

    public function test_cod_then_the_price_question_is_answered_from_the_kalimantan_address(): void
    {
        // #418: a form order to Pontianak; "COD" and then "Berapa harganya?".
        $this->buyer(['shipping_address' => 'Jl. Gajah Mada No. 8 RT 01/RW 02, Kota Pontianak', 'shipping_province' => 'Kalimantan Barat']);
        $this->ai = [
            $this->text('Siap kak, COD bisa. Kaos Lari 2 x Rp 89.000 = Rp 178.000, ongkir SiCepat REG Rp 58.000, total Rp 236.000 dibayar saat paket diterima.'),
            $this->text('Harganya Rp 89.000 per kaos, jadi Rp 178.000 untuk 2. Ongkir JNE REG Rp 68.000 (total Rp 246.000) atau SiCepat REG Rp 58.000 (total Rp 236.000), Kak.'),
        ];

        $cod = $this->says('COD');
        $price = $this->says('Berapa harganya?');

        $this->assertSame(AiDraft::SENT, $this->draftFor($cod)->status);
        $this->assertSame(AiDraft::SENT, $this->draftFor($price)->status);
        $this->assertStringContainsString('(tujuan: Kota Pontianak, Kalimantan Barat)', $this->asked()[0]['system'], 'The city is read from the typed address.');
        $this->assertStringContainsString('"ongkir":58000', $this->asked()[1]['system']);
        $this->assertSame(0, $this->handoffs());
    }

    public function test_a_lone_city_name_is_the_destination_and_an_ambiguous_one_asks_the_province(): void
    {
        // #415: "Palembang" after the AI asked where to.
        $this->buyer();
        $palembang = $this->says('Palembang');

        $this->assertSame(AiDraft::SENT, $this->draftFor($palembang)->status);
        $system = $this->asked()[0]['system'];
        $this->assertStringContainsString('kota yang tampaknya baru disebut pembeli sebagai tujuan (tujuan: Palembang, Sumatera Selatan)', $system);
        $this->assertStringContainsString('"ongkir":45000', $system, 'Sumatera zone, SiCepat REG, 1 kg.');

        // With the regions imported, a name found in two provinces makes the AI ask which one.
        Cache::flush();
        DB::table('regions')->insert([
            ['code' => '11', 'parent_code' => null, 'level' => 1, 'name' => 'Aceh', 'search' => 'aceh', 'postal_code' => null],
            ['code' => '12', 'parent_code' => null, 'level' => 1, 'name' => 'Sumatera Utara', 'search' => 'sumatera utara', 'postal_code' => null],
            ['code' => '11.01', 'parent_code' => '11', 'level' => 2, 'name' => 'Kabupaten Aceh Tengah', 'search' => 'aceh tengah', 'postal_code' => null],
            ['code' => '12.01', 'parent_code' => '12', 'level' => 2, 'name' => 'Kabupaten Tapanuli Tengah', 'search' => 'tapanuli tengah', 'postal_code' => null],
            ['code' => '11.01.01', 'parent_code' => '11.01', 'level' => 3, 'name' => 'Sukamakmur', 'search' => 'sukamakmur', 'postal_code' => null],
            ['code' => '12.01.01', 'parent_code' => '12.01', 'level' => 3, 'name' => 'Sukamakmur', 'search' => 'sukamakmur', 'postal_code' => null],
        ]);

        $this->says('Sukamakmur kak');

        $system = $this->asked()[1]['system'];
        $this->assertStringContainsString('Nama tempat di pesan terakhir pembeli ada di beberapa provinsi: Aceh, Sumatera Utara. Tanyakan provinsinya', $system);
        $this->assertStringNotContainsString('CEK ONGKIR SUDAH DIJALANKAN', $system);
    }

    public function test_a_zone_marked_konfirmasi_admin_goes_to_a_person_without_asking_the_model(): void
    {
        $this->buyer(['shipping_address' => 'Jl. Raya Abepura No. 3 RT 1/RW 2, Kota Jayapura', 'shipping_province' => 'Papua']);

        $message = $this->says('Totalnya berapa kak?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertSame('Ongkir ke tujuan ini perlu dikonfirmasi admin', $this->draftFor($message)->reason);
        $this->assertSame([], $this->asked());
    }

    public function test_an_invented_shipping_cost_is_checked_and_written_again_once(): void
    {
        $this->buyer($this->manado());
        $this->ai = [
            $this->text('Ongkirnya Rp 50.000 ya kak, jadi total Rp 228.000.'),
            $this->text('Ongkir ke Kota Manado JNE REG Rp 62.000, total Rp 240.000 ya Kak.'),
        ];

        // No cost words, so nothing is checked first; the invented number makes the server check and ask again.
        $message = $this->says('Mau yang hitam 2 kak, dikirim ya');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertSame('Ongkir ke Kota Manado JNE REG Rp 62.000, total Rp 240.000 ya Kak.', collect($this->sent())->last());
        $retry = $this->asked()[1]['system'];
        $this->assertStringContainsString(GenerateAiReply::INVENTED_NOTE, $retry);
        $this->assertStringContainsString('"ongkir":62000', $retry);

        // Wrong twice: a person takes over, and the buyer never sees the number.
        $this->ai = [$this->text('Ongkir Rp 50.000 kak.'), $this->text('Ongkir Rp 51.000 kak.')];
        $again = $this->says('Kirim hari ini bisa?');
        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($again)->status);
        $this->assertSame('AI menyebut ongkir atau total yang tidak berasal dari cek ongkir', $this->draftFor($again)->reason);
        $this->assertNotContains('Ongkir Rp 51.000 kak.', $this->sent());
    }

    public function test_an_invented_cost_without_any_known_destination_goes_to_a_person(): void
    {
        $this->buyer();
        $this->ai = [$this->text('Ongkirnya Rp 20.000 kak.')];

        $message = $this->says('Kirim hari ini bisa?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertCount(1, $this->asked(), 'No check could run, so the model is not asked again.');
    }

    // ---- 2. Internal text never reaches a buyer ------------------------------------------------------------------

    public function test_reasoning_and_the_word_handoff_are_never_sent(): void
    {
        $this->buyer($this->manado());

        // #418 as it happened: reasoning, then the old signal word.
        $this->ai = [$this->text('Berdasarkan aturan, saya perlu menyerahkan ini ke admin karena pembeli bertanya soal garansi. HANDOFF')];
        $leak = $this->says('Garansinya berapa lama?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($leak)->status);
        $this->assertSame([], $this->sent());

        // Reasoning without the word: written again once, and only the clean answer goes out.
        $this->ai = [
            $this->text('Pembeli menanyakan warna. Berdasarkan pengetahuan, warna yang tersedia hitam dan putih.'),
            $this->text('Warnanya ada hitam dan putih, Kak.'),
        ];
        $colour = $this->says('Warnanya apa aja?');

        $this->assertSame(AiDraft::SENT, $this->draftFor($colour)->status);
        $this->assertSame(['Warnanya ada hitam dan putih, Kak.'], $this->sent());
        $this->assertStringContainsString(GenerateAiReply::INTERNAL_NOTE, $this->asked()[2]['system']);

        // Twice: a person.
        $this->ai = [$this->text('Sesuai instruksi yang diberikan, saya tidak boleh menjawab.'), $this->text('<thinking>cek</thinking> Ukuran ada M dan L.')];
        $size = $this->says('Ukurannya ada apa?');
        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($size)->status);
        $this->assertSame(['Warnanya ada hitam dan putih, Kak.'], $this->sent());
    }

    public function test_handing_over_is_a_tool_call_that_ends_the_answer(): void
    {
        $this->buyer();
        $this->ai = [$this->tool(ReplySignals::HANDOFF_TOOL, ['alasan' => 'Pembeli minta nego harga'])];

        $message = $this->says('Bisa kurang nggak harganya?');

        $draft = $this->draftFor($message);
        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertSame('Di luar pengetahuan AI: Pembeli minta nego harga', $draft->reason);
        $this->assertCount(1, $this->asked(), 'The model is not asked to write anything after handing over.');
        $this->assertSame([], $this->sent());
        $this->assertStringNotContainsString('balas hanya dengan kata HANDOFF', $this->asked()[0]['system']);
        $this->assertContains(ReplySignals::HANDOFF_TOOL, collect($this->asked()[0]['tools'])->pluck('name')->all());
    }

    public function test_the_checks_for_internal_text_and_short_replies(): void
    {
        foreach (['Berdasarkan aturan, saya perlu memanggil cek_ongkir.', 'HANDOFF', 'Sesuai dengan pengetahuan yang ada, stok habis.', 'Pelanggan menanyakan ongkir.',
            'Saya perlu menggunakan tool dulu.', '<function_calls>', 'Menurut instruksi saya, ...'] as $internal) {
            $this->assertTrue(ReplySignals::internal($internal), $internal);
        }

        foreach (['Ongkir ke Bandung Rp 18.000, Kak.', 'Aturan pakai: cuci dengan air dingin ya kak.', 'Siap kak, pesanan kami catat 🙏', 'Admin kami cek dulu ya kak.'] as $fine) {
            $this->assertFalse(ReplySignals::internal($fine), $fine);
        }

        foreach (['ok', 'Oke kak', 'okee', 'siap', 'Siapp min', 'sebentar ya kak', 'ya', 'iya', '🙏', '👍👍', 'terima kasih', 'Makasih kak!', 'Ok deal', 'thanks'] as $ack) {
            $this->assertTrue(ReplySignals::acknowledgement($ack), $ack);
        }

        foreach (['ok berapa ongkirnya?', 'oke 2 ya', 'Palembang', 'COD', 'Berapa biayanya', 'mau ganti warna', 'admin'] as $question) {
            $this->assertFalse(ReplySignals::acknowledgement($question), $question);
        }
    }

    // ---- 3. Short replies never need a person ----------------------------------------------------------------------

    public function test_ok_deal_thanks_and_emoji_never_hand_over(): void
    {
        $this->buyer($this->manado());
        $this->ai = [
            $this->text('Siap kak, pesanannya kami catat. Mau pakai kurir JNE REG atau SiCepat REG?'),
            $this->text('HANDOFF'),
            $this->tool(ReplySignals::SILENT_TOOL),
            $this->tool(ReplySignals::HANDOFF_TOOL, ['alasan' => 'tidak ada pertanyaan']),
        ];

        $deal = $this->says('Ok deal');
        $thanks = $this->says('terima kasih kak');
        $emoji = $this->says('🙏');
        $wait = $this->says('sebentar ya');

        $this->assertSame(AiDraft::SENT, $this->draftFor($deal)->status);
        $this->assertSame(['Siap kak, pesanannya kami catat. Mau pakai kurir JNE REG atau SiCepat REG?'], $this->sent());
        $this->assertStringContainsString(GenerateAiReply::ACK_NOTE, $this->asked()[0]['system']);
        $this->assertNull($this->draftFor($thanks));
        $this->assertNull($this->draftFor($emoji));
        $this->assertNull($this->draftFor($wait));
        $this->assertSame(0, $this->handoffs());
    }

    // ---- 4. A handed-off question does not silence the next ones ---------------------------------------------------

    public function test_after_an_out_of_knowledge_handoff_the_next_answerable_question_is_answered_until_an_agent_replies(): void
    {
        $lead = $this->buyer($this->manado());
        $this->ai = [
            $this->tool(ReplySignals::HANDOFF_TOOL, ['alasan' => 'garansi tidak ada di pengetahuan']),
            $this->text('Ongkir ke Kota Manado: JNE REG Rp 62.000 (total Rp 240.000), SiCepat REG Rp 80.000 (total Rp 258.000), Kak.'),
        ];

        $warranty = $this->says('Garansinya berapa lama?');
        $shipping = $this->says('Ongkir ke Manado berapa?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($warranty)->status);
        $this->assertSame(AiDraft::SENT, $this->draftFor($shipping)->status);

        $request = $this->asked()[1];
        $this->assertStringContainsString(GenerateAiReply::HANDED_OFF_NOTE, $request['system']);
        $turns = $request['messages'];
        $this->assertSame(['role' => 'assistant', 'content' => GenerateAiReply::HANDED_OFF_TURN], $turns[count($turns) - 2]);
        $this->assertStringContainsString('Garansinya berapa lama?', $turns[count($turns) - 3]['content']);

        // Once an agent has answered by hand, the chat reads as before: no mark, no note.
        $this->inShop(function () use ($lead) {
            $conversation = $lead->conversation()->first();
            $conversation->messages()->create(['channel_id' => $this->channel->id, 'direction' => 'out', 'type' => 'text', 'body' => 'Garansi 30 hari kak.', 'status' => MessageStatus::Sent, 'sent_at' => now()]);
            $conversation->forceFill(['ai_paused_until' => null])->save();
        });
        $this->ai = [$this->tool(ReplySignals::HANDOFF_TOOL, ['alasan' => 'klaim garansi']), $this->text('Warnanya hitam dan putih, Kak.')];
        $this->says('Cara klaimnya gimana?');
        $this->says('Warnanya apa aja?');

        $request = $this->asked()[3];
        $this->assertStringContainsString(GenerateAiReply::HANDED_OFF_NOTE, $request['system'], 'A new handoff after the agent reply is marked again.');
        $marks = collect($request['messages'])->where('content', GenerateAiReply::HANDED_OFF_TURN)->count();
        $this->assertSame(1, $marks, 'Only the question after the agent reply is marked; the one the agent answered is not.');
    }

    // ---- 6. Photos of the variant ordered only ---------------------------------------------------------------------

    /** @return array{0: int, 1: int} Putih Army, Putih Merah */
    private function bagPhotos(): array
    {
        return $this->inShop(fn () => [
            MediaAsset::create(['folder_id' => MediaFolder::query()->value('id') ?? MediaFolder::create(['name' => 'Foto produk'])->id, 'title' => 'Tas Badminton Putih Army', 'kind' => 'link', 'url' => 'https://example.com/army.jpg', 'active' => true])->id,
            MediaAsset::create(['folder_id' => MediaFolder::query()->value('id'), 'title' => 'Tas Badminton Putih Merah', 'kind' => 'link', 'url' => 'https://example.com/merah.jpg', 'active' => true])->id,
        ]);
    }

    public function test_only_the_photo_of_the_ordered_variant_is_sent_and_none_with_a_summary(): void
    {
        // #444: the buyer ordered Putih Merah.
        [$army, $merah] = $this->bagPhotos();
        $this->buyer(['variant' => 'Putih Merah']);
        $this->channel->forceFill(['ai_mode' => AiMode::Draft])->save();
        $this->ai = [
            $this->text("Ini fotonya kak, warna Putih Merah.\nLAMPIRAN: {$army}, {$merah}"),
            $this->text("Ringkasan pesanan: Tas Badminton Putih Merah x2, total Rp 240.000. Mohon dikonfirmasi ya kak.\nLAMPIRAN: {$merah}"),
        ];

        $photo = $this->says('Boleh lihat fotonya kak?');
        $summary = $this->says('Mau kirim ke Manado ya');

        $this->assertSame([$merah], $this->draftFor($photo)->media_asset_ids);
        $this->assertNull($this->draftFor($summary)->media_asset_ids, 'No photo with an order summary the buyer did not ask a photo for.');
        $this->assertStringContainsString('jangan melampirkan foto saat merangkum atau mengonfirmasi pesanan', $this->asked()[0]['system']);
    }

    // ---- 7. "Ok" to a full summary records the order -------------------------------------------------------------

    /** The summary the chat sent last (by the AI or an agent). */
    private function summary(Lead $lead, string $body): void
    {
        $this->inShop(fn () => $lead->conversation()->first()->messages()->create([
            'channel_id' => $this->channel->id, 'direction' => 'out', 'type' => 'text', 'body' => $body, 'status' => MessageStatus::Sent, 'sent_at' => now(),
        ]));
    }

    private function stageOf(Lead $lead): ?string
    {
        return $this->inShop(fn () => $lead->fresh()->stage?->name);
    }

    private function recorded(Lead $lead): int
    {
        return $this->inShop(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('type', 'order_recorded')->count());
    }

    public function test_ok_to_a_full_cod_summary_records_the_order_and_moves_it_to_packing(): void
    {
        $lead = $this->buyer($this->manado() + ['payment_type' => 'cod', 'courier' => 'JNE REG']);
        $this->summary($lead, 'Ringkasan pesanan: Kaos Lari Hitam x2, kirim ke Jl. Sam Ratulangi No. 12, Kota Manado. Ongkir JNE REG Rp 62.000, total Rp 240.000, bayar di tempat (COD). Sudah sesuai kak?');
        $this->ai = [$this->text('Siap kak, pesanannya kami catat. Total Rp 240.000 dibayar saat paket diterima, segera kami kemas ya.')];

        $ok = $this->says('Ok');

        $this->assertSame(AiDraft::SENT, $this->draftFor($ok)->status);
        $this->assertSame(1, $this->recorded($lead));
        $this->assertSame('Dikemas & dikirim', $this->stageOf($lead));
        $this->assertSame('cod', $lead->fresh()->payment_type);
        $this->assertStringContainsString('PESANAN SUDAH DICATAT', $this->asked()[0]['system']);
        $this->assertSame(0, $this->handoffs());

        // A second "ok" records nothing more.
        $this->says('oke');
        $this->assertSame(1, $this->recorded($lead));
    }

    public function test_sesuai_to_a_transfer_summary_waits_for_payment_even_when_the_model_hands_over(): void
    {
        $lead = $this->buyer($this->manado());
        // The way of paying and the courier come from the summary itself.
        $this->summary($lead, 'Rincian pesanan kak: Kaos Lari Hitam x2 Rp 178.000 + ongkir SiCepat REG Rp 80.000 = total Rp 258.000. Pembayaran via transfer ya. Mohon konfirmasinya.');
        $this->ai = [$this->tool(ReplySignals::HANDOFF_TOOL, ['alasan' => 'tidak yakin'])];

        $this->says('Sesuai kak');

        $this->assertSame(1, $this->recorded($lead));
        $this->assertSame('Menunggu pembayaran', $this->stageOf($lead));
        $this->assertSame('transfer', $lead->fresh()->payment_type);
        $this->assertSame('SiCepat REG', $lead->fresh()->courier);
        $this->assertStringContainsString('Totalnya Rp 258.000', collect($this->sent())->last());
        $this->assertSame(0, $this->handoffs());
    }

    public function test_cod_named_only_in_the_summary_is_recorded_as_cod(): void
    {
        $lead = $this->buyer($this->manado() + ['courier' => 'JNE REG']);
        $this->summary($lead, 'Ringkasan pesanan: Kaos Lari Hitam x2, ongkir JNE REG Rp 62.000, total Rp 240.000, bayar di tempat (COD). Sudah sesuai kak?');
        $this->ai = [$this->text('Siap kak, pesanan kami catat dan segera dikemas.')];

        $this->says('Ok');

        $this->assertSame('cod', $lead->fresh()->payment_type);
        $this->assertSame('Dikemas & dikirim', $this->stageOf($lead));
    }

    public function test_ok_without_a_way_of_paying_asks_for_it_and_ok_without_a_summary_records_nothing(): void
    {
        $lead = $this->buyer($this->manado() + ['courier' => 'JNE REG']);
        $this->summary($lead, 'Ringkasan pesanan: Kaos Lari Hitam x2, ongkir JNE REG Rp 62.000, total Rp 240.000. Sudah sesuai kak?');
        $this->ai = [$this->text('HANDOFF')];

        $this->says('👍');

        $this->assertSame(0, $this->recorded($lead));
        $this->assertStringContainsString('cara bayar belum diketahui', $this->asked()[0]['system']);
        $this->assertSame('Siap kak. Bayarnya mau transfer atau COD (bayar di tempat)?', collect($this->sent())->last());
        $this->assertSame(0, $this->handoffs());

        // A plain "Ok" with no summary before it: nothing recorded, nothing handed over.
        $this->inShop(fn () => Lead::query()->first()->conversation()->first()->messages()->create([
            'channel_id' => $this->channel->id, 'direction' => 'out', 'type' => 'text', 'body' => 'Halo kak, ada yang bisa dibantu?', 'status' => MessageStatus::Sent, 'sent_at' => now(),
        ]));
        $this->ai = [$this->tool(ReplySignals::SILENT_TOOL)];
        $plain = $this->says('Ok');

        $this->assertNull($this->draftFor($plain));
        $this->assertSame(0, $this->recorded($lead));
        $this->assertSame(0, $this->handoffs());
        $this->assertStringNotContainsString('PESANAN SUDAH DICATAT', $this->asked()[1]['system']);
    }

    public function test_the_yes_words(): void
    {
        foreach (['ok', 'Oke kak', 'sesuai', 'Siap lanjut', 'ok deal', 'betul kak', '👍', 'iya benar'] as $yes) {
            $this->assertTrue(ReplySignals::confirmation($yes), $yes);
        }

        foreach (['terima kasih', '🙏', 'sebentar ya', 'ok sebentar', 'ok berapa?'] as $no) {
            $this->assertFalse(ReplySignals::confirmation($no), $no);
        }
    }
}
