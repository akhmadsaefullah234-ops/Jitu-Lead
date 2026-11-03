{{-- Animated GIFs, shown as uploaded (never resized, so they keep moving). --}}
@php
    $gifs = \App\LandingPages\Embeds::images($p['gifs'] ?? [], $tenantId);
    $width = array_key_exists($p['width'] ?? '', \App\LandingPages\Sections::GIF_WIDTHS) ? $p['width'] : 'medium';
    $cols = count($gifs) > 1 && in_array((string) ($p['columns'] ?? '2'), ['1', '2', '3'], true) ? (string) ($p['columns'] ?? '2') : '1';
    $alt = trim((string) ($p['caption'] ?? '')) ?: trim((string) ($p['heading'] ?? ''));
@endphp
@if ($gifs)
    <section class="{{ $cls }}"><div class="wrap">
        @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
        <figure class="gif gif-{{ $width }} gif-c{{ $cols }}">
            <div class="gif-grid">@foreach ($gifs as $src)<img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async">@endforeach</div>
            @if (filled($p['caption'] ?? null))<figcaption>{{ $p['caption'] }}</figcaption>@endif
        </figure>
    </div></section>
@endif
