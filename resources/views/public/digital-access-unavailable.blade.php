<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Akses belum tersedia - {{ $tenant->name }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; line-height: 1.55; }
        .card { max-width: 30rem; margin: 1.5rem auto; background: #fff; border-radius: 1rem; padding: 1.5rem 1.25rem; }
    </style>
</head>
<body>
<div class="card">
    <h1 style="font-size:1.3rem;margin:0 0 .5rem">Akses belum tersedia</h1>
    <p style="margin:0">Tautan ini tidak bisa dibuka{{ filled($problem ?? null) ? ' ('.$problem.')' : '' }}. Balas chat WhatsApp {{ $tenant->name }}, admin akan membantu.</p>
</div>
</body>
</html>
