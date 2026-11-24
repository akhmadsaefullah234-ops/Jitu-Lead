@php $tiers = array_values(array_filter((array) ($p['items'] ?? []), 'is_array')); @endphp
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['intro'] ?? null))<p class="mute">{{ $p['intro'] }}</p>@endif
    <ol class="tiers n{{ count($tiers) }}">
        @foreach ($tiers as $it)
            <li class="card tier" style="--step:{{ $loop->index }}">
                <span class="tier-n" aria-hidden="true">{{ $loop->iteration }}</span>
                <div class="tier-t">
                    <small>Tingkat {{ $loop->iteration }} dari {{ count($tiers) }}</small>
                    <h3>{{ $it['name'] ?? '' }}</h3>
                    @if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif
                    <span class="tier-bar" aria-hidden="true">@for ($i = 1; $i <= count($tiers); $i++)<i @class(['on' => $i <= $loop->iteration])></i>@endfor</span>
                </div>
            </li>
        @endforeach
    </ol>
</div></section>
