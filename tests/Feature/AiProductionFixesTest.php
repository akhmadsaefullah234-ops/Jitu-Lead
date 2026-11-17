<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Ai\AnswerMemory;
use App\Ai\OtherBot;
use App\Ai\PropertyGuard;
use App\Ai\ReplySignals;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Jobs\GenerateAiReplyJob;
use App\Mail\AiReplyFailed;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** PR 4: the fixes production showed were needed (numbers, promises, other bots, consistency, leaks, failures). */
class AiProductionFixesTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWLEDGE = 'Tipe Melati: Bangunan 38, Tanah 72, mulai 500 jutaan. Tipe Kenanga luas 36-45 m2. Promo bulan ini: gratis biaya KPR. Lokasi di Jl. Contoh Raya, Denpasar.';

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    /** @var list<array> answers the AI gives in order; afterwards */
    private array $ai = [];

    private string $answer = 'Baik Kak 😊';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->tenant = $this->makeTenant('agensi-uji');
        $this->tenant->forceFill(['name' => 'Agensi Uji', 'timezone' => 'Asia/Makassar'])->save();
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Nomor Uji', 'phone' => '+6289900000011',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Perumahan Contoh', 'content' => self::KNOWLEDGE]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->answer]]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n, 'ok' => true]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function says(string $text, string $from = '6289900001234'): WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($from), 'text', $text, null, CarbonImmutable::now(), 'Klien Uji',
        )));
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    private function draftFor(WaMessage $message): ?AiDraft
    {
        return $this->in(fn () => AiDraft::query()->where('message_id', $message->getKey())->first());
    }

    private function calls(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic')));
    }

    // ---- Pemeriksa angka -------------------------------------------------------------------------------------

    public function test_a_number_in_the_knowledge_grounds_the_answer_whatever_its_unit(): void
    {
        $sources = $this->in(fn () => PropertyGuard::sources());
        $problems = fn (string $answer) => PropertyGuard::problems($answer, 'Sekarang Senin, 16 November 2026', [], $sources);

        $this->assertSame([], $problems('Tipe Melati luas bangunan 38 m2, tanah 72 m² kak'));
        $this->assertNotSame([], $problems('Tipe Melati luas bangunan 50 m2 kak'));
        $this->assertNotSame([], $problems('Serah terima tahun 2038'), 'A year still has to be written as a year');
    }

    public function test_a_range_must_be_in_the_sources_as_a_range(): void
    {
        $sources = $this->in(fn () => PropertyGuard::sources());
        $problems = fn (string $answer) => PropertyGuard::problems($answer, '', [], $sources);

        $this->assertSame([], $problems('Tipe Kenanga luasnya 36-45 m2 kak'));
        $this->assertNotSame([], $problems('Tipe Kenanga luasnya 36-72 m2 kak'));
        $this->assertSame([], $problems('Hubungi kami jam 9-17 ya kak'), 'A range without money or a unit is left alone');
    }

    public function test_a_forbidden_word_the_knowledge_states_is_a_fact_not_a_promise(): void
    {
        $sources = $this->in(fn () => PropertyGuard::sources());

        $this->assertSame([], PropertyGuard::problems('Promo bulan ini gratis biaya KPR kak 😊', '', ['gratis'], $sources));
        $this->assertNotSame([], PropertyGuard::problems('Promo bulan ini gratis biaya KPR kak 😊', '', ['gratis']), 'Without the knowledge it is still held');
        $this->assertNotSame([], PropertyGuard::problems('Unitnya siap huni kak', '', ['siap huni'], $sources));
    }

    // ---- Bot lain ---------------------------------------------------------------------------------------------

    public function test_another_businesss_automatic_reply_is_not_answered_and_the_chat_pauses(): void
    {
        $this->tenant->forceFill(['ai_settings' => ['handoff_pause' => 30]])->save();
        $message = $this->says('Terima kasih telah menghubungi Toko Contoh. Ini pesan otomatis, ketik 1 untuk katalog.');

        $this->assertSame([], $this->sent());
        $this->assertSame(0, $this->calls(), 'The AI is not even asked');
        $draft = $this->draftFor($message);
        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertSame(OtherBot::REASON, $draft->reason);
        $this->assertTrue($this->in(fn () => WaConversation::query()->first()->ai_paused_until->greaterThan(now()->addHours(23))), 'Paused for a day');
        $this->assertStringContainsString('bisnis lain', $this->in(fn () => Lead::query()->first()->activities()->where('type', 'ai_handoff')->value('body')));
    }

    public function test_instant_replies_to_our_automatic_replies_three_times_stop_the_ai(): void
    {
        $this->says('Halo kak, mau tanya rumah');

        foreach (['Menu utama: 1 info lengkap', 'Menu utama: 2 info harga', 'Menu utama: 3 info lokasi'] as $text) {
            Carbon::setTestNow(now()->addSeconds(2));
            $last = $this->says($text);
        }

        $this->assertCount(3, $this->sent(), 'The first three are answered; the third instant reply is not');
        $this->assertSame(GenerateAiReply::AI_ORIGIN, $this->in(fn () => WaMessage::query()->where('direction', 'out')->value('origin')));
        $this->assertSame(OtherBot::REASON, $this->draftFor($last)->reason);
    }

    public function test_a_person_writing_at_a_normal_pace_is_answered(): void
    {
        foreach (['Halo kak', 'Mau tanya rumah', 'Tipe Melati masih ada?', 'Lokasinya di mana?'] as $text) {
            Carbon::setTestNow(now()->addSeconds(40));
            $this->says($text);
        }

        $this->assertSame([], $this->in(fn () => AiDraft::query()->where('status', '!=', AiDraft::SENT)->pluck('reason')->all()));
        $this->assertCount(4, $this->sent());
    }

    public function test_quick_short_answers_from_a_person_are_answered(): void
    {
        foreach (['Halo kak', 'ok kak', 'iya', 'boleh', 'siap kak'] as $text) {
            Carbon::setTestNow(now()->addSeconds(2));
            $this->says($text);
        }

        $this->assertCount(5, $this->sent());
    }

    public function test_draft_mode_has_no_bot_check(): void
    {
        $this->channel->forceFill(['ai_mode' => AiMode::Draft])->save();

        $message = $this->says('Terima kasih telah menghubungi kami, ini pesan otomatis.');

        $this->assertSame(AiDraft::PENDING, $this->draftFor($message)->status);
    }

    // ---- Konsisten --------------------------------------------------------------------------------------------

    public function test_a_question_answered_before_is_answered_again_instead_of_handed_off(): void
    {
        $this->answer = 'Tipe Melati mulai 500 jutaan kak 😊';
        $this->says('Harga tipe Melati berapa kak?');

        $this->ai = [['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => ReplySignals::HANDOFF_TOOL, 'input' => ['alasan' => 'tidak yakin']]]]];
        $message = $this->says('harga tipe melati berapa kak??', '6289900005678');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertSame(['Tipe Melati mulai 500 jutaan kak 😊', 'Tipe Melati mulai 500 jutaan kak 😊'], $this->sent());
        $retry = Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['system'];
        $this->assertStringContainsString('Pertanyaan yang sama pernah kamu jawab', is_string($retry) ? $retry : json_encode($retry));
    }

    public function test_the_memory_starts_afresh_when_the_knowledge_changes(): void
    {
        $this->in(fn () => AnswerMemory::remember($this->tenant, 'Harga tipe Melati berapa?', 'Mulai 500 jutaan'));
        $this->assertSame('Mulai 500 jutaan', $this->in(fn () => AnswerMemory::previous($this->tenant, 'harga tipe melati berapa')));

        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Harga baru', 'content' => 'Tipe Melati mulai 550 jutaan.']));

        $this->assertNull($this->in(fn () => AnswerMemory::previous($this->tenant, 'Harga tipe Melati berapa?')));
        $this->assertNull($this->in(fn () => AnswerMemory::previous($this->tenant, 'ok')), 'Short texts are never remembered');
    }

    public function test_a_question_never_answered_is_still_handed_off(): void
    {
        $this->ai = [['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => ReplySignals::HANDOFF_TOOL, 'input' => ['alasan' => 'notaris']]]]];
        $message = $this->says('Ada kolam renang di perumahannya kak?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertSame(1, $this->calls());
    }

    // ---- Kebocoran kata internal --------------------------------------------------------------------------------

    public function test_internal_words_never_reach_a_client(): void
    {
        $this->assertTrue(ReplySignals::internal('Sesuai PENGETAHUAN, harganya 500 juta kak'));
        $this->assertTrue(ReplySignals::internal("DATA KLIEN: nama belum ada\nHalo kak"));
        $this->assertTrue(ReplySignals::internal('Saya panggil serahkan_ke_admin dulu ya kak'));
        $this->assertTrue(ReplySignals::internal('Menurut PLAYBOOK PROPERTI saya harus bertanya'));
        $this->assertFalse(ReplySignals::internal('Pengetahuan kami soal KPR terbatas kak, nanti agen bantu ya'));
        $this->assertFalse(ReplySignals::internal('MEMORI: 128GB, CATATAN: garansi resmi'), 'A shop\'s own capitals are left alone');
    }

    public function test_an_answer_that_keeps_leaking_goes_to_an_agent(): void
    {
        $this->answer = 'Menurut PENGETAHUAN, tipe Melati mulai 500 jutaan kak';
        $message = $this->says('Harga tipe Melati berapa kak?');

        $this->assertSame([], $this->sent());
        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
    }

    // ---- Kegagalan berujung ke agen -----------------------------------------------------------------------------

    public function test_a_failed_job_hands_the_chat_to_an_agent_and_emails_the_admin(): void
    {
        Mail::fake();
        Queue::fake();
        $message = $this->says('Harga tipe Melati berapa kak?');

        $job = new GenerateAiReplyJob($this->tenant->getKey(), $message->getKey());
        $job->failed(new RuntimeException('timeout'));
        $job->failed(new RuntimeException('timeout'));

        $draft = $this->draftFor($message);
        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertStringContainsString('AI gagal menjawab', $draft->reason);
        $this->assertSame(1, $this->in(fn () => AiDraft::query()->count()), 'A second failure adds nothing');
        $this->assertSame(1, $this->in(fn () => Lead::query()->first()->activities()->where('type', 'ai_handoff')->count()));
        Mail::assertSent(AiReplyFailed::class, fn (AiReplyFailed $m) => $m->hasTo($this->tenant->users()->first()->email));
    }

    public function test_a_failed_job_after_a_reply_changes_nothing(): void
    {
        Mail::fake();
        $message = $this->says('Harga tipe Melati berapa kak?');

        (new GenerateAiReplyJob($this->tenant->getKey(), $message->getKey()))->failed(new RuntimeException('late'));

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        Mail::assertNothingSent();
    }
}
