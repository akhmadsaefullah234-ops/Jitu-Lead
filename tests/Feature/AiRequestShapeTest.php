<?php

namespace Tests\Feature;

use App\Ai\AnthropicClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Requests the Messages API would refuse with a 400 (28-11-2026, "layanan AI menolak permintaan (kode 400)" on a
 * Quicker Sport chat): a tool call's empty input sent back as a JSON list, empty text blocks, blank turns, a tool
 * result that json_encode could not write.
 */
class AiRequestShapeTest extends TestCase
{
    /** @var list<array> responses the fake API gives, in order */
    private array $api = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => fn () => Http::response(array_shift($this->api) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Siap kak']]])]);
    }

    /** @return list<string> the raw JSON body of every request to the model */
    private function bodies(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->map(fn ($pair) => $pair[0]->body())->values()->all();
    }

    private function converse(callable $run): array
    {
        $tool = ['name' => 'lihat_pesanan', 'description' => 'Pesanan pembeli.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass]];

        return app(AnthropicClient::class)->converse('Sistem', [['role' => 'user', 'content' => 'COD kak']], [$tool], $run);
    }

    public function test_a_tool_call_with_empty_input_goes_back_as_an_object_and_empty_text_is_left_out(): void
    {
        $this->api = [['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'text', 'text' => ''],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lihat_pesanan', 'input' => new \stdClass],
        ]]];

        $out = $this->converse(fn () => ['status' => 'ok']);

        $this->assertSame('Siap kak', $out['text']);
        $second = json_decode($this->bodies()[1], true, flags: JSON_OBJECT_AS_ARRAY);
        $this->assertStringContainsString('"input":{}', $this->bodies()[1]);
        $this->assertStringNotContainsString('"input":[]', $this->bodies()[1]);
        $this->assertSame([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lihat_pesanan', 'input' => []]], $second['messages'][1]['content']);
    }

    public function test_a_tool_result_json_cannot_write_is_still_text(): void
    {
        $this->api = [['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lihat_pesanan', 'input' => ['x' => 1]]]]];

        $this->converse(fn () => ['alamat' => "Jl. Mawar \xB1 RT 02", 'ongkir' => NAN]);

        $result = json_decode($this->bodies()[1], true)['messages'][2]['content'][0];
        $this->assertSame('tool_result', $result['type']);
        $this->assertIsString($result['content']);
        $this->assertStringContainsString('Jl. Mawar', $result['content']);
    }

    public function test_bad_utf8_in_the_chat_never_breaks_the_request(): void
    {
        $text = app(AnthropicClient::class)->reply("Sistem \xC3", [['role' => 'user', 'content' => "COD kak \xF0\x9F"]]);

        $this->assertSame('Siap kak', $text);
        $body = json_decode($this->bodies()[0], true);
        $this->assertNotNull($body);
        $this->assertStringStartsWith('COD kak', $body['messages'][0]['content']);
    }

    public function test_blank_turns_are_left_out_and_the_rest_still_alternate(): void
    {
        $this->assertSame([
            ['role' => 'user', 'content' => 'Halo'],
            ['role' => 'assistant', 'content' => "Total 215.000\nCOD atau transfer?"],
            ['role' => 'user', 'content' => 'COD kak'],
        ], AnthropicClient::normalize([
            ['role' => 'user', 'content' => 'Halo'],
            ['role' => 'assistant', 'content' => 'Total 215.000'],
            ['role' => 'user', 'content' => "\u{3164}"],
            ['role' => 'assistant', 'content' => 'COD atau transfer?'],
            ['role' => 'user', 'content' => "\u{200B} \u{00A0}"],
            ['role' => 'user', 'content' => 'COD kak'],
            ['role' => 'assistant', 'content' => "\u{2800}"],
        ]));
    }
}
