<?php

namespace App\Media;

/** What the media library accepts. Files are sent to customers over WhatsApp, so the list is kept short. */
final class Library
{
    /** Folders every agency starts with. Agencies can add, rename and delete their own. */
    public const FOLDERS = ['Price list', 'Foto rumah', 'Denah', 'Link Google Maps', 'Brosur', 'Lainnya'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** extension => [mime types a real file of that kind may be detected as, WhatsApp message type] */
    public const TYPES = [
        'jpg' => [['image/jpeg'], 'image'],
        'jpeg' => [['image/jpeg'], 'image'],
        'png' => [['image/png'], 'image'],
        'pdf' => [['application/pdf'], 'document'],
        'doc' => [['application/msword', 'application/octet-stream', 'application/x-ole-storage'], 'document'],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'document'],
        'xls' => [['application/vnd.ms-excel', 'application/msword', 'application/octet-stream', 'application/x-ole-storage'], 'document'],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], 'document'],
        'ppt' => [['application/vnd.ms-powerpoint', 'application/msword', 'application/octet-stream', 'application/x-ole-storage'], 'document'],
        'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'], 'document'],
        'csv' => [['text/csv', 'text/plain', 'application/csv'], 'document'],
        'txt' => [['text/plain'], 'document'],
    ];

    /** The mime type sent to WhatsApp for an extension. */
    public const SEND_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv', 'txt' => 'text/plain',
    ];

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** Whether a file with this name and detected content type may be stored and sent. */
    public static function accepts(string $name, ?string $detectedMime): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return isset(self::TYPES[$extension]) && in_array((string) $detectedMime, self::TYPES[$extension][0], true);
    }

    public static function whatsAppType(string $name): string
    {
        return self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))][1] ?? 'document';
    }

    public static function sendMime(string $name): string
    {
        return self::SEND_MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
