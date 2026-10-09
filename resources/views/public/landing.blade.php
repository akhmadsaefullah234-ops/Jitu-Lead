@php
    $canonical = $preview ? null : $page->publicUrl();
    $n = 0;
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    @if ($preview)<meta name="robots" content="noindex">@endif
    @if ($canonical)<link rel="canonical" href="{{ $canonical }}">@endif
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    @if ($canonical)<meta property="og:url" content="{{ $canonical }}">@endif
    @if ($og)<meta property="og:image" content="{{ $og }}"><meta name="twitter:image" content="{{ $og }}">@endif
    <meta name="twitter:card" content="{{ $og ? 'summary_large_image' : 'summary' }}">
    <meta name="theme-color" content="{{ $color }}">
    <style>
        :root { --c: {{ $color }}; --ink: #111827; --mute: #4b5563; --line: #e5e7eb; --soft: #f9fafb; --f: {!! $bodyFont !!}; --fh: {!! $headingFont !!}; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font-family: var(--f); color: var(--ink); background: #fff; line-height: 1.6; padding-bottom: 4.5rem; }
        img { max-width: 100%; display: block; height: auto; }
        a { color: inherit; }
        .wrap { max-width: 64rem; margin: 0 auto; padding: 0 1.25rem; }
        .top { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 3px solid var(--c); }
        .top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 3.5rem; }
        .brand { font-weight: 800; letter-spacing: -.01em; font-family: var(--fh); display: flex; align-items: center; gap: .6rem; min-width: 0; }
        .brand img { height: 2.25rem; width: auto; max-width: 9rem; object-fit: contain; }
        .btn { display: inline-block; text-decoration: none; font-weight: 700; padding: .8rem 1.4rem; border-radius: .75rem; background: var(--c); color: #fff; min-height: 2.75rem; text-align: center; border: 0; cursor: pointer; font: inherit; font-weight: 700; }
        .btn.wa { background: #16a34a; }
        .btn.sm { padding: .5rem 1rem; min-height: 0; font-size: .875rem; }
        .hero { position: relative; color: #fff; background: var(--c); overflow: hidden; }
        .hero img.bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .hero::before { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.65)); z-index: 1; }
        .hero .wrap { position: relative; z-index: 2; padding-top: 4rem; padding-bottom: 4rem; }
        .hero h1 { font-family: var(--fh); font-size: clamp(1.9rem, 6vw, 3.25rem); line-height: 1.12; margin: 0 0 .75rem; letter-spacing: -.02em; max-width: 40rem; }
        .hero p { font-size: 1.1rem; margin: 0 0 1.5rem; max-width: 36rem; opacity: .95; }
        .hero .btn { background: #fff; color: var(--c); }
        section.blk { padding: 3rem 0; }
        section.blk.alt { background: var(--soft); }
        section.blk.bg-light { background: #fff; }
        section.blk.bg-dark { background: #111827; color: #f9fafb; }
        section.blk.bg-brand { background: var(--c); color: #fff; }
        .bg-dark h2, .bg-brand h2 { color: inherit; }
        .bg-brand h2::after { background: #fff; }
        .bg-dark .mute, .bg-brand .mute { color: rgba(255,255,255,.8); }
        h2 { font-family: var(--fh); font-size: clamp(1.4rem, 4vw, 2rem); margin: 0 0 1.25rem; letter-spacing: -.01em; line-height: 1.2; }
        h2::after { content: ""; display: block; width: 3rem; height: 4px; border-radius: 4px; background: var(--c); margin-top: .5rem; }
        .mute { color: var(--mute); }
        .grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 640px) { .grid.c2 { grid-template-columns: repeat(2, 1fr); } .grid.c3 { grid-template-columns: repeat(3, 1fr); } }
        .card { background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 1rem; padding: 1.1rem 1.2rem; }
        .card h3 { margin: 0 0 .3rem; font-size: 1.05rem; }
        .card p { margin: 0; color: var(--mute); }
        .dot { width: 2.25rem; height: 2.25rem; border-radius: .6rem; background: color-mix(in srgb, var(--c) 14%, #fff); color: var(--c); display: grid; place-items: center; font-weight: 800; margin-bottom: .6rem; }
        .gal { display: grid; gap: .6rem; grid-template-columns: repeat(2, 1fr); }
        @media (min-width: 768px) { .gal { grid-template-columns: repeat(3, 1fr); } }
        .gal img { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: .85rem; }
        table.det { width: 100%; border-collapse: collapse; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 1rem; overflow: hidden; }
        table.det th, table.det td { text-align: left; padding: .8rem 1rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        table.det th { width: 40%; color: var(--mute); font-weight: 600; }
        table.det tr:last-child th, table.det tr:last-child td { border-bottom: 0; }
        .map iframe { width: 100%; height: 20rem; border: 0; border-radius: 1rem; }
        .prose { max-width: 44rem; } .prose p, .prose ul, .prose ol { margin: 0 0 1rem; } .prose a { color: var(--c); text-decoration: underline; }
        .bg-dark .prose a, .bg-brand .prose a { color: inherit; }
        details.q { background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: .85rem; padding: .9rem 1.1rem; margin-bottom: .6rem; }
        details.q summary { cursor: pointer; font-weight: 700; }
        details.q p { margin: .6rem 0 0; color: var(--mute); }
        blockquote { margin: 0; }
        .who { margin-top: .5rem; font-weight: 700; font-size: .9rem; }
        .formbox { max-width: 32rem; margin: 0 auto; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 1.25rem; padding: 1.5rem 1.25rem; box-shadow: 0 10px 30px rgba(17,24,39,.08); }
        .formbox .intro { margin: -.5rem 0 1rem; color: var(--mute); }
        .formbox h2 { color: var(--ink); }
        .price { text-align: center; } .price small { display: block; color: inherit; opacity: .8; }
        .price strong { display: block; font-family: var(--fh); font-size: clamp(2rem, 8vw, 3.2rem); line-height: 1.1; color: var(--c); margin: .15rem 0; }
        .bg-dark .price strong, .bg-brand .price strong { color: inherit; }
        .price .inst { font-weight: 700; }
        .amen { display: flex; gap: .8rem; align-items: flex-start; }
        .amen .dot { margin: 0; flex: none; } .amen h3 { margin: 0; font-size: 1rem; } .amen p { margin: 0; font-size: .9rem; color: var(--mute); }
        .unit h3 { font-family: var(--fh); } .unit .p { font-weight: 800; color: var(--c); margin-top: .4rem; }
        .unit ul { list-style: none; padding: 0; margin: .4rem 0 0; color: var(--mute); font-size: .92rem; }
        .near { list-style: none; padding: 0; margin: 1rem 0 0; display: grid; gap: .4rem; }
        .near li { display: flex; justify-content: space-between; gap: 1rem; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: .7rem; padding: .55rem .9rem; }
        .near b { color: var(--c); white-space: nowrap; }
        .legal li { list-style: none; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: .7rem; padding: .6rem .9rem; display: flex; gap: .6rem; align-items: baseline; }
        .legal ul { padding: 0; margin: 0; display: grid; gap: .5rem; } .legal li::before { content: "\2713"; color: var(--c); font-weight: 800; }
        .kpr { display: grid; gap: .8rem; grid-template-columns: 1fr 1fr; max-width: 34rem; }
        .kpr label { display: grid; gap: .25rem; font-size: .85rem; font-weight: 600; } .kpr input { font: inherit; padding: .65rem .75rem; border: 1px solid var(--line); border-radius: .6rem; min-width: 0; color: var(--ink); }
        .kpr .out { grid-column: 1 / -1; background: #fff; color: var(--ink); border: 2px solid var(--c); border-radius: 1rem; padding: 1rem; text-align: center; }
        .kpr .out strong { display: block; font-size: 1.7rem; color: var(--c); }
        .video { max-width: 40rem; } .video a, .video iframe { display: block; width: 100%; aspect-ratio: 16/9; border: 0; border-radius: 1rem; background: #000; position: relative; overflow: hidden; }
        .video img { width: 100%; height: 100%; object-fit: cover; } .video .play { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font-size: 3rem; text-shadow: 0 2px 10px rgba(0,0,0,.6); }
        .cta { text-align: center; } .cta p { margin: 0 0 1rem; }
        .count { display: flex; gap: .6rem; flex-wrap: wrap; } .count div { min-width: 4.5rem; text-align: center; background: #fff; color: var(--ink); border-radius: .8rem; padding: .6rem .5rem; border: 1px solid var(--line); }
        .count b { display: block; font-size: 1.6rem; color: var(--c); line-height: 1.1; }
        .foot { padding: 2rem 0; text-align: center; color: var(--mute); font-size: .8125rem; }
        .dock { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; display: flex; gap: .6rem; padding: .6rem .8rem calc(.6rem + env(safe-area-inset-bottom)); background: #fff; border-top: 1px solid var(--line); }
        .dock .btn { flex: 1; }
        @media (min-width: 900px) { body { padding-bottom: 0; } .dock { display: none; } }
        .preview-tag { background: #111827; color: #fff; text-align: center; font-size: .8125rem; padding: .35rem; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
    @include('public.partials.tracking')
</head>
<body>
    @if ($preview)<div class="preview-tag">Pratinjau - halaman ini belum tentu sudah terbit</div>@endif
    <header class="top"><div class="wrap">
        <span class="brand">@if ($logo)<img src="{{ $logo }}" alt="{{ $tenant->name }}">@else{{ $tenant->name }}@endif</span>
        <a class="btn sm" href="#daftar">Daftar</a>
    </div></header>

    @foreach ($sections as $section)
        @php
            $p = $section['props'];
            $isHero = $section['type'] === 'hero';
            $bg = $p['bg'] ?? 'auto';
            if (! $isHero) { $n++; }
            $cls = 'blk '.(match ($bg) { 'light' => 'bg-light', 'dark' => 'bg-dark', 'brand' => 'bg-brand', default => ($n % 2 === 0 ? 'alt' : '') });
        @endphp
        @include('public.sections.'.$section['type'], ['p' => $p, 'cls' => $cls])
    @endforeach

    @unless ($hasForm)
        <section class="blk" id="daftar"><div class="wrap"><div class="formbox">
            <h2>{{ $settings['title'] }}</h2>
            <p class="intro">{{ $settings['intro'] }}</p>
            @include('public.partials.lead-form', ['formId' => 'lead-form', 'pageId' => $page->getKey()])
        </div></div></section>
    @endunless

    <footer class="foot"><div class="wrap">&copy; {{ now()->year }} {{ $tenant->name }}</div></footer>

    <nav class="dock" aria-label="Aksi cepat">
        @if ($waLink)<a class="btn wa" href="{{ $waLink }}" rel="noopener">WhatsApp</a>@endif
        <a class="btn" href="#daftar">Daftar</a>
    </nav>

    @include('public.partials.form-assets')
    @if ($usesScript)@include('public.partials.landing-js')@endif
</body>
</html>
