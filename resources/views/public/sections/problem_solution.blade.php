<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c2 ps">
        @foreach (['problems' => ['problem_title', 'bad', '✕'], 'solutions' => ['solution_title', 'good', '✓']] as $key => [$titleKey, $tone, $mark])
            @php($items = array_filter((array) ($p[$key] ?? []), fn ($i) => is_array($i) && filled($i['text'] ?? null)))
            @if ($items)
                <div class="card ps-{{ $tone }}">
                    @if (filled($p[$titleKey] ?? null))<h3>{{ $p[$titleKey] }}</h3>@endif
                    <ul>@foreach ($items as $it)<li><span class="ps-i" aria-hidden="true">{{ mb_substr(trim((string) ($it['icon'] ?? '')), 0, 4) ?: $mark }}</span><span>{{ $it['text'] }}</span></li>@endforeach</ul>
                </div>
            @endif
        @endforeach
    </div>
</div></section>
