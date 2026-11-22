<x-filament-panels::page>
    @if ($this->isProperty())
        <p style="color:var(--gray-600);max-width:46rem">
            Lead properti pindah tahap sendiri mengikuti percakapan: Dihubungi saat klien membalas, Terkualifikasi saat kebutuhannya tercatat,
            Sudah survei setelah jadwal survei lewat, dan Gugur bila klien diam setelah follow-up. Setiap perpindahan otomatis tercatat di aktivitas lead
            ("Pindah otomatis ...") dan memicu follow-up, event iklan, dan label WhatsApp tahap barunya seperti biasa. Agen tetap bisa memindah lead
            secara manual, Booking dan Closing tetap diisi agen, dan lead yang sudah Closing tidak pernah dipindah otomatis.
        </p>
        @if ($this->waitingToStart())
            <div style="max-width:46rem;padding:.75rem 1rem;border-radius:.5rem;background:var(--warning-50);color:var(--warning-800)">
                Otomatisasi tahap belum menyala untuk agensi ini. Klik <b>Nyalakan</b> di kanan atas untuk memakainya. Lead yang ada tidak berubah sampai Anda
                memakai <b>Terapkan ke lead yang ada</b>, yang menampilkan daftar perubahannya dulu.
            </div>
        @endif
    @elseif ($this->isDigital())
        <p style="color:var(--gray-600);max-width:46rem">
            Pembelian produk digital pindah tahap sendiri menurut aturan di bawah. Setiap perpindahan otomatis tercatat di aktivitas lead
            ("Pindah otomatis ...") dan memicu follow-up tahap barunya seperti biasa. Lunas hanya dikonfirmasi orang lewat tombol
            <b>Pembayaran diterima</b> di Inbox atau di lead, tidak pernah oleh pembeli atau AI. Lead yang sudah Lunas tidak pernah dipindah otomatis.
        </p>
    @else
        <p style="color:var(--gray-600);max-width:46rem">
            Lead pindah tahap sendiri menurut aturan di bawah. Setiap perpindahan otomatis tercatat di aktivitas lead ("Pindah otomatis ...") dan
            memicu follow-up tahap barunya seperti biasa. Agen tetap bisa memindah lead secara manual, dan lead yang sudah Selesai tidak pernah dipindah otomatis.
            Tahap dikenali dari urutannya di templat toko, jadi aturan tetap jalan meski nama tahap diubah.
        </p>
    @endif
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
