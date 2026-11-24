<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c2 cmp">
        @foreach (['left' => ['cmp-a', '✕'], 'right' => ['cmp-b', '✓']] as $side => $look)
            <div class="card {{ $look[0] }}">
                <h3>{{ $p[$side.'_title'] ?? '' }}</h3>
                <ul>
                    @foreach ((array) ($p[$side.'_items'] ?? []) as $it)
                        @php $t = $it; while (is_array($t)) { $t = $t['text'] ?? ''; } @endphp
                        @if (filled($t))<li><span aria-hidden="true">{{ $look[1] }}</span> {{ $t }}</li>@endif
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div></section>
