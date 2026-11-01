{{-- One configurable button (App\LandingPages\Buttons). Needs: $b, $ctx, $tracking. Draws nothing when it cannot work. --}}
@php
    $link = \App\LandingPages\Buttons::link($b, $ctx);
    $px = $link ? \App\LandingPages\Buttons::pixel($b, $tracking ?? null) : null;
    $icon = \App\LandingPages\Buttons::iconPath($b['icon'] ?? null);
    $color = array_key_exists($b['color'] ?? '', \App\LandingPages\Buttons::COLORS) ? $b['color'] : 'brand';
    $size = array_key_exists($b['size'] ?? '', \App\LandingPages\Buttons::SIZES) ? $b['size'] : 'md';
@endphp
@if ($link)
    <a class="jb jb-{{ $color }} jb-{{ $size }} {{ $extraClass ?? '' }}" href="{{ $link['href'] }}" data-action="{{ $link['action'] }}"
       @if ($link['new_tab']) target="_blank" rel="noopener" @elseif ($link['action'] !== 'scroll') rel="noopener" @endif
       @if ($px) data-px="{{ $px }}" @endif>@if ($icon)<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>@endif<span>{{ $b['label'] ?? 'Klik di sini' }}</span></a>
@endif
