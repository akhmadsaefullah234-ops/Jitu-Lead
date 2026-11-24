@php
    $shots = [];
    foreach ((array) ($p['items'] ?? []) as $it) {
        $src = is_array($it) ? \App\LandingPages\Embeds::image($it['image'] ?? null, $tenantId) : null;
        if ($src !== null) {
            $shots[] = ['src' => $src, 'size' => \App\LandingPages\Embeds::imageSize($it['image'], $tenantId), 'caption' => trim((string) ($it['caption'] ?? '')), 'alt' => trim((string) ($it['alt'] ?? ''))];
        }
    }
@endphp
@if ($shots !== [])
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="phones n{{ count($shots) }}">
        @foreach ($shots as $s)
            <figure class="phone">
                <div class="phone-f"><img src="{{ $s['src'] }}" alt="{{ $s['alt'] !== '' ? $s['alt'] : $s['caption'] }}" loading="lazy" decoding="async" @if ($s['size']) width="{{ $s['size'][0] }}" height="{{ $s['size'][1] }}" @endif></div>
                @if ($s['caption'] !== '')<figcaption>{{ $s['caption'] }}</figcaption>@endif
            </figure>
        @endforeach
    </div>
</div></section>
@endif
