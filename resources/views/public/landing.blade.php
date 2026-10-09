@php
    $color = preg_match(\App\Models\LandingPage::COLOR_PATTERN, $page->color) ? $page->color : '#dc2626';
    $img = fn (?string $path) => $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
    $has = fn (string $type) => collect($blocks)->contains(fn ($b) => $b['type'] === $type);
    $wa = collect($blocks)->firstWhere('type', 'whatsapp');
    $waNumber = $wa ? preg_replace('/\D+/', '', (string) ($wa['data']['number'] ?? '')) : '';
    $waLink = $waNumber ? 'https://wa.me/'.$waNumber.'?text='.rawurlencode((string) ($wa['data']['message'] ?? 'Halo, saya tertarik dengan '.$page->title)) : null;
    $mapOk = fn (?string $u) => is_string($u) && preg_match('#^https://www\.google\.com/maps/embed\?#', $u);
    $og = $img(collect($blocks)->firstWhere('type', 'hero')['data']['image'] ?? null);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->title }} - {{ $tenant->name }}</title>
    <meta name="description" content="{{ $page->description ?: \Illuminate\Support\Str::limit(strip_tags((string) ($blocks[0]['data']['subheadline'] ?? $page->title)), 150) }}">
    @if ($preview)<meta name="robots" content="noindex">@endif
    <meta property="og:title" content="{{ $page->title }}">
    <meta property="og:description" content="{{ $page->description }}">
    @if ($og)<meta property="og:image" content="{{ $og }}">@endif
    <meta name="theme-color" content="{{ $color }}">
    <style>
        :root { --c: {{ $color }}; --ink: #111827; --mute: #4b5563; --line: #e5e7eb; --soft: #f9fafb; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #fff; line-height: 1.6; padding-bottom: 4.5rem; }
        img { max-width: 100%; display: block; }
        a { color: inherit; }
        .wrap { max-width: 64rem; margin: 0 auto; padding: 0 1.25rem; }
        .top { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 3px solid var(--c); }
        .top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 3.5rem; }
        .brand { font-weight: 800; letter-spacing: -.01em; }
        .btn { display: inline-block; text-decoration: none; font-weight: 700; padding: .8rem 1.4rem; border-radius: .75rem; background: var(--c); color: #fff; min-height: 2.75rem; }
        .btn.alt { background: #fff; color: var(--c); box-shadow: inset 0 0 0 2px var(--c); }
        .btn.sm { padding: .5rem 1rem; min-height: 0; font-size: .875rem; }
        .hero { position: relative; color: #fff; background: var(--c); overflow: hidden; }
        .hero img.bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .hero::before { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.65)); z-index: 1; }
        .hero .wrap { position: relative; z-index: 2; padding-top: 4rem; padding-bottom: 4rem; }
        .hero h1 { font-size: clamp(1.9rem, 6vw, 3.25rem); line-height: 1.12; margin: 0 0 .75rem; letter-spacing: -.02em; max-width: 40rem; }
        .hero p { font-size: 1.1rem; margin: 0 0 1.5rem; max-width: 36rem; opacity: .95; }
        .hero .btn { background: #fff; color: var(--c); }
        section.blk { padding: 3rem 0; }
        section.blk:nth-of-type(even) { background: var(--soft); }
        h2 { font-size: clamp(1.4rem, 4vw, 2rem); margin: 0 0 1.25rem; letter-spacing: -.01em; line-height: 1.2; }
        h2::after { content: ""; display: block; width: 3rem; height: 4px; border-radius: 4px; background: var(--c); margin-top: .5rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 640px) { .grid.c2 { grid-template-columns: repeat(2, 1fr); } .grid.c3 { grid-template-columns: repeat(3, 1fr); } }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 1rem; padding: 1.1rem 1.2rem; }
        .card h3 { margin: 0 0 .3rem; font-size: 1.05rem; }
        .card p { margin: 0; color: var(--mute); }
        .card .dot { width: 2rem; height: 2rem; border-radius: .6rem; background: color-mix(in srgb, var(--c) 14%, #fff); color: var(--c); display: grid; place-items: center; font-weight: 800; margin-bottom: .6rem; }
        .gal { display: grid; gap: .6rem; grid-template-columns: repeat(2, 1fr); }
        @media (min-width: 768px) { .gal { grid-template-columns: repeat(3, 1fr); } }
        .gal img { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: .85rem; }
        table.det { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 1rem; overflow: hidden; }
        table.det th, table.det td { text-align: left; padding: .8rem 1rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        table.det th { width: 40%; color: var(--mute); font-weight: 600; }
        table.det tr:last-child th, table.det tr:last-child td { border-bottom: 0; }
        .map iframe { width: 100%; height: 20rem; border: 0; border-radius: 1rem; }
        .prose p { margin: 0 0 1rem; max-width: 44rem; }
        details.q { background: #fff; border: 1px solid var(--line); border-radius: .85rem; padding: .9rem 1.1rem; margin-bottom: .6rem; }
        details.q summary { cursor: pointer; font-weight: 700; }
        details.q p { margin: .6rem 0 0; color: var(--mute); }
        blockquote { margin: 0; }
        .who { margin-top: .5rem; font-weight: 700; font-size: .9rem; }
        .formbox { max-width: 32rem; margin: 0 auto; background: #fff; border: 1px solid var(--line); border-radius: 1.25rem; padding: 1.5rem 1.25rem; box-shadow: 0 10px 30px rgba(17,24,39,.08); }
        .formbox .intro { margin: -.5rem 0 1rem; color: var(--mute); }
        .foot { padding: 2rem 0; text-align: center; color: var(--mute); font-size: .8125rem; }
        .dock { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; display: flex; gap: .6rem; padding: .6rem .8rem calc(.6rem + env(safe-area-inset-bottom)); background: #fff; border-top: 1px solid var(--line); }
        .dock .btn { flex: 1; text-align: center; }
        .dock .wa { background: #16a34a; }
        @media (min-width: 900px) { body { padding-bottom: 0; } .dock { display: none; } }
        .preview-tag { background: #111827; color: #fff; text-align: center; font-size: .8125rem; padding: .35rem; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
    @include('public.partials.tracking')
</head>
<body>
    @if ($preview)<div class="preview-tag">Pratinjau - halaman ini belum tentu sudah terbit</div>@endif
    <header class="top"><div class="wrap">
        <span class="brand">{{ $tenant->name }}</span>
        <a class="btn sm" href="#daftar">Daftar</a>
    </div></header>

    @foreach ($blocks as $b)
        @php($d = $b['data'])
        @switch($b['type'])
            @case('hero')
                <section class="hero">
                    @if ($img($d['image'] ?? null))<img class="bg" src="{{ $img($d['image']) }}" alt="" fetchpriority="high">@endif
                    <div class="wrap">
                        <h1>{{ $d['headline'] ?? $page->title }}</h1>
                        @if (filled($d['subheadline'] ?? null))<p>{{ $d['subheadline'] }}</p>@endif
                        <a class="btn" href="#daftar">{{ $d['cta_label'] ?? 'Daftar sekarang' }}</a>
                    </div>
                </section>
                @break

            @case('highlights')
                <section class="blk"><div class="wrap">
                    @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                    <div class="grid c3">
                        @foreach ((array) ($d['items'] ?? []) as $i => $it)
                            <div class="card"><div class="dot">{{ $i + 1 }}</div><h3>{{ $it['title'] ?? '' }}</h3><p>{{ $it['text'] ?? '' }}</p></div>
                        @endforeach
                    </div>
                </div></section>
                @break

            @case('gallery')
                @if (! empty($d['images']))
                    <section class="blk"><div class="wrap">
                        @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                        <div class="gal">
                            @foreach ((array) $d['images'] as $path)
                                <img src="{{ $img($path) }}" alt="" loading="lazy">
                            @endforeach
                        </div>
                    </div></section>
                @endif
                @break

            @case('details')
                <section class="blk"><div class="wrap">
                    @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                    <table class="det"><tbody>
                        @foreach ((array) ($d['rows'] ?? []) as $row)
                            <tr><th>{{ $row['label'] ?? '' }}</th><td>{{ $row['value'] ?? '' }}</td></tr>
                        @endforeach
                    </tbody></table>
                </div></section>
                @break

            @case('location')
                <section class="blk"><div class="wrap">
                    @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                    @if (filled($d['address'] ?? null))<p style="margin:0 0 1rem">{{ $d['address'] }}</p>@endif
                    @if ($mapOk($d['map_url'] ?? null))<div class="map"><iframe src="{{ $d['map_url'] }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Peta lokasi"></iframe></div>@endif
                </div></section>
                @break

            @case('text')
                <section class="blk"><div class="wrap prose">
                    @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                    @foreach (preg_split('/\R{2,}/', trim((string) ($d['body'] ?? ''))) as $para)
                        <p>{!! nl2br(e($para)) !!}</p>
                    @endforeach
                </div></section>
                @break

            @case('faq')
                <section class="blk"><div class="wrap">
                    @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                    @foreach ((array) ($d['items'] ?? []) as $it)
                        <details class="q"><summary>{{ $it['q'] ?? '' }}</summary><p>{{ $it['a'] ?? '' }}</p></details>
                    @endforeach
                </div></section>
                @break

            @case('testimonials')
                <section class="blk"><div class="wrap">
                    @if (filled($d['heading'] ?? null))<h2>{{ $d['heading'] }}</h2>@endif
                    <div class="grid c2">
                        @foreach ((array) ($d['items'] ?? []) as $it)
                            <div class="card"><blockquote><p>&ldquo;{{ $it['quote'] ?? '' }}&rdquo;</p></blockquote><div class="who">{{ $it['name'] ?? '' }}</div></div>
                        @endforeach
                    </div>
                </div></section>
                @break

            @case('form')
                <section class="blk" id="daftar"><div class="wrap">
                    <div class="formbox">
                        <h2>{{ $d['heading'] ?? $settings['title'] }}</h2>
                        @if (filled($d['intro'] ?? $settings['intro']))<p class="intro">{{ $d['intro'] ?? $settings['intro'] }}</p>@endif
                        @include('public.partials.lead-form', ['formId' => 'lead-form', 'pageId' => $page->getKey(), 'settings' => array_replace($settings, array_filter(['button' => $d['button'] ?? null]))])
                    </div>
                </div></section>
                @break

            @case('whatsapp')
                @if ($waLink)
                    <section class="blk"><div class="wrap" style="text-align:center">
                        <a class="btn" style="background:#16a34a" href="{{ $waLink }}" rel="noopener">{{ $d['label'] ?? 'Chat via WhatsApp' }}</a>
                    </div></section>
                @endif
                @break
        @endswitch
    @endforeach

    @unless ($has('form'))
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
</body>
</html>
