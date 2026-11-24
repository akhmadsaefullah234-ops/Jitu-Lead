<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['intro'] ?? null))<p class="mute">{{ $p['intro'] }}</p>@endif
    <div class="grid c3">
        @foreach ((array) ($p['items'] ?? []) as $it)
            @if (is_array($it))<div class="card tier"><div class="dot" aria-hidden="true">{{ $loop->iteration }}</div><h3>{{ $it['name'] ?? '' }}</h3>@if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif</div>@endif
        @endforeach
    </div>
</div></section>
