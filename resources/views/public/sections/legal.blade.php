<section class="{{ $cls }}"><div class="wrap legal">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <ul>@foreach ((array) ($p['items'] ?? []) as $it)<li><b>{{ $it['name'] ?? '' }}</b>@if (filled($it['note'] ?? null))<span class="mute">{{ $it['note'] }}</span>@endif</li>@endforeach</ul>
    @if (filled($p['note'] ?? null))<p class="mute" style="font-size:.85rem;margin-top:.8rem">{{ $p['note'] }}</p>@endif
</div></section>
