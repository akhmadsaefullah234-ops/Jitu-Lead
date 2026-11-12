<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The user's initials on the panel's primary colour, drawn as an inline SVG.
 *
 * Filament's default (ui-avatars.com) loads an image from an outside service; when that service cannot be reached the
 * top bar shows a broken image with the text "Ava". This one needs no network.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $part): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        $background = Color::convertToHex(FilamentColor::getColor('primary')[600] ?? Color::Gray[950]);
        $text = htmlspecialchars(mb_strtoupper($initials ?: '?'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.$background.'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#fff" font-family="Inter, system-ui, sans-serif" font-size="26" font-weight="600">'.$text.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
