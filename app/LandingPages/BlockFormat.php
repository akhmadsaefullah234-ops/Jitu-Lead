<?php

namespace App\LandingPages;

/**
 * The stored shape of a page, one entry per section:
 *
 *     {"type": "hero", "version": 1, "props": {...}}
 *
 * `props` also carries two shared switches: `hidden` and `bg`. The editor
 * (Filament Builder) works on {type, data} items; this class is the single
 * place that converts between the two, and also reads the older {type, data}
 * rows that were saved before the format had a version.
 */
class BlockFormat
{
    public const VERSION = 1;

    /** Older block types that are now spelled differently. */
    private const RENAMED = ['whatsapp' => 'cta'];

    /**
     * Stored (or legacy) blocks to the clean list the renderer reads.
     * Unknown types and non-array entries are dropped.
     *
     * @return list<array{type: string, version: int, props: array<string, mixed>}>
     */
    public static function normalize(mixed $blocks): array
    {
        $out = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = self::RENAMED[$block['type'] ?? ''] ?? ($block['type'] ?? null);

            if (! is_string($type) || ! Sections::has($type)) {
                continue;
            }

            $props = $block['props'] ?? $block['data'] ?? [];
            $props = is_array($props) ? $props : [];

            if (isset($block['type']) && $block['type'] === 'whatsapp') {
                $props['heading'] ??= '';
            }

            if ($type === 'text' && isset($props['body']) && ! isset($props['html'])) {
                $props['html'] = collect(preg_split('/\R{2,}/', trim((string) $props['body'])) ?: [])
                    ->filter(fn ($p) => trim($p) !== '')
                    ->map(fn ($p) => '<p>'.nl2br(e($p), false).'</p>')->implode('');
                unset($props['body']);
            }

            $out[] = ['type' => $type, 'version' => (int) ($block['version'] ?? self::VERSION), 'props' => $props];
        }

        return $out;
    }

    /**
     * What the Builder field is filled with.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function toForm(mixed $blocks): array
    {
        return array_map(fn ($b) => ['type' => $b['type'], 'data' => $b['props']], self::normalize($blocks));
    }

    /**
     * What gets saved from the Builder field (its items are keyed by random ids).
     *
     * @return list<array{type: string, version: int, props: array<string, mixed>}>
     */
    public static function fromForm(mixed $items): array
    {
        return self::normalize(array_map(
            fn ($i) => is_array($i) ? ['type' => $i['type'] ?? null, 'version' => self::VERSION, 'props' => $i['data'] ?? []] : null,
            array_values(is_array($items) ? $items : []),
        ));
    }

    /** Sections the visitor sees: hidden ones are left out. */
    public static function visible(mixed $blocks): array
    {
        return array_values(array_filter(self::normalize($blocks), fn ($b) => empty($b['props']['hidden'])));
    }
}
