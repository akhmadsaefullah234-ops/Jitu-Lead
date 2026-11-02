<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c3">
        @foreach ((array) ($p['items'] ?? []) as $it)
            @php
                $photo = \App\LandingPages\Embeds::image($it['photo'] ?? null, $tenantId);
                $stars = max(0, min(5, (int) ($it['rating'] ?? 5)));
            @endphp
            <figure class="card rev">
                @if ($photo)<img src="{{ $photo }}" alt="" loading="lazy" decoding="async">@endif
                @if ($stars)<div class="stars" aria-label="{{ $stars }} dari 5 bintang">{{ str_repeat('★', $stars) }}<span>{{ str_repeat('★', 5 - $stars) }}</span></div>@endif
                <blockquote><p>&ldquo;{{ $it['quote'] ?? '' }}&rdquo;</p></blockquote>
                <figcaption class="who">{{ $it['name'] ?? '' }}</figcaption>
            </figure>
        @endforeach
    </div>
</div></section>
