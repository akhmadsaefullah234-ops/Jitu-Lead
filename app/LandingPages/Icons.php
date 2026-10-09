<?php

namespace App\LandingPages;

/**
 * The small fixed icon set for the facilities section. Inline SVG paths, so
 * nothing is downloaded and nothing user-typed ever reaches an SVG.
 */
class Icons
{
    private const PATHS = [
        'home' => 'M3 11l9-8 9 8M5 10v10h14V10M10 20v-6h4v6',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4',
        'tree' => 'M12 3l6 8h-3l4 6H5l4-6H6zM12 17v4',
        'water' => 'M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z',
        'road' => 'M8 3L4 21M16 3l4 18M12 4v3M12 10.5v3M12 17v3',
        'wifi' => 'M2 9a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 19.5h.01',
        'car' => 'M5 16v-5l2-5h10l2 5v5M3 16h18M7.5 19.5h.01M16.5 19.5h.01',
        'pool' => 'M3 18c2 0 2-1.5 4-1.5S9 18 11 18s2-1.5 4-1.5S17 18 19 18M7 14V5a2 2 0 0 1 4 0M13 14V5a2 2 0 0 1 4 0M7 9h6',
        'school' => 'M2 9l10-5 10 5-10 5zM6 11.5V17c3 2 9 2 12 0v-5.5',
        'store' => 'M4 9l1.5-5h13L20 9M4 9h16M5 9v11h14V9M10 20v-5h4v5',
        'cctv' => 'M3 7l13 4-2 5L3 12zM16 11l5-2M9 16l-1 4h4',
        'bolt' => 'M13 2L5 14h6l-1 8 8-12h-6z',
        'worship' => 'M12 3c2 2 4 3 4 6v1H8V9c0-3 2-4 4-6zM5 21V10h14v11M10 21v-5h4v5',
        'hospital' => 'M5 4h14v17H5zM12 8v6M9 11h6',
        'pin' => 'M12 21s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12zM12 11.5h.01',
        'check' => 'M5 12l5 5 9-10',
    ];

    private const LABELS = [
        'home' => 'Rumah', 'shield' => 'Keamanan', 'tree' => 'Taman / hijau', 'water' => 'Air bersih', 'road' => 'Jalan / akses',
        'wifi' => 'Internet', 'car' => 'Carport / parkir', 'pool' => 'Kolam renang', 'school' => 'Sekolah', 'store' => 'Pertokoan',
        'cctv' => 'CCTV', 'bolt' => 'Listrik', 'worship' => 'Tempat ibadah', 'hospital' => 'Kesehatan', 'pin' => 'Lokasi', 'check' => 'Centang',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::LABELS;
    }

    public static function svg(?string $name): string
    {
        $path = self::PATHS[$name] ?? self::PATHS['check'];

        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$path.'"/></svg>';
    }
}
