@php $cards = array_values(array_filter((array) ($p['items'] ?? []), fn ($it) => is_array($it) && filled($it['title'] ?? null))); @endphp
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['intro'] ?? null))<p class="mute">{{ $p['intro'] }}</p>@endif
    <div class="kc-g n{{ count($cards) }}">
        @foreach ($cards as $it)
            <article class="kc kc-{{ isset(\App\LandingPages\Sections::KIND_COLORS[$it['color'] ?? '']) ? $it['color'] : 'ungu' }}">
                @include('public.partials.item-icon', ['it' => $it])
                <h3>{{ $it['title'] }}</h3>
                @if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif
                @if (filled($it['example'] ?? null))<blockquote>&ldquo;{{ $it['example'] }}&rdquo;</blockquote>@endif
            </article>
        @endforeach
    </div>
</div></section>
