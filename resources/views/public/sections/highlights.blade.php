<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c3">
        @foreach ((array) ($p['items'] ?? []) as $it)
            <div class="card"><div class="dot">{{ $loop->iteration }}</div><h3>{{ $it['title'] ?? '' }}</h3><p>{{ $it['text'] ?? '' }}</p></div>
        @endforeach
    </div>
</div></section>
