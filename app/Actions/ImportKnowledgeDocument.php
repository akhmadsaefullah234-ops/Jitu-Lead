<?php

namespace App\Actions;

use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Ai\TextChunker;
use App\Models\AiKnowledgeItem;
use Illuminate\Support\Facades\DB;

/**
 * Adds an uploaded document to the agency's knowledge. A question-and-answer
 * CSV becomes one entry per row; any other document is cut into readable parts.
 */
class ImportKnowledgeDocument
{
    public const MAX_CHARS = 60000;

    public const MAX_QA_ROWS = 500;

    public function __construct(private DocumentReader $reader, private TextChunker $chunker) {}

    /**
     * @return array{items: int, truncated: bool}
     *
     * @throws DocumentException
     */
    public function __invoke(string $path, string $originalName, ?string $title = null): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $text = $this->reader->read($path, $extension);
        $name = trim($title ?: pathinfo($originalName, PATHINFO_FILENAME));

        if ($extension === 'csv' && ($rows = $this->questionRows($text)) !== null) {
            return $this->saveRows($rows);
        }

        $truncated = mb_strlen($text) > self::MAX_CHARS;
        $parts = $this->chunker->chunk(mb_substr($text, 0, self::MAX_CHARS));
        $total = count($parts);

        DB::transaction(function () use ($parts, $total, $name) {
            foreach ($parts as $i => $part) {
                AiKnowledgeItem::query()->create([
                    'title' => mb_substr($total > 1 ? sprintf('%s (bagian %d/%d)', $name, $i + 1, $total) : $name, 0, 120),
                    'content' => $part,
                    'source' => 'upload',
                    'active' => true,
                ]);
            }
        });

        return ['items' => $total, 'truncated' => $truncated];
    }

    /** @return list<array{string, string}>|null Null when the file has no pertanyaan/jawaban columns. */
    private function questionRows(string $text): ?array
    {
        $first = strtok($text, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $lines = array_map(fn ($l) => str_getcsv($l, $delimiter, '"', ''), preg_split('/\n/', $text) ?: []);

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($lines) ?? []);
        $q = array_search('pertanyaan', $header, true);
        $a = array_search('jawaban', $header, true);

        if ($q === false || $a === false) {
            return null;
        }

        $rows = [];

        foreach ($lines as $line) {
            $question = trim((string) ($line[$q] ?? ''));
            $answer = trim((string) ($line[$a] ?? ''));

            if ($question !== '' && $answer !== '') {
                $rows[] = [$question, $answer];
            }
        }

        return array_slice($rows, 0, self::MAX_QA_ROWS);
    }

    /** @param  list<array{string, string}>  $rows */
    private function saveRows(array $rows): array
    {
        if ($rows === []) {
            throw new DocumentException('Tidak ada baris yang terisi. Isi kolom pertanyaan dan jawaban.');
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as [$question, $answer]) {
                AiKnowledgeItem::query()->create([
                    'title' => mb_substr($question, 0, 120),
                    'question' => mb_substr($question, 0, 500),
                    'content' => mb_substr($answer, 0, 4000),
                    'source' => 'upload',
                    'active' => true,
                ]);
            }
        });

        return ['items' => count($rows), 'truncated' => false];
    }
}
