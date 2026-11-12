<?php

namespace App\Tutorials;

use App\Enums\Role;
use App\LandingPages\RichText;
use App\Support\Industry;

/**
 * One chapter of the in-app tutorial, read from resources/tutorials/{slug}.php (see docs/tutorial.md).
 * Text may carry a little HTML (bold, italic, lists, links); it is always rebuilt through App\LandingPages\RichText.
 * "{katalog}" and "{item}" become the agency's own words (Industry::term catalog / catalog_item).
 */
final class Chapter
{
    /**
     * @param  list<string>  $industries  keys of config/industries.php
     * @param  list<array{text: string, image: ?string, alt: string, mobile: ?string, shot: array}>  $steps
     * @param  array<string, list<string>>  $notes  tips / perhatian / penting
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $group,
        public readonly int $order,
        public readonly array $industries,
        public readonly bool $admin,
        public readonly bool $leader,
        public readonly string $menu,
        public readonly string $intro,
        public readonly array $steps,
        public readonly array $notes,
    ) {}

    public static function fromArray(string $slug, array $data): self
    {
        $steps = [];

        foreach (array_values((array) ($data['steps'] ?? [])) as $i => $step) {
            $step = is_string($step) ? ['text' => $step] : (array) $step;
            $number = $i + 1;
            $hasShot = isset($step['shot']);
            $steps[] = [
                'text' => (string) ($step['text'] ?? ''),
                'image' => $hasShot ? "tutorial/{$slug}/{$number}.webp" : null,
                'alt' => (string) ($step['alt'] ?? strip_tags((string) ($step['text'] ?? ''))),
                'mobile' => $hasShot && ! empty($step['shot']['mobile']) ? "tutorial/{$slug}/{$number}-hp.webp" : null,
                'shot' => $hasShot ? (array) $step['shot'] : [],
            ];
        }

        return new self(
            $slug,
            (string) $data['title'],
            (string) $data['group'],
            (int) ($data['order'] ?? 100),
            array_values((array) ($data['industries'] ?? array_keys(config('industries.industries')))),
            (bool) ($data['admin'] ?? false),
            (bool) ($data['leader'] ?? false),
            (string) ($data['menu'] ?? ''),
            (string) ($data['intro'] ?? ''),
            $steps,
            array_filter([
                'tips' => array_values((array) ($data['tips'] ?? [])),
                'perhatian' => array_values((array) ($data['perhatian'] ?? [])),
                'penting' => array_values((array) ($data['penting'] ?? [])),
            ]),
        );
    }

    public function for(Industry $industry): bool
    {
        return in_array($industry->key, $this->industries, true);
    }

    /** Admin chapters show to admins only; "leader" chapters to admins and team leaders (the pages agents cannot open). */
    public function readableBy(?Role $role): bool
    {
        return match (true) {
            $this->admin => $role === Role::Admin,
            $this->leader => $role?->seesAllLeads() ?? false,
            default => true,
        };
    }

    /** Safe HTML of a text in this chapter, with the agency's words filled in. */
    public static function html(string $text, ?Industry $industry = null): string
    {
        $industry ??= Industry::current();

        return RichText::clean(strtr($text, [
            '{katalog}' => $industry->term('catalog'),
            '{item}' => $industry->term('catalog_item'),
        ]));
    }

    /** Plain text of the whole chapter, for search. */
    public function text(): string
    {
        return mb_strtolower(implode(' ', [$this->title, $this->menu, strip_tags($this->intro),
            ...array_map(fn (array $s) => strip_tags($s['text']), $this->steps),
            ...array_map('strip_tags', array_merge(...array_values($this->notes ?: [[]]))),
        ]));
    }
}
