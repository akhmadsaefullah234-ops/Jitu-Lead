<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · JITU LEAD</title>
    <meta name="description" content="@yield('description')">
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
        footer nav { display: flex; flex-wrap: wrap; gap: .4rem 1rem; }
        .faq details { border: 1px solid var(--line); border-radius: .7rem; padding: .2rem 1rem; margin: .6rem 0; }
        .faq summary { cursor: pointer; font-weight: 600; padding: .7rem 0; }
        .faq details p { margin: 0 0 .9rem; }
        dl.contact { display: grid; gap: .8rem; margin: 1.5rem 0; }
        dl.contact div { border: 1px solid var(--line); border-radius: .8rem; padding: .8rem 1rem; }
        dl.contact dt { font-size: .8rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        dl.contact dd { margin: .15rem 0 0; font-weight: 600; overflow-wrap: anywhere; }
    </style>
</head>
<body>
<header><div class="wrap"><a href="{{ url('/') }}"><img src="{{ asset('images/logo.svg') }}" alt="JITU LEAD"></a><a href="{{ url('/app/login') }}" class="mute">Masuk</a></div></header>
<main class="wrap">
@yield('content')
</main>
@include('partials.public-footer')
</body>
</html>
