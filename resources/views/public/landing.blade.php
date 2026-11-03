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
        body { margin: 0; font-family: var(--f); color: var(--ink); background: #fff; line-height: 1.6; }
        html, body { overflow-x: clip; }
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
        .gif { margin: 0 auto; }
        .gif-medium { max-width: 560px; } .gif-small { max-width: 360px; }
        .gif-grid { display: grid; gap: .6rem; }
        .gif-c2 .gif-grid, .gif-c3 .gif-grid { grid-template-columns: repeat(2, 1fr); }
        @media (min-width: 768px) { .gif-c3 .gif-grid { grid-template-columns: repeat(3, 1fr); } .gif-c2.gif-medium, .gif-c3.gif-medium { max-width: 820px; } }
        .gif img { display: block; width: 100%; height: auto; border-radius: .85rem; }
        .gif figcaption { margin-top: .6rem; text-align: center; font-size: .9rem; opacity: .8; }
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
        .top .jb { flex: none; }
        .dock { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; display: flex; gap: .6rem; padding: .6rem .8rem calc(.6rem + env(safe-area-inset-bottom)); background: #fff; border-top: 1px solid var(--line); }
        .dock .jb { flex: 1 1 0; min-width: 0; padding-left: .6rem; padding-right: .6rem; }
        body.has-dock { padding-bottom: 4.5rem; }
        .waf { position: fixed; right: 1rem; bottom: calc(1rem + env(safe-area-inset-bottom)); z-index: 31; width: 3.5rem; height: 3.5rem; border-radius: 50%; background: #16a34a; color: #fff; display: grid; place-items: center; box-shadow: 0 6px 20px rgba(0,0,0,.25); }
        body.has-dock .waf { bottom: calc(5rem + env(safe-area-inset-bottom)); }
        @media (min-width: 900px) { body.has-dock { padding-bottom: 0; } .dock { display: none; } body.has-dock .waf { bottom: 1.5rem; } }
        .prd-g { display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 800px) { .prd-g { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; gap: 2rem; } }
        .prd-media, .prd-info { min-width: 0; }
        .sl { display: flex; gap: .5rem; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; border-radius: 1rem; -webkit-overflow-scrolling: touch; }
        .sl::-webkit-scrollbar { display: none; }
        .sl[hidden] { display: none; }
        .sl img { flex: 0 0 100%; width: 100%; aspect-ratio: 1 / 1; object-fit: cover; scroll-snap-align: center; border-radius: 1rem; background: var(--soft); }
        .sl-hint { margin: .4rem 0 0; font-size: .8rem; color: var(--mute); text-align: center; }
        .prd-name { font-family: var(--fh); font-size: clamp(1.5rem, 5vw, 2.2rem); line-height: 1.2; margin: .3rem 0 .5rem; letter-spacing: -.01em; overflow-wrap: anywhere; }
        .prd-name::after { display: none; }
        .off { display: inline-block; background: #dc2626; color: #fff; font-weight: 800; font-size: .85rem; padding: .2rem .6rem; border-radius: 999px; }
        .prc { display: flex; flex-wrap: wrap; align-items: baseline; gap: .2rem .7rem; margin-bottom: .5rem; }
        .prc s { color: var(--mute); font-size: 1rem; }
        .prc strong { font-size: clamp(1.6rem, 6vw, 2.1rem); color: var(--c); line-height: 1.15; }
        .bg-dark .prc strong, .bg-brand .prc strong, .bg-dark .prc s, .bg-brand .prc s { color: inherit; }
        .stk { display: inline-block; margin: 0 0 .8rem; font-weight: 700; font-size: .9rem; padding: .15rem .6rem; border-radius: .5rem; background: #dcfce7; color: #166534; }
        .stk-low { background: #fef3c7; color: #92400e; } .stk-preorder { background: #e0e7ff; color: #3730a3; }
        .opt { margin: 0 0 .9rem; } .opt-l { margin: 0 0 .4rem; font-size: .9rem; }
        .chips { display: flex; flex-wrap: wrap; gap: .45rem; }
        .chip { font: inherit; font-size: .9rem; display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .8rem; min-height: 2.5rem; border-radius: .7rem; border: 2px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; }
        .chip i { width: 1.1rem; height: 1.1rem; border-radius: 50%; border: 1px solid rgba(0,0,0,.2); flex: none; }
        .chip[aria-pressed="true"] { border-color: var(--c); box-shadow: 0 0 0 1px var(--c); }
        .pts { list-style: none; padding: 0; margin: 0 0 1rem; display: grid; gap: .35rem; }
        .pts li { display: flex; gap: .5rem; } .pts li::before { content: "\2713"; color: var(--c); font-weight: 800; flex: none; }
        .rev img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: .7rem; margin-bottom: .6rem; }
        .rev .stars { color: #f59e0b; letter-spacing: .1em; } .rev .stars span { color: var(--line); }
        figure.rev { margin: 0; }
        .hero .assure { font-size: .9rem; margin: .9rem 0 0; opacity: .9; }
        .mx { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); align-items: center; }
        .mx > * { min-width: 0; }
        @media (min-width: 900px) { .hero-m .mx { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
        .hero-m .wrap { padding-top: 2.5rem; padding-bottom: 2.5rem; }
        .hero-vid { width: 100%; border-radius: 1rem; background: #000; display: block; }
        .sld { position: relative; --pv: var(--pvm); }
        @media (min-width: 640px) { .sld { --pv: var(--pvt); } }
        @media (min-width: 1024px) { .sld { --pv: var(--pvd); } }
        .sl-track { display: flex; gap: var(--sg); overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
        .sl-track::-webkit-scrollbar { display: none; }
        .slide { margin: 0; flex: 0 0 calc((100% - (var(--pv) - 1) * var(--sg)) / var(--pv)); scroll-snap-align: start; position: relative; min-width: 0; }
        .slide a { display: block; }
        .sl-img { width: 100%; aspect-ratio: var(--sar, auto); object-fit: var(--sf); border-radius: var(--sr); background: var(--soft); }
        .slide figcaption { position: absolute; left: 0; right: 0; bottom: 0; padding: 1rem; color: #fff; background: linear-gradient(transparent, rgba(0,0,0,.65)); border-radius: 0 0 var(--sr) var(--sr); display: grid; gap: .25rem; text-align: left; }
        .slide figcaption strong { font-size: 1.1rem; }
        .sld.fade .sl-track { display: grid; overflow: hidden; }
        .sld.fade .slide { grid-area: 1 / 1; opacity: 0; visibility: hidden; transition: opacity .5s; }
        .sld.fade .slide.on { opacity: 1; visibility: visible; }
        .sl-arrow { position: absolute; top: 50%; transform: translateY(-50%); width: 2.5rem; height: 2.5rem; border-radius: 50%; border: 0; background: rgba(255,255,255,.9); color: #111827; font-size: 1.6rem; line-height: 1; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
        .sl-arrow.prev { left: .5rem; } .sl-arrow.next { right: .5rem; }
        .sl-dots { display: flex; justify-content: center; gap: .1rem; margin-top: .4rem; }
        .sl-dots button { width: 1.6rem; height: 1.6rem; border: 0; background: none; cursor: pointer; padding: 0; display: grid; place-items: center; }
        .sl-dots button::before { content: ""; width: .5rem; height: .5rem; border-radius: 50%; background: var(--line); }
        .sl-dots button[aria-current="true"]::before { background: var(--c); }
        .ps ul { list-style: none; padding: 0; margin: .4rem 0 0; display: grid; gap: .55rem; }
        .ps li { display: flex; gap: .6rem; align-items: flex-start; color: var(--ink); }
        .ps-i { flex: none; width: 1.6rem; height: 1.6rem; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: .9rem; }
        .ps-bad .ps-i { background: #fee2e2; color: #b91c1c; } .ps-good .ps-i { background: #dcfce7; color: #15803d; }
        .ps-good { border-color: color-mix(in srgb, var(--c) 40%, var(--line)); }
        .ic-i { flex: none; width: calc(var(--ics, 28px) * 1.75); height: calc(var(--ics, 28px) * 1.75); display: inline-grid; place-items: center; font-size: var(--ics, 28px); line-height: 1; color: var(--icc, var(--c)); background: var(--icb, color-mix(in srgb, var(--c) 14%, #fff)); border-radius: .7rem; }
        .ic-i svg { width: var(--ics, 22px); height: var(--ics, 22px); }
        .ic-i .ic-img { width: var(--ics, 28px); height: var(--ics, 28px); object-fit: contain; }
        .ben .ic-i { margin-bottom: .6rem; --ics: 22px; }
        .ic { list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem; grid-template-columns: repeat(var(--icm), minmax(0, 1fr)); }
        @media (min-width: 768px) { .ic { grid-template-columns: repeat(var(--icd), minmax(0, 1fr)); } }
        .ic li, .ic-a { display: flex; gap: .8rem; align-items: flex-start; min-width: 0; text-decoration: none; color: inherit; }
        .ic-pos-top li, .ic-pos-top .ic-a { flex-direction: column; }
        .ic-pos-right li, .ic-pos-right .ic-a { flex-direction: row-reverse; }
        .ic-grid li { background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 1rem; padding: 1rem; }
        .ic-circle .ic-i { border-radius: 50%; } .ic-none .ic-i { background: none; width: auto; height: auto; }
        .ic-div li { border-bottom: 1px solid var(--line); padding-bottom: 1rem; }
        .ic-t { display: grid; gap: .15rem; min-width: 0; overflow-wrap: anywhere; } .ic-t span { color: var(--mute); font-size: .92rem; }
        .stats { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .stats div { flex: 1 1 8rem; text-align: center; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 1rem; padding: .9rem; }
        .stats b { display: block; font-family: var(--fh); font-size: clamp(1.6rem, 6vw, 2.4rem); color: var(--c); line-height: 1.1; }
        .stats span { font-size: .9rem; color: var(--mute); }
        .who-row { display: flex; gap: .6rem; align-items: center; margin-top: .6rem; } .who-row small { display: block; color: var(--mute); }
        img.ava { width: 2.75rem; height: 2.75rem; border-radius: 50%; object-fit: cover; margin: 0; aspect-ratio: 1; }
        .logos-t { text-align: center; margin: 1.75rem 0 .75rem; }
        .logos { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 1.25rem 2rem; }
        .logos img { height: 2.5rem; width: auto; max-width: 8rem; object-fit: contain; filter: grayscale(1); opacity: .8; }
        .assures { list-style: none; padding: 0; margin: 0 0 1.2rem; display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem .9rem; font-weight: 600; }
        .assures li::before { content: "\2713  "; color: var(--c); }
        .bg-brand .assures li::before, .bg-dark .assures li::before { color: inherit; }
        .js .bx-anim { opacity: 0; transition: opacity .7s ease, transform .7s ease; }
        .js .bx-slide { transform: translateY(1.5rem); }
        .js .bx-anim.in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .js .bx-anim { opacity: 1; transform: none; transition: none; } }
        .preview-tag { background: #111827; color: #fff; text-align: center; font-size: .8125rem; padding: .35rem; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
    @if ($blockCss !== '')<style>{!! $blockCss !!}</style>@endif
    @if ($animated)<script>document.documentElement.classList.add('js');</script>@endif
    @include('public.partials.button-styles')
    @include('public.partials.tracking')
</head>
<body @if ($nav['dock']) class="has-dock" @endif>
    @if ($preview)<div class="preview-tag">Pratinjau - halaman ini belum tentu sudah terbit</div>@endif
    <header class="top"><div class="wrap">
        <span class="brand">@if ($logo)<img src="{{ $logo }}" alt="{{ $tenant->name }}">@else{{ $tenant->name }}@endif</span>
        @if ($nav['header'])@include('public.partials.button', ['b' => $nav['header'], 'ctx' => $btnCtx, 'tracking' => $tracking])@endif
    </div></header>

    @foreach ($sections as $section)
        @php
            $p = $section['props'];
            $isHero = $section['type'] === 'hero';
            $bg = $p['bg'] ?? 'auto';
            if (! $isHero) { $n++; }
            $cls = 'blk '.(match ($bg) { 'light' => 'bg-light', 'dark' => 'bg-dark', 'brand' => 'bg-brand', default => ($n % 2 === 0 ? 'alt' : '') });
        @endphp
        @if (isset($styled[$loop->index]))
            <div class="bx {{ $styled[$loop->index] }}" id="bx-{{ $loop->iteration }}">@include('public.sections.'.$section['type'], ['p' => $p, 'cls' => $cls, 'isFirst' => $loop->first])</div>
        @else
            @include('public.sections.'.$section['type'], ['p' => $p, 'cls' => $cls, 'isFirst' => $loop->first])
        @endif
    @endforeach

    @if ($showPrimaryForm && $primaryForm)
        <section class="blk" id="daftar"><div class="wrap"><div class="formbox">
            <h2>{{ $primaryForm->design('title', 'Daftar') }}</h2>
            @if (filled($primaryForm->design('description')))<p class="intro">{{ $primaryForm->design('description') }}</p>@endif
            @include('public.partials.built-form', \App\LeadForms\FormView::block($primaryForm, $tenant, $tracking, $page->getKey(), 'daftar-form', null, $btnCtx['vars']['halaman'] ?? null, $btnCtx['vars']))
        </div></div></section>
    @endif

    <footer class="foot"><div class="wrap">&copy; {{ now()->year }} {{ $tenant->name }} · <a href="{{ url('/kebijakan-privasi') }}" rel="noopener" target="_blank" style="color:inherit">Kebijakan Privasi</a> · <a href="{{ url('/syarat-layanan') }}" rel="noopener" target="_blank" style="color:inherit">Syarat Layanan</a></div></footer>

    @if ($nav['dock'])
        <nav class="dock" aria-label="Aksi cepat">@foreach ($nav['dock'] as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</nav>
    @endif
    @if ($nav['float'])
        @php
            $wf = \App\LandingPages\Buttons::link($nav['float'], $btnCtx);
            $wfPx = \App\LandingPages\Buttons::pixel($nav['float'], $tracking);
        @endphp
        <a class="waf" href="{{ $wf['href'] }}" rel="noopener" data-action="whatsapp" aria-label="Chat WhatsApp" @if ($wfPx) data-px="{{ $wfPx }}" @endif><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\LandingPages\Buttons::iconPath('whatsapp') }}"/></svg></a>
    @endif

    @include('public.partials.form-assets')
    @include('public.partials.pixel-js', ['pagePx' => $pagePx])
    @if ($usesScript)@include('public.partials.landing-js')@endif
</body>
</html>
