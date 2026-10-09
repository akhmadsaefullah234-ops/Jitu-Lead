<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $settings['title'] }} - {{ $tenant->name }}</title>
    <style>
        :root { --c: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $settings['color']) ? $settings['color'] : '#dc2626' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #fff; color: #111827; }
        .card { max-width: 30rem; margin: 0 auto; padding: 1.5rem 1.25rem 2rem; }
        h1 { font-size: 1.35rem; margin: 0 0 .35rem; line-height: 1.25; }
        .intro { margin: 0 0 1.25rem; color: #4b5563; }
        .bar { height: 4px; background: var(--c); }
    </style>
    @include('public.partials.tracking')
</head>
<body>
    <div class="bar"></div>
    <main class="card">
        <h1>{{ $settings['title'] }}</h1>
        <p class="intro">{{ $settings['intro'] }}</p>
        @include('public.partials.lead-form', ['formId' => 'lead-form'])
    </main>
    @include('public.partials.form-assets')
</body>
</html>
