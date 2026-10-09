<div style="display:flex;flex-direction:column;gap:.75rem">
    <p style="font-size:.8125rem;color:var(--gray-600)">Menampilkan versi yang sudah disimpan. Simpan perubahan dulu, lalu buka pratinjau.</p>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;justify-content:center">
        <iframe src="{{ $url }}" title="Pratinjau ponsel" style="width:390px;max-width:100%;height:70vh;border:1px solid var(--gray-300);border-radius:1.25rem;background:#fff"></iframe>
        <iframe src="{{ $url }}" title="Pratinjau desktop" style="flex:1 1 32rem;min-width:0;height:70vh;border:1px solid var(--gray-300);border-radius:.75rem;background:#fff" class="hidden lg:block"></iframe>
    </div>
</div>
