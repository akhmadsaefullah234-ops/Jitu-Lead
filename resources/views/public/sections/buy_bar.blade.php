@php
    $price = is_numeric($p['price'] ?? null) ? (int) $p['price'] : null;
    $strike = is_numeric($p['compare_price'] ?? null) && $price !== null && (int) $p['compare_price'] > $price ? (int) $p['compare_price'] : null;
@endphp
<div class="buybar" role="region" aria-label="Beli {{ $p['name'] ?? 'produk' }}">
    <div class="buybar-in">
        <div class="buybar-t">
            <b>{{ $p['name'] ?? '' }}</b>
            @if ($price !== null)<span>@if ($strike)<s>{{ \App\LandingPages\Sections::rupiah($strike) }}</s> @endif<strong>{{ \App\LandingPages\Sections::rupiah($price) }}</strong></span>@endif
        </div>
        <a class="btn" href="#daftar">{{ $p['button'] ?? 'Beli sekarang' }}</a>
    </div>
</div>
