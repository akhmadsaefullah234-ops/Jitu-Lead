<x-filament-panels::page>
    <div style="max-width:46rem;display:grid;gap:1.2rem">
        <section>
            <h3 style="font-weight:700;margin:0 0 .3rem">Unduh data Anda</h3>
            <p style="color:#4b5563;margin:0">
                Satu berkas ZIP berisi CSV: lead dan aktivitasnya, percakapan dan pesan WhatsApp, properti, landing page, aturan follow-up, pengetahuan dan draf AI, anggota tim, langganan, dan chat support.
                Kata sandi dan kunci integrasi tidak disertakan. Berkas berisi data pribadi calon pembeli, jadi simpan dengan aman. Tersedia kapan saja, juga saat masa percobaan atau langganan berakhir.
            </p>
        </section>
        <section>
            <h3 style="font-weight:700;margin:0 0 .3rem;color:#b91c1c">Hapus agensi</h3>
            <p style="color:#4b5563;margin:0">
                Anda sebagai admin dapat menghapus agensi sendiri tanpa persetujuan pemilik aplikasi. Penghapusan permanen dan berlaku seketika pada data aktif.
                Cadangan harian menyimpan salinan paling lama {{ config('jitu.backup.remote_keep_days') }} hari, lalu hilang otomatis.
            </p>
        </section>
        <p style="color:#6b7280;font-size:.85rem;margin:0">
            Hak lain atas data pribadi (akses, perbaikan, keberatan): lihat <a href="{{ url('/kebijakan-privasi') }}" target="_blank" rel="noopener" style="text-decoration:underline">Kebijakan Privasi</a>.
        </p>
    </div>
</x-filament-panels::page>
