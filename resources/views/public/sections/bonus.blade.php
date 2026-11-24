@php
    // {harga} is the product's price; the struck-through normal price is shown only when asked for and really higher.
    $price = $productPrice['price'] ?? null;
    $strike = ! empty($p['show_compare']) && $price !== null && ($productPrice['compare'] ?? 0) > $price ? $productPrice['compare'] : null;
    $short = fn (int $n) => str_replace(' ', '', \App\LandingPages\Sections::rupiah($n));
    $badge = trim(preg_replace('/\s+/', ' ', str_replace('{harga}', $price !== null ? $short($price) : '', (string) ($p['badge'] ?? ''))));
@endphp
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if ($badge !== '')<p class="ribbon"><span>{{ $badge }}</span>@if ($strike)<span>Harga normal <s>{{ $short($strike) }}</s></span>@endif</p>@endif
    <div class="grid c2">
        @foreach ((array) ($p['items'] ?? []) as $it)
            @if (is_array($it))<div class="card bonus"><span class="dot" aria-hidden="true">+</span><h3>{{ $it['title'] ?? '' }}</h3>@if (filled($it['text'] ?? null))<p>{{ $it['text'] }}</p>@endif</div>@endif
        @endforeach
    </div>
</div></section>
