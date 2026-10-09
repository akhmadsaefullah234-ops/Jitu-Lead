<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['title'] }} · JITU LEAD</title>
    <meta name="description" content="{{ $doc['title'] }} JITU LEAD, CRM untuk agen dan agensi properti.">
    <link rel="icon" href="{{ asset('images/icon.png') }}">
    <style>
        :root { --red: #dc2626; --ink: #111827; --muted: #6b7280; --line: #e5e7eb; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--ink); background: #fff; line-height: 1.7; }
        header { border-bottom: 2px solid var(--red); }
        header .wrap { display: flex; align-items: center; justify-content: space-between; height: 3.5rem; }
        header img { height: 2.1rem; display: block; }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 0 1rem; }
        h1 { font-size: 1.8rem; margin: 2rem 0 .3rem; }
        h2 { font-size: 1.15rem; margin: 1.8rem 0 .3rem; color: #991b1b; }
        p, li { color: #1f2937; } li { margin: .25rem 0; }
        .mute { color: var(--muted); font-size: .88rem; }
        .draft { background: #fef3c7; border: 1px solid #f59e0b; color: #78350f; border-radius: .7rem; padding: .7rem .9rem; margin: 1.2rem 0; font-size: .92rem; }
        footer { border-top: 1px solid var(--line); margin-top: 3rem; padding: 1.2rem 0 2rem; font-size: .85rem; color: var(--muted); }
        footer a, .mute a { color: inherit; }
    </style>
</head>
<body>
<header><div class="wrap"><a href="{{ url('/') }}"><img src="{{ asset('images/logo.svg') }}" alt="JITU LEAD"></a><a href="{{ url('/app/login') }}" class="mute">Masuk</a></div></header>
<main class="wrap">
    <h1>{{ $doc['title'] }}</h1>
    <p class="mute">Terakhir diperbarui {{ $doc['updated']->translatedFormat('j F Y') }}</p>
    @unless ($doc['reviewed'])
        <div class="draft" role="note"><b>Rancangan.</b> Teks ini belum ditinjau oleh ahli hukum dan dapat berubah sebelum layanan dirilis penuh.</div>
    @endunless
    @if ($doc['intro'])<p>{{ $doc['intro'] }}</p>@endif
    {!! $doc['html'] !!}
</main>
<footer><div class="wrap"><a href="{{ url('/') }}">Beranda</a> · <a href="{{ \App\Legal\LegalDocs::url('kebijakan-privasi') }}">Kebijakan Privasi</a> · <a href="{{ \App\Legal\LegalDocs::url('syarat-layanan') }}">Syarat Layanan</a></div></footer>
</body>
</html>
