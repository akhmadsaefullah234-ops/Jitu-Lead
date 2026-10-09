<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827;max-width:36rem">
    <p>Halo,</p>
    <p>{{ $trial ? 'Masa percobaan' : 'Langganan' }} agensi <b>{{ $tenant->name }}</b> di JITU LEAD berakhir pada <b>{{ $ends->translatedFormat('j F Y') }}</b> ({{ $days }} hari lagi).</p>
    <p>Setelah itu ada masa tenggang {{ $grace }} hari. Lewat dari itu akun menjadi hanya-baca: data tetap aman dan bisa dilihat, tetapi tidak bisa ditambah atau diubah, dan AI serta follow-up otomatis berhenti.</p>
    <p><a href="{{ $url }}" style="background:#dc2626;color:#fff;padding:.6rem 1.1rem;border-radius:.5rem;text-decoration:none;font-weight:bold">Pilih paket</a></p>
    <p style="color:#6b7280;font-size:.85rem">Email otomatis dari JITU LEAD.</p>
</div>
