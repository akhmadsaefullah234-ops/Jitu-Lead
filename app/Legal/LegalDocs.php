<?php

namespace App\Legal;

use App\LandingPages\RichText;
use App\Models\LegalDocument;
use Carbon\Carbon;

/** The privacy policy and terms of service: default text from config/legal.php, or the owner's edited version. */
class LegalDocs
{
    public const SLUGS = ['kebijakan-privasi', 'syarat-layanan'];

    public static function exists(string $slug): bool
    {
        return in_array($slug, self::SLUGS, true);
    }

    public static function url(string $slug): string
    {
        return url('/'.$slug);
    }

    /** @return array{title: string, intro: ?string, html: string, reviewed: bool, updated: Carbon, custom: bool} */
    public static function get(string $slug): array
    {
        $default = config("legal.documents.$slug");
        $row = LegalDocument::query()->where('slug', $slug)->first();
        $custom = $row !== null && filled($row->body);

        return [
            'title' => $row?->title ?: $default['title'],
            'intro' => $custom ? null : self::fill($default['intro'] ?? ''),
            'html' => $custom ? self::fill(self::normalize($row->body), escape: true) : self::defaultHtml($slug),
            'reviewed' => $row?->reviewed_at !== null,
            'updated' => $custom ? $row->updated_at : Carbon::parse(config('legal.updated')),
            'custom' => $custom,
        ];
    }

    /** Sanitised HTML with the editor's quirk removed (it wraps every list item in a paragraph), so two texts compare and render alike. */
    public static function normalize(string|array|null $html): string
    {
        return preg_replace('#<li><p>(.*?)</p></li>#s', '<li>$1</li>', RichText::clean($html, headings: true));
    }

    /** The built-in text as HTML (what the editor starts from, too). */
    public static function defaultHtml(string $slug): string
    {
        $html = '';

        foreach (config("legal.documents.$slug.sections") as [$heading, $blocks]) {
            $html .= '<h2>'.e(self::fill($heading)).'</h2>';

            foreach ($blocks as [$kind, $content]) {
                $html .= $kind === 'ul'
                    ? '<ul>'.collect($content)->map(fn ($li) => '<li>'.e(self::fill($li)).'</li>')->implode('').'</ul>'
                    : '<p>'.e(self::fill($content)).'</p>';
            }
        }

        return $html;
    }

    /** Replaces {placeholders}. Values are plain text; $escape HTML-escapes them for text that is already HTML. */
    public static function fill(string $text, bool $escape = false): string
    {
        $contact = config('legal.contact') ?: 'kontak pengelola yang tertera di aplikasi';
        $address = config('legal.address');
        $values = [
            '{aplikasi}' => 'JITU LEAD',
            '{perusahaan}' => config('legal.company'),
            '{kontak}' => $contact,
            '{alamat}' => $address ? ", alamat: $address" : '',
            '{backup_hari}' => (string) config('jitu.backup.keep_days'),
            '{backup_luar_hari}' => (string) config('jitu.backup.remote_keep_days'),
            '{tanggal}' => Carbon::parse(config('legal.updated'))->translatedFormat('j F Y'),
        ];

        return strtr($text, $escape ? array_map('e', $values) : $values);
    }
}
