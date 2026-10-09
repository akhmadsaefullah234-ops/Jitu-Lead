<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c2">
        @foreach ((array) ($p['items'] ?? []) as $it)
            <div class="card"><blockquote><p>&ldquo;{{ $it['quote'] ?? '' }}&rdquo;</p></blockquote><div class="who">{{ $it['name'] ?? '' }}</div></div>
        @endforeach
    </div>
</div></section>
