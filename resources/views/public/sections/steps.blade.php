<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['intro'] ?? null))<p class="mute">{{ $p['intro'] }}</p>@endif
    <ol class="grid c2 steps">
        @foreach ((array) ($p['items'] ?? []) as $it)
            @if (is_array($it))<li class="card"><div class="dot" aria-hidden="true">{{ $loop->iteration }}</div><h3>{{ $it['title'] ?? '' }}</h3>@if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif</li>@endif
        @endforeach
    </ol>
</div></section>
