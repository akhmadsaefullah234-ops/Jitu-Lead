@php
    $testimonials = array_values(array_filter((array) ($p['testimonials'] ?? []), fn ($t) => is_array($t) && filled($t['quote'] ?? null)));
    $logos = \App\LandingPages\Embeds::images($p['logos'] ?? [], $tenantId);
    $stats = array_values(array_filter((array) ($p['stats'] ?? []), fn ($s) => is_array($s) && filled($s['number'] ?? null)));
@endphp
@if ($testimonials || $logos || $stats)
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if ($stats)<div class="stats">@foreach ($stats as $st)<div><b>{{ $st['number'] }}</b><span>{{ $st['label'] ?? '' }}</span></div>@endforeach</div>@endif
    @if ($testimonials)
        <div class="grid c3">
            @foreach ($testimonials as $it)
                @php
                    $photo = \App\LandingPages\Embeds::image($it['photo'] ?? null, $tenantId);
                    $stars = max(0, min(5, (int) ($it['rating'] ?? 0)));
                @endphp
                <figure class="card rev">
                    @if ($stars)<div class="stars" aria-label="{{ $stars }} dari 5 bintang">{{ str_repeat('★', $stars) }}<span>{{ str_repeat('★', 5 - $stars) }}</span></div>@endif
                    <blockquote><p>&ldquo;{{ $it['quote'] }}&rdquo;</p></blockquote>
                    <figcaption class="who-row">@if ($photo)<img class="ava" src="{{ $photo }}" alt="" loading="lazy" decoding="async">@endif<span><b>{{ $it['name'] ?? '' }}</b>@if (filled($it['role'] ?? null))<small>{{ $it['role'] }}</small>@endif</span></figcaption>
                </figure>
            @endforeach
        </div>
    @endif
    @if ($logos)
        @if (filled($p['logos_title'] ?? null))<p class="mute logos-t">{{ $p['logos_title'] }}</p>@endif
        <div class="logos">@foreach ($logos as $src)<img src="{{ $src }}" alt="" loading="lazy" decoding="async">@endforeach</div>
    @endif
</div></section>
@endif
