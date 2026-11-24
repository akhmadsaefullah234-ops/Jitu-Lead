<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c2">
        @foreach ((array) ($p['items'] ?? []) as $it)
            @if (is_array($it))<div class="card bonus">@if (filled($p['badge'] ?? null))<span class="badge">{{ $p['badge'] }}</span>@endif<h3>{{ $it['title'] ?? '' }}</h3>@if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif</div>@endif
        @endforeach
    </div>
</div></section>
