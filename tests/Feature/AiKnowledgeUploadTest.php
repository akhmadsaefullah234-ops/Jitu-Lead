<?php

namespace Tests\Feature;

use App\Actions\ImportKnowledgeDocument;
use App\Ai\Defaults;
use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Ai\HandoffRules;
use App\Ai\TextChunker;
use App\Enums\AiMode;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\AiModes;
use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Models\AiKnowledgeItem;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class AiKnowledgeUploadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function file(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('kb').'-'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function docx(string $bodyXml): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('kb').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'.$bodyXml.'</w:body></w:document>');
        $zip->close();

        return $path;
    }

    private function pdf(string $text): string
    {
        $stream = "BT /F1 12 Tf 72 720 Td ($text) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        return $this->file('x.pdf', $pdf);
    }

    public function test_reads_text_word_and_pdf(): void
    {
        $reader = app(DocumentReader::class);

        $this->assertSame("Harga mulai 450 juta.\n\nDP 10 persen.", $reader->read($this->file('a.txt', "\xEF\xBB\xBFHarga mulai 450 juta.\r\n\r\n\r\n\r\nDP 10 persen.  \n"), 'txt'));

        $docx = $this->docx('<w:p><w:r><w:t>Cluster Mawar &amp; Melati</w:t></w:r></w:p><w:p><w:r><w:t>Harga 450 juta</w:t></w:r></w:p>');
        $this->assertSame("Cluster Mawar & Melati\nHarga 450 juta", $reader->read($docx, 'docx'));

        $this->assertStringContainsString('Harga Cluster Mawar', $reader->read($this->pdf('Harga Cluster Mawar 450 juta'), 'pdf'));
    }

    public function test_unreadable_documents_say_why(): void
    {
        $reader = app(DocumentReader::class);

        foreach ([
            ['x.exe', 'exe', 'tidak didukung'],
            ['x.txt', 'txt', 'kosong'],
            ['x.pdf', 'pdf', 'tidak bisa dibaca'],
            ['x.docx', 'docx', 'tidak bisa dibuka'],
        ] as [$name, $ext, $message]) {
            try {
                $reader->read($this->file($name, $ext === 'txt' ? "  \n" : 'bukan dokumen'), $ext);
                $this->fail("$name should be rejected");
            } catch (DocumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
            }
        }

        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage('5 MB');
        $reader->read($this->file('big.txt', str_repeat('a', DocumentReader::MAX_BYTES + 1)), 'txt');
    }

    public function test_a_pdf_without_text_is_reported_as_a_scan(): void
    {
        try {
            app(DocumentReader::class)->read($this->pdf(''), 'pdf');
            $this->fail('Empty pdf should be rejected');
        } catch (DocumentException $e) {
            $this->assertStringContainsString('hasil scan', $e->getMessage());
        }
    }

    public function test_chunker_keeps_everything_and_respects_the_size(): void
    {
        $text = collect(range(1, 40))->map(fn ($i) => "Paragraf $i ".str_repeat('kata ', 60))->implode("\n\n");
        $chunks = (new TextChunker(1000))->chunk($text);

        $this->assertGreaterThan(5, count($chunks));
        $this->assertTrue(collect($chunks)->every(fn ($c) => mb_strlen($c) <= 1000));
        $this->assertSame(40, preg_match_all('/Paragraf \d+/', implode("\n\n", $chunks)));

        $long = (new TextChunker(100))->chunk(str_repeat('x', 250));
        $this->assertSame([100, 100, 50], array_map('mb_strlen', $long));
    }

    public function test_a_long_document_becomes_titled_parts(): void
    {
        $text = collect(range(1, 12))->map(fn ($i) => "Bagian $i ".str_repeat('isi ', 200))->implode("\n\n");

        $result = $this->inTenant(fn () => app(ImportKnowledgeDocument::class)($this->file('brosur.txt', $text), 'Brosur Mawar.txt'));
        $items = $this->inTenant(fn () => AiKnowledgeItem::orderBy('id')->get());

        $this->assertSame(['items' => $items->count(), 'truncated' => false], $result);
        $this->assertGreaterThan(1, $items->count());
        $this->assertSame('Brosur Mawar (bagian 1/'.$items->count().')', $items->first()->title);
        $this->assertTrue($items->every(fn ($i) => $i->source === 'upload' && $i->active && mb_strlen($i->content) <= 3000));
    }

    public function test_a_question_answer_csv_becomes_one_entry_per_row(): void
    {
        $csv = "pertanyaan;jawaban\nBerapa DP?;DP 10 persen\nApakah bisa KPR?;Bisa, bank BTN\n;tanpa pertanyaan\n";

        $result = $this->inTenant(fn () => app(ImportKnowledgeDocument::class)($this->file('qa.csv', $csv), 'qa.csv'));
        $items = $this->inTenant(fn () => AiKnowledgeItem::orderBy('id')->get());

        $this->assertSame(2, $result['items']);
        $this->assertSame(['Berapa DP?', 'DP 10 persen'], [$items[0]->question, $items[0]->content]);
    }

    public function test_the_shipped_templates_import_cleanly(): void
    {
        foreach (array_keys(ManageAiKnowledge::TEMPLATES) as $file) {
            $path = resource_path("ai-templates/$file");
            $this->assertFileExists($path);
            $this->inTenant(fn () => app(ImportKnowledgeDocument::class)($path, $file));
        }

        $this->assertGreaterThan(4, $this->inTenant(fn () => AiKnowledgeItem::count()));
        $this->assertSame(4, $this->inTenant(fn () => AiKnowledgeItem::whereNotNull('question')->count()));
    }

    public function test_admin_uploads_a_document_and_downloads_a_template_from_the_page(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageAiKnowledge::class)
            ->callAction('upload', ['file' => UploadedFile::fake()->createWithContent('harga.txt', 'Cluster Mawar mulai 450 juta, DP 10 persen.'), 'title' => 'Harga Mawar'])
            ->assertHasNoActionErrors()
            ->assertNotified('1 catatan ditambahkan');

        $item = $this->inTenant(fn () => AiKnowledgeItem::first());
        $this->assertSame('Harga Mawar', $item->title);
        $this->assertStringContainsString('450 juta', $item->content);

        Livewire::test(ManageAiKnowledge::class)
            ->callAction('upload', ['file' => UploadedFile::fake()->createWithContent('kosong.txt', "  \n")])
            ->assertNotified('Dokumen belum bisa dipakai');
        $this->assertSame(1, $this->inTenant(fn () => AiKnowledgeItem::count()));
    }

    public function test_new_agencies_start_with_ready_to_use_instructions_and_handoff_words(): void
    {
        $this->assertSame(Defaults::INSTRUCTIONS, $this->tenant->fresh()->ai_instructions);
        $this->assertSame(Defaults::keywordText(), $this->tenant->fresh()->ai_handoff_keywords);
    }

    public function test_the_agencys_handoff_list_replaces_the_defaults_and_blank_restores_them(): void
    {
        $rules = new HandoffRules;

        $this->assertTrue($rules->matches('Boleh nego?', null));
        $this->assertTrue($rules->matches('Boleh nego?', '  '));
        $this->assertTrue($rules->matches('Ada cicilan syariah?', 'wakaf, cicilan syariah'));
        $this->assertFalse($rules->matches('Boleh nego?', 'wakaf, cicilan syariah'), 'The agency removed "nego" from its list');
    }

    public function test_advanced_instructions_can_be_changed(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageAiKnowledge::class)
            ->callAction('settings', ['ai_instructions' => 'Sapa dengan Bapak/Ibu', 'ai_handoff_keywords' => 'wakaf'])
            ->assertHasNoActionErrors();
        $this->assertSame('Sapa dengan Bapak/Ibu', $this->tenant->fresh()->ai_instructions);
    }

    public function test_admin_switches_a_number_between_manual_draft_and_automatic(): void
    {
        $channel = $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW 1', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $this->assertSame(AiMode::Off, $channel->fresh()->ai_mode, 'New numbers start in manual mode');

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(AiModes::class)->assertSee('GW 1')
            ->call('updateTableColumnState', 'ai_mode', $channel->getKey(), 'auto');

        $this->assertSame(AiMode::Auto, $channel->fresh()->ai_mode);

        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        $this->assertFalse(AiModes::canAccess());
    }
}
