<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c3">
        @foreach ((array) ($p['items'] ?? []) as $it)
            <div class="card amen"><div class="dot">{!! \App\LandingPages\Icons::svg($it['icon'] ?? null) !!}</div><div><h3>{{ $it['title'] ?? '' }}</h3>@if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif</div></div>
        @endforeach
    </div>
</div></section>
