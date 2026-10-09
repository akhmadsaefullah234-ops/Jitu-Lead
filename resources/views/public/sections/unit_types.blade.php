<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="grid c3">
        @foreach ((array) ($p['items'] ?? []) as $it)
            <div class="card unit">
                <h3>{{ $it['name'] ?? '' }}</h3>
                @if (filled($it['price'] ?? null))<div class="p">{{ $it['price'] }}</div>@endif
                <ul>
                    @if (filled($it['area'] ?? null))<li>Luas: {{ $it['area'] }}</li>@endif
                    @if (filled($it['bedrooms'] ?? null))<li>Kamar tidur: {{ $it['bedrooms'] }}</li>@endif
                    @if (filled($it['bathrooms'] ?? null))<li>Kamar mandi: {{ $it['bathrooms'] }}</li>@endif
                    @if (filled($it['note'] ?? null))<li>{{ $it['note'] }}</li>@endif
                </ul>
            </div>
        @endforeach
    </div>
</div></section>
