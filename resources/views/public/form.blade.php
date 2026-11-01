<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} - {{ $tenant->name }}</title>
    <meta name="theme-color" content="{{ $color }}">
    <style>
        :root { --c: {{ $color }}; --f: {!! $bodyFont !!}; --fh: {!! $headingFont !!}; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: var(--f); color: #111827; line-height: 1.55; min-height: 100vh; overflow-x: hidden; }
        body.bg-white { background: #fff; }
        body.bg-soft { background: #f3f4f6; }
        body.bg-tint { background: color-mix(in srgb, var(--c) 10%, #fff); }
        body.bg-brand { background: var(--c); }
        body.bg-dark { background: #111827; }
        body.has-img { background-size: cover; background-position: center; background-attachment: fixed; }
        img { max-width: 100%; display: block; height: auto; }
        .shell { width: 100%; max-width: 32rem; margin: 0 auto; padding: 1rem .75rem 2rem; }
        .card { background: #fff; border-radius: 1.1rem; overflow: hidden; box-shadow: 0 10px 30px rgba(17, 24, 39, .08); }
        body.bg-white .card { box-shadow: none; border: 1px solid #e5e7eb; }
        .banner { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; }
        .bar { height: 5px; background: var(--c); }
        .body { padding: 1.35rem 1.15rem 1.5rem; }
        h1 { font-family: var(--fh); font-size: 1.45rem; margin: 0 0 .4rem; line-height: 1.25; letter-spacing: -.01em; overflow-wrap: anywhere; }
        .desc { margin: 0 0 1.2rem; color: #4b5563; white-space: pre-line; overflow-wrap: anywhere; }
        .preview-tag { background: #111827; color: #fff; text-align: center; font-size: .8125rem; padding: .35rem; }
        .foot { text-align: center; font-size: .75rem; color: #6b7280; margin-top: 1rem; }
        body.bg-brand .foot, body.bg-dark .foot, body.has-img .foot { color: rgba(255, 255, 255, .85); }
        @media (min-width: 640px) { .shell { padding-top: 2rem; } .body { padding: 1.75rem 1.75rem 2rem; } }
    </style>
    @include('public.partials.button-styles')
    @include('public.partials.tracking')
</head>
<body class="bg-{{ $background }} {{ $backgroundImage ? 'has-img' : '' }}" @if ($backgroundImage) style="background-image: url('{{ $backgroundImage }}')" @endif>
    @if ($preview)<div class="preview-tag">Pratinjau formulir</div>@endif
    <main class="shell">
        <div class="card">
            @if ($banner)
                <img class="banner" src="{{ $banner }}" alt="" fetchpriority="high" decoding="async">
            @else
                <div class="bar"></div>
            @endif
            <div class="body">
                <h1>{{ $title }}</h1>
                @if (filled($description))<p class="desc">{{ $description }}</p>@endif
                @include('public.partials.built-form', $block)
            </div>
        </div>
        <p class="foot">{{ $tenant->name }}</p>
    </main>
    @include('public.partials.form-assets')
    @include('public.partials.pixel-js', ['pagePx' => $pagePx])
</body>
</html>
