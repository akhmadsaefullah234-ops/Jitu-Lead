@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $limitText = fn ($n) => $n === null ? 'tanpa batas' : number_format($n, 0, ',', '.');
    $open = config('jitu.registration') !== 'closed';
    $invite = config('jitu.registration') === 'invite';
    $contact = config('jitu.billing_contact');
    $cta = $open ? url('/app/register') : url('/app/login');
    $ctaText = $invite ? 'Daftar dengan kode undangan' : ($open ? 'Coba gratis '.$trialDays.' hari' : 'Masuk');
    $wa = $contact && preg_match('/^\+?\d[\d\s-]{7,}$/', $contact) ? 'https://wa.me/'.preg_replace('/\D/', '', $contact) : null;
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JITU LEAD - CRM untuk agen dan agensi properti</title>
    <meta name="description" content="Tangkap lead dari iklan dan WhatsApp, balas lebih cepat dengan AI, dan tindak lanjut otomatis. CRM untuk agen dan agensi properti.">
    <link rel="icon" href="{{ asset('images/icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">
    <style>
        :root { --red: #dc2626; --red-dark: #b91c1c; --red-deep: #991b1b; --red-soft: #fef2f2; --ink: #111827; --muted: #6b7280; --line: #e5e7eb; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #fff; color: var(--ink); line-height: 1.6; overflow-x: hidden; }
        a { color: inherit; }
        .wrap { max-width: 68rem; margin: 0 auto; padding: 0 1rem; }
        header.top { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 2px solid var(--red); }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; height: 3.5rem; gap: 1rem; }
        header.top img { height: 2.25rem; display: block; }
        nav { display: flex; align-items: center; gap: 1.25rem; font-size: .9rem; font-weight: 500; }
        nav a { text-decoration: none; color: var(--muted); }
        nav a:hover { color: var(--ink); }
        .btn { display: inline-block; padding: .75rem 1.25rem; border-radius: .75rem; background: var(--red); color: #fff !important; font-weight: 700; text-decoration: none; text-align: center; }
        .btn:hover { background: var(--red-dark); }
        .btn.ghost { background: #fff; color: var(--red-dark) !important; border: 1px solid #fecaca; }
        .btn.ghost:hover { background: var(--red-soft); }
        .btn.sm { padding: .45rem .9rem; font-size: .85rem; }
        .hero { background: linear-gradient(180deg, var(--red) 0%, var(--red-dark) 100%); color: #fff; padding: 4rem 0 4.5rem; }
        .hero h1 { font-size: clamp(2rem, 5vw, 3.2rem); line-height: 1.12; letter-spacing: -.02em; font-weight: 800; margin: 0 0 1rem; max-width: 40rem; }
        .hero p { font-size: 1.1rem; max-width: 38rem; margin: 0 0 1.75rem; color: rgba(255,255,255,.92); }
        .hero .row { display: flex; flex-wrap: wrap; gap: .75rem; }
        .hero .btn { background: #fff; color: var(--red-dark) !important; }
        .hero .btn:hover { background: var(--red-soft); }
        .hero .btn.ghost { background: transparent; color: #fff !important; border: 1px solid rgba(255,255,255,.6); }
        .hero .btn.ghost:hover { background: rgba(255,255,255,.12); }
        section.s { padding: 3.5rem 0; }
        section.s.alt { background: var(--red-soft); }
        h2.t { font-size: 1.7rem; font-weight: 800; letter-spacing: -.01em; margin: 0 0 .4rem; }
        .sub { color: var(--muted); margin: 0 0 2rem; max-width: 40rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        .box { border: 1px solid var(--line); border-radius: 1rem; padding: 1.25rem; background: #fff; box-shadow: 0 1px 2px rgba(17,24,39,.05); }
        .box h3 { margin: .6rem 0 .25rem; font-size: 1.05rem; }
        .box p { margin: 0; color: var(--muted); font-size: .92rem; }
        .ico { width: 2.5rem; height: 2.5rem; border-radius: .7rem; background: var(--red); color: #fff; display: grid; place-items: center; font-weight: 800; }
        .steps { counter-reset: n; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        .steps .box { counter-increment: n; }
        .steps .box::before { content: counter(n); display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: 9999px; background: var(--red); color: #fff; font-weight: 800; }
        .card { border: 1px solid var(--line); border-radius: 1rem; padding: 1.25rem; position: relative; display: flex; flex-direction: column; gap: .5rem; background: #fff; }
        .card.pop { border: 2px solid var(--red); }
        .tag { position: absolute; top: -.75rem; right: 1rem; background: var(--red); color: #fff; font-size: .75rem; font-weight: 700; padding: .15rem .7rem; border-radius: 9999px; }
        .price { font-size: 1.8rem; font-weight: 800; } .price small { font-size: .85rem; font-weight: 500; color: var(--muted); }
        .note { color: var(--muted); font-size: .85rem; }
        .card ul, .adds { list-style: none; padding: 0; margin: 0; display: grid; gap: .3rem; font-size: .9rem; }
        .card ul .no { color: var(--muted); text-decoration: line-through; }
        .card .btn { margin-top: auto; }
        details { border: 1px solid var(--line); border-radius: .85rem; padding: .85rem 1rem; background: #fff; margin-bottom: .6rem; }
        summary { cursor: pointer; font-weight: 700; }
        details p { margin: .5rem 0 0; color: var(--muted); font-size: .92rem; }
        .cta { background: linear-gradient(180deg, var(--red) 0%, var(--red-dark) 100%); color: #fff; text-align: center; padding: 3rem 1rem; }
        .cta h2 { margin: 0 0 .5rem; font-size: 1.7rem; font-weight: 800; }
        .cta .btn { background: #fff; color: var(--red-dark) !important; margin-top: .75rem; }
        footer { padding: 1.5rem 0; font-size: .85rem; color: var(--muted); }
        footer .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem; }
        @media (max-width: 640px) { nav a.hide { display: none; } .hero { padding: 2.5rem 0 3rem; } }
    </style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <a href="{{ url('/') }}"><img src="{{ asset('images/logo.svg') }}" alt="JITU LEAD"></a>
        <nav>
            <a class="hide" href="#fitur">Fitur</a>
            <a class="hide" href="#harga">Harga</a>
            <a class="hide" href="#tanya">Tanya jawab</a>
            <a class="btn sm" href="{{ url('/app/login') }}">Masuk</a>
        </nav>
    </div>
</header>

<div class="hero">
    <div class="wrap">
        <h1>Setiap calon pembeli properti dibalas cepat dan tidak ada yang terlewat.</h1>
        <p>JITU LEAD mengumpulkan lead dari iklan, formulir, dan WhatsApp di satu tempat. AI menyiapkan balasan, dan tindak lanjut berjalan otomatis sampai lead siap survei.</p>
        <div class="row">
            <a class="btn" href="{{ $cta }}">{{ $ctaText }}</a>
            <a class="btn ghost" href="#harga">Lihat harga</a>
        </div>
    </div>
</div>

<section class="s" id="fitur">
    <div class="wrap">
        <h2 class="t">Semua yang dibutuhkan tim penjualan properti</h2>
        <p class="sub">Dibuat untuk agen dan agensi yang mengandalkan iklan dan WhatsApp.</p>
        <div class="grid">
            <div class="box"><div class="ico">1</div><h3>Pipeline lead</h3><p>Lihat posisi setiap lead dari baru masuk sampai closing, lengkap dengan catatan dan riwayat. Data bisa diekspor ke CSV.</p></div>
            <div class="box"><div class="ico">2</div><h3>WhatsApp resmi dan scan QR</h3><p>Hubungkan lewat WhatsApp Business API atau cukup scan QR. Pesan masuk dan keluar tersimpan di lead.</p></div>
            <div class="box"><div class="ico">3</div><h3>Asisten AI</h3><p>AI membuat balasan dari data properti Anda. Saat agen mengetik, AI berhenti, dan lead rumit diserahkan ke agen.</p></div>
            <div class="box"><div class="ico">4</div><h3>Follow-up otomatis</h3><p>Atur aturan tindak lanjut sekali, sistem mengingatkan lead yang belum membalas tanpa Anda mengingat satu per satu.</p></div>
            <div class="box"><div class="ico">5</div><h3>Halaman web dan formulir</h3><p>Buat halaman properti dan formulir dalam hitungan menit. Lead dari formulir selalu masuk, tidak pernah dibuang.</p></div>
            <div class="box"><div class="ico">6</div><h3>Pelacakan iklan</h3><p>Kirim event ke Meta, TikTok, dan Google dengan pilihan event sendiri, supaya iklan belajar dari lead yang nyata.</p></div>
        </div>
    </div>
</section>

<section class="s alt">
    <div class="wrap">
        <h2 class="t">Mulai dalam tiga langkah</h2>
        <p class="sub">Tanpa pemasangan rumit.</p>
        <div class="steps">
            <div class="box"><h3>Daftar dan isi data properti</h3><p>Buat akun agensi, undang tim, dan masukkan properti yang dijual.</p></div>
            <div class="box"><h3>Hubungkan WhatsApp dan iklan</h3><p>Scan QR atau sambungkan WhatsApp resmi, lalu pasang formulir di iklan Anda.</p></div>
            <div class="box"><h3>Biarkan sistem bekerja</h3><p>Lead masuk, AI menyiapkan balasan, dan follow-up berjalan sesuai aturan Anda.</p></div>
        </div>
    </div>
</section>

<section class="s" id="harga">
    <div class="wrap">
        <h2 class="t">Harga</h2>
        <p class="sub">Harga tetap per tim, bukan per pengguna. Coba gratis {{ $trialDays }} hari dengan batas paket Tim Kecil. Tahunan sama dengan bayar 10 bulan.</p>
        <div class="grid">
            @foreach ($plans as $plan)
                <div class="card {{ $plan['popular'] ? 'pop' : '' }}">
                    @if ($plan['popular'])<span class="tag">Paling populer</span>@endif
                    <h3 style="margin:0">{{ $plan['name'] }}</h3>
                    <div class="note">{{ $plan['tagline'] }}</div>
                    <div class="price">{{ $rp($plan['price']['monthly']) }}<small> / bulan</small></div>
                    <div class="note">atau {{ $rp($plan['price']['yearly']) }} / tahun</div>
                    <ul>
                        @foreach ($plan['limits'] as $lk => $lv)<li>&#10003; {{ $limitLabels[$lk] }}: <b>{{ $limitText($lv) }}</b></li>@endforeach
                        @foreach ($plan['features'] as $fk => $fv)<li class="{{ $fv ? '' : 'no' }}">{{ $fv ? '✓' : '✕' }} {{ $featureLabels[$fk] }}</li>@endforeach
                    </ul>
                    <a class="btn" href="{{ $cta }}">{{ $ctaText }}</a>
                </div>
            @endforeach
        </div>
        <h3 style="margin-top:2rem">Tambahan per bulan</h3>
        <ul class="adds">@foreach ($addons as $addon)<li>{{ $addon['unit_label'] }}: <b>{{ $rp($addon['price']) }}</b></li>@endforeach</ul>
        <p class="note" style="margin-top:1rem">Biaya template WhatsApp resmi ditagih langsung oleh Meta ke akun Anda dan bukan bagian dari paket.</p>
    </div>
</section>

<section class="s alt" id="tanya">
    <div class="wrap">
        <h2 class="t">Tanya jawab</h2>
        <p class="sub"></p>
        <details><summary>Apakah saya perlu punya API key AI sendiri?</summary><p>Tidak. AI sudah disediakan sistem dan kuotanya mengikuti paket Anda.</p></details>
        <details><summary>Bagaimana jika lead melebihi batas paket?</summary><p>Lead dari formulir dan WhatsApp tetap diterima. Anda hanya mendapat peringatan untuk menaikkan paket.</p></details>
        <details><summary>Apa yang terjadi jika langganan berakhir?</summary><p>Ada masa tenggang 3 hari. Setelah itu akun menjadi baca saja, data tidak dihapus, dan bisa dipakai lagi setelah perpanjang.</p></details>
        <details><summary>Bagaimana cara membayar?</summary><p>Saat ini lewat transfer bank. Rekening tertera di menu Langganan setelah Anda masuk.</p></details>
        <details><summary>Bisakah turun paket?</summary><p>Bisa. Data tidak pernah dihapus saat turun paket.</p></details>
    </div>
</section>

<div class="cta">
    <h2>Siap tidak ada lead yang terlewat?</h2>
    <div>Mulai dari {{ $rp(collect($plans)->min(fn ($p) => $p['price']['monthly'])) }} per bulan.</div>
    <a class="btn" href="{{ $cta }}">{{ $ctaText }}</a>
    @if ($wa)<div style="margin-top:.75rem"><a href="{{ $wa }}" style="color:#fff">Tanya dulu lewat WhatsApp</a></div>@endif
</div>

<footer>
    <div class="wrap">
        <span>&copy; {{ date('Y') }} JITU LEAD</span>
        <span><a href="{{ url('/harga') }}">Halaman harga</a> · <a href="{{ url('/app/login') }}">Masuk</a></span>
    </div>
</footer>
</body>
</html>
