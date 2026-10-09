@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $limitText = fn ($n) => $n === null ? 'tanpa batas' : number_format($n, 0, ',', '.');
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Harga JITU LEAD</title>
    <style>
        :root { --c: #dc2626; --bg: #fff; --fg: #111827; --muted: #6b7280; --line: #e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --fg: #f9fafb; --muted: #9ca3af; --line: #374151; } }
        body { margin: 0; font-family: system-ui, sans-serif; background: var(--bg); color: var(--fg); line-height: 1.6; }
        main { max-width: 64rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
        h1 { margin: 0 0 .25rem; }
        .sub { color: var(--muted); margin: 0 0 2rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .card { border: 1px solid var(--line); border-radius: 1rem; padding: 1.25rem; position: relative; display: flex; flex-direction: column; gap: .5rem; }
        .card.pop { border: 2px solid var(--c); }
        .tag { position: absolute; top: -.75rem; right: 1rem; background: var(--c); color: #fff; font-size: .75rem; font-weight: 700; padding: .15rem .7rem; border-radius: 9999px; }
        .price { font-size: 1.8rem; font-weight: 800; } .price small { font-size: .85rem; font-weight: 500; color: var(--muted); }
        ul { list-style: none; padding: 0; margin: 0; display: grid; gap: .3rem; font-size: .9rem; }
        .no { color: var(--muted); text-decoration: line-through; }
        .btn { display: block; text-align: center; padding: .8rem 1rem; border-radius: .75rem; background: var(--c); color: #fff; font-weight: 700; text-decoration: none; margin-top: auto; }
        .note { color: var(--muted); font-size: .85rem; }
    </style>
</head>
<body>
<main>
    <h1>Harga JITU LEAD</h1>
    <p class="sub">Harga tetap per tim, bukan per pengguna. Coba gratis {{ $trialDays }} hari dengan batas paket Tim Kecil. Tahunan = bayar 10 bulan.</p>
    <div class="grid">
        @foreach ($plans as $key => $plan)
            <section class="card {{ $plan['popular'] ? 'pop' : '' }}">
                @if ($plan['popular'])<span class="tag">Paling populer</span>@endif
                <h2 style="margin:0">{{ $plan['name'] }}</h2>
                <div class="note">{{ $plan['tagline'] }}</div>
                <div class="price">{{ $rp($plan['price']['monthly']) }}<small> / bulan</small></div>
                <div class="note">atau {{ $rp($plan['price']['yearly']) }} / tahun</div>
                <ul>
                    @foreach ($plan['limits'] as $lk => $lv)<li>&#10003; {{ $limitLabels[$lk] }}: <b>{{ $limitText($lv) }}</b></li>@endforeach
                    @foreach ($plan['features'] as $fk => $fv)<li class="{{ $fv ? '' : 'no' }}">{{ $fv ? '✓' : '✕' }} {{ $featureLabels[$fk] }}</li>@endforeach
                </ul>
                <a class="btn" href="{{ url('/app/register') }}">Coba gratis</a>
            </section>
        @endforeach
    </div>
    <h2 style="margin-top:2.5rem">Tambahan per bulan</h2>
    <ul>@foreach ($addons as $addon)<li>{{ $addon['unit_label'] }}: <b>{{ $rp($addon['price']) }}</b></li>@endforeach</ul>
    <p class="note" style="margin-top:1rem">Biaya template WhatsApp resmi ditagih langsung oleh Meta ke akun Anda dan bukan bagian dari paket.</p>
</main>
</body>
</html>
