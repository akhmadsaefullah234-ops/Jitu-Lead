<?php

namespace App\Ai\Facts;

use App\Ai\AnthropicClient;
use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Models\AiFact;

/**
 * Reads a brochure, price list or FAQ (PDF, Word, Excel, text, or a photo) into facts that wait for the owner's
 * review: nothing a document says reaches the AI before the owner approves it. Each fact keeps the file it came from.
 * A value the agency already has (approved or waiting) is not asked again. Must run inside the tenant.
 */
final class FactExtractor
{
    public const MAX_CHARS = 30000;

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const TOOL = 'catat_data_properti';

    private const SYSTEM = 'Kamu membaca dokumen properti (brosur, daftar harga, FAQ) milik agensi dan menyalin datanya ke alat '.self::TOOL.'. '
        .'Salin hanya yang tertulis jelas di dokumen; kosongkan yang tidak ada. Jangan menebak, menghitung, atau membulatkan. '
        .'Tulis harga lengkap dengan satuannya seperti di dokumen (mis. "Rp642.850.000" atau "642,85 juta"), dan luas dengan angkanya saja (mis. "38"). '
        .'Satu tipe unit satu baris di "types". Abaikan data pribadi klien.';

    public function __construct(private DocumentReader $reader, private AnthropicClient $ai) {}

    /**
     * @return array{added: int, same: int}
     *
     * @throws DocumentException
     */
    public function __invoke(string $path, string $originalName): array
    {
        if (! $this->ai->configured()) {
            throw new DocumentException('Layanan AI belum diaktifkan di server ini, jadi dokumen belum bisa dibaca otomatis. Isi datanya lewat wizard.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $content = in_array($extension, DocumentReader::IMAGES, true) ? $this->image($path, $extension) : [[
            'type' => 'text', 'text' => "DOKUMEN ({$originalName}):\n".mb_substr($this->reader->read($path, $extension), 0, self::MAX_CHARS),
        ]];

        $input = $this->ai->structured(self::SYSTEM, $content, self::tool());

        if ($input === null) {
            throw new DocumentException('AI tidak bisa membaca data dari dokumen ini. Coba file lain atau isi lewat wizard.');
        }

        return $this->save($input, mb_substr($originalName, 0, 200));
    }

    /** @return list<array<string, mixed>> */
    private function image(string $path, string $extension): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_IMAGE_BYTES) {
            throw new DocumentException('Gambar terlalu besar. Maksimal 5 MB.');
        }

        return [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $extension === 'jpg' ? 'image/jpeg' : 'image/'.$extension, 'data' => base64_encode((string) file_get_contents($path))]],
            ['type' => 'text', 'text' => 'Salin data properti dari gambar ini.'],
        ];
    }

    /** @return array{added: int, same: int} */
    private function save(array $input, string $source): array
    {
        // Approved or already waiting for review: the same value is not asked twice.
        $approved = AiFact::query()->whereIn('status', [AiFact::APPROVED, AiFact::PENDING])->get();
        $added = 0;
        $same = 0;
        $rows = [];

        foreach (FactKeys::fromDocuments() as $key => $_) {
            $rows[] = [null, $key, $input['project'][$key] ?? null];
        }

        foreach (array_slice(is_array($input['types'] ?? null) ? $input['types'] : [], 0, 30) as $type) {
            $name = is_array($type) ? trim((string) ($type['name'] ?? '')) : '';

            foreach ($name === '' ? [] : FactKeys::TYPE as $key => $_) {
                $rows[] = [mb_substr($name, 0, 120), $key, $type[$key] ?? null];
            }
        }

        foreach ($rows as [$subject, $key, $raw]) {
            if (! is_scalar($raw) || ($value = FactFormat::clean($key, $raw)) === null) {
                continue;
            }

            $known = $approved->contains(fn (AiFact $f) => $f->key === $key && Facts::subjectKey($f->subject) === Facts::subjectKey($subject)
                && FactFormat::comparable($key, $f->value) === FactFormat::comparable($key, $value));

            if ($known) {
                $same++;

                continue;
            }

            AiFact::query()->create(['subject' => $subject, 'key' => $key, 'value' => $value, 'source' => AiFact::DOCUMENT,
                'source_name' => $source, 'status' => AiFact::PENDING]);
            $added++;
        }

        return ['added' => $added, 'same' => $same];
    }

    /** @return array{name: string, description: string, input_schema: array} */
    public static function tool(): array
    {
        $fields = fn (array $keys) => collect($keys)->map(fn (array $def) => ['type' => 'string', 'description' => $def[0]])->all();

        return [
            'name' => self::TOOL,
            'description' => 'Catat data proyek dan tipe unit yang tertulis di dokumen.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'project' => ['type' => 'object', 'properties' => $fields(FactKeys::fromDocuments())],
                    'types' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => 'Nama tipe, mis. Melati']] + $fields(FactKeys::TYPE)]],
                ],
            ],
        ];
    }
}
