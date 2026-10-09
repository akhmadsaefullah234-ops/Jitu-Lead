<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="kpr" data-kpr>
        <label>Harga properti (Rp)<input type="text" inputmode="numeric" data-k="price" value="{{ (int) ($p['price'] ?? 0) }}"></label>
        <label>Uang muka (%)<input type="text" inputmode="decimal" data-k="dp" value="{{ (float) ($p['dp_percent'] ?? 10) }}"></label>
        <label>Tenor (tahun)<input type="text" inputmode="numeric" data-k="years" value="{{ (int) ($p['tenor'] ?? 20) }}"></label>
        <label>Bunga (% per tahun)<input type="text" inputmode="decimal" data-k="rate" value="{{ (float) ($p['rate'] ?? 8) }}"></label>
        <div class="out"><small>Perkiraan cicilan per bulan</small><strong data-k="out">-</strong><small data-k="loan"></small></div>
    </div>
    @if (filled($p['note'] ?? null))<p class="mute" style="font-size:.85rem;margin-top:.8rem">{{ $p['note'] }}</p>@endif
</div></section>
