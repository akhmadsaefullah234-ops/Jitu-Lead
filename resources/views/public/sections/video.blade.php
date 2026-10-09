@php($id = \App\LandingPages\Embeds::youtubeId($p['url'] ?? null))
@if ($id)
    <section class="{{ $cls }}"><div class="wrap">
        @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
        <div class="video"><a href="https://www.youtube.com/watch?v={{ $id }}" target="_blank" rel="noopener nofollow" data-yt="{{ $id }}" aria-label="Putar video"><img src="https://i.ytimg.com/vi/{{ $id }}/hqdefault.jpg" alt="" loading="lazy" decoding="async"><span class="play" aria-hidden="true">&#9654;</span></a></div>
    </div></section>
@endif
