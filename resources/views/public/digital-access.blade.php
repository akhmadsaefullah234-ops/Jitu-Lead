<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Akses {{ $product?->name ?? 'produk' }} - {{ $tenant->name }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; line-height: 1.55; }
        main { max-width: 30rem; margin: 1.5rem auto; padding: 0 1rem; }
        .card { background: #fff; border-radius: 1rem; padding: 1.5rem 1.25rem; }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        .muted { color: #4b5563; font-size: .95rem; }
        .btn { display: block; text-align: center; background: #047857; color: #fff; font-weight: 600; font-size: 1.05rem; text-decoration: none; padding: .9rem 1rem; border-radius: .75rem; margin: 1.25rem 0 .75rem; }
        .btn:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }
        .hint { background: #fef3c7; border-radius: .75rem; padding: .75rem 1rem; font-size: .95rem; }
        ul { padding-left: 1.2rem; margin: .5rem 0 0; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Halo, {{ $lead->name ?: 'Kak' }} 👋</h1>
        <p class="muted" style="margin:0">Terima kasih sudah membeli <strong>{{ $product?->name ?? 'produk' }}</strong> dari {{ $tenant->name }}. Tautan ini khusus untuk Anda.</p>

        <a class="btn" href="{{ $downloadUrl }}" rel="noopener noreferrer">{{ $file ? 'Unduh '.($product?->name ?? 'produk') : 'Buka '.($product?->name ?? 'produk') }}</a>

        <div class="hint" role="note">
            <strong>Buka dengan Chrome.</strong> Di HP Android, buka tautan ini di Chrome lalu tekan tombol di atas.
            @if ($file) Setelah terunduh, buka berkasnya dari Chrome (menu ⋮ &gt; Download). @endif
        </div>

        <ul class="muted">
            <li>Sisa {{ $left }} dari {{ $access->max_downloads }} kali {{ $file ? 'unduh' : 'buka' }}.</li>
            <li>Berlaku sampai {{ $until }}.</li>
            <li>Mohon tidak membagikan tautan ini. Ada kendala? Balas chat WhatsApp {{ $tenant->name }}.</li>
        </ul>
    </div>
</main>
</body>
</html>
