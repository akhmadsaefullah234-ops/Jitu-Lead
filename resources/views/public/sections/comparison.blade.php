<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c2 cmp">
        @foreach ([['left', 'cmp-a', '✕'], ['right', 'cmp-b', '✓']] as [$side, $class, $mark])
            <div class="card {{ $class }}">
                <h3>{{ $p[$side.'_title'] ?? '' }}</h3>
                <ul>
                    @foreach ((array) ($p[$side.'_items'] ?? []) as $it)
                        @php $t = is_array($it) ? ($it['text'] ?? '') : $it; @endphp
                        @if (filled($t))<li><span aria-hidden="true">{{ $mark }}</span> {{ $t }}</li>@endif
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div></section>
