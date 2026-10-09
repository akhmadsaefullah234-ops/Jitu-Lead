@php($map = \App\LandingPages\Embeds::map($p['map_url'] ?? null))
@php($route = \App\LandingPages\Embeds::mapLink($p['map_link'] ?? null))
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['address'] ?? null))<p style="margin:0 0 1rem">{{ $p['address'] }}</p>@endif
    @if ($map)<div class="map"><iframe src="{{ $map }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Peta lokasi"></iframe></div>@endif
    @if ($route)<p style="margin:1rem 0 0"><a class="btn sm" href="{{ $route }}" target="_blank" rel="noopener nofollow">Buka rute di Google Maps</a></p>@endif
    @if (! empty($p['nearby']))
        <ul class="near">@foreach ((array) $p['nearby'] as $it)<li><span>{{ $it['place'] ?? '' }}</span><b>{{ $it['distance'] ?? '' }}</b></li>@endforeach</ul>
    @endif
</div></section>
