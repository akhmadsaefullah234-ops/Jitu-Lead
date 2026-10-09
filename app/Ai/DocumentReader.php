<?php

namespace App\Ai;

use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Turns an uploaded document into plain text the assistant can use.
 * Reads PDF (with a text layer), Word (.docx), and plain text/Markdown/CSV.
 * Scanned pages and images carry no text and are reported, not guessed at.
 */
class DocumentReader
{
    public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_XML_BYTES = 20 * 1024 * 1024;

    /** @throws DocumentException */
    public function read(string $path, string $extension): string
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new DocumentException('Jenis file tidak didukung. Gunakan PDF, DOCX, TXT, MD, atau CSV.');
        }

        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new DocumentException('File terlalu besar. Maksimal 5 MB.');
        }

        $text = match ($extension) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            default => $this->plain($path),
        };

        $text = $this->tidy($text);

        if ($text === '') {
            throw new DocumentException($extension === 'pdf'
                ? 'PDF ini tidak berisi teks yang bisa dibaca (kemungkinan hasil scan atau gambar). Gunakan PDF yang teksnya bisa disalin, atau salin isinya ke template.'
                : 'Dokumen ini kosong.');
        }

        return $text;
    }

    private function pdf(string $path): string
    {
        try {
            return (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            throw new DocumentException('PDF tidak bisa dibaca. Pastikan file tidak rusak atau diberi kata sandi.');
        }
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new DocumentException('File DOCX tidak bisa dibuka. Pastikan formatnya .docx, bukan .doc lama.');
        }

        try {
            $stat = $zip->statName('word/document.xml');

            if ($stat === false) {
                throw new DocumentException('File DOCX ini tidak berisi dokumen Word yang valid.');
            }

            if ($stat['size'] > self::MAX_XML_BYTES) {
                throw new DocumentException('Dokumen terlalu besar untuk dibaca.');
            }

            $xml = (string) $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        $xml = preg_replace(['#</w:p>#', '#<w:br\s*/?>#', '#<w:tab\s*/?>#', '#</w:tc>#'], ["\n", "\n", "\t", ' | '], $xml);

        return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function plain(string $path): string
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim((string) $text);
    }
}
