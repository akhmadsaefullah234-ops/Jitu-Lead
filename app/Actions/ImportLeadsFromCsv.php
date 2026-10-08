<?php

namespace App\Actions;

use InvalidArgumentException;

/**
 * Reads a CSV of leads (as exported from Excel or Google Sheets) and registers
 * each row. Columns are recognised by header name, Indonesian or English.
 */
class ImportLeadsFromCsv
{
    public const MAX_ROWS = 2000;

    private const ALIASES = [
        'name' => ['name', 'nama', 'nama lengkap', 'nama lead'],
        'phone' => ['phone', 'telepon', 'telp', 'hp', 'no hp', 'no. hp', 'nomor hp', 'whatsapp', 'wa', 'no wa', 'nomor wa'],
        'email' => ['email', 'e-mail'],
        'note' => ['note', 'catatan', 'keterangan', 'pesan'],
    ];

    public function __construct(private RegisterLead $register) {}

    /**
     * @return array{created: int, duplicates: int, invalid: list<string>}
     */
    public function __invoke(string $path, string $sourceName, ?int $ownerId = null): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException('File tidak bisa dibaca.');
        }

        try {
            $first = fgets($handle) ?: '';
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $columns = $this->mapHeader(str_getcsv($first, $delimiter, '"', ''));

            if (! isset($columns['name']) || (! isset($columns['phone']) && ! isset($columns['email']))) {
                throw new InvalidArgumentException('Baris pertama harus berisi judul kolom: nama, dan telepon atau email.');
            }

            $result = ['created' => 0, 'duplicates' => 0, 'invalid' => []];
            $row = 1;

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $row++;

                if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                    continue;
                }

                if ($row - 1 > self::MAX_ROWS) {
                    throw new InvalidArgumentException('Maksimal '.self::MAX_ROWS.' baris per impor. Pecah file Anda.');
                }

                $data = [];
                foreach ($columns as $field => $index) {
                    $data[$field] = isset($cells[$index]) ? trim((string) $cells[$index]) : null;
                }

                if (blank($data['name'] ?? null) || (blank($data['phone'] ?? null) && blank($data['email'] ?? null))) {
                    $result['invalid'][] = "Baris {$row}: nama dan telepon/email wajib diisi";

                    continue;
                }

                if (filled($data['email'] ?? null) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $result['invalid'][] = "Baris {$row}: email tidak valid";

                    continue;
                }

                if (filled($data['phone'] ?? null) && strlen(preg_replace('/\D+/', '', $data['phone'])) < 8) {
                    $result['invalid'][] = "Baris {$row}: nomor telepon tidak valid";

                    continue;
                }

                [, $created] = ($this->register)($data, $sourceName, 'manual', $ownerId);
                $created ? $result['created']++ : $result['duplicates']++;
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $title) {
            $title = mb_strtolower(trim((string) $title));

            foreach (self::ALIASES as $field => $names) {
                if (in_array($title, $names, true) && ! isset($map[$field])) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }
}
