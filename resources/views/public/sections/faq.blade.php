<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @foreach ((array) ($p['items'] ?? []) as $it)
        <details class="q"><summary>{{ $it['q'] ?? '' }}</summary><p>{{ $it['a'] ?? '' }}</p></details>
    @endforeach
</div></section>
