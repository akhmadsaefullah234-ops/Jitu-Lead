<section class="{{ $cls }}"><div class="wrap price">
    @if (filled($p['label'] ?? null))<small>{{ $p['label'] }}</small>@endif
    @if (filled($p['price'] ?? null))<strong>{{ $p['price'] }}</strong>@endif
    @if (filled($p['installment'] ?? null))<div class="inst">{{ $p['installment'] }}</div>@endif
    @if (filled($p['note'] ?? null))<small>{{ $p['note'] }}</small>@endif
</div></section>
