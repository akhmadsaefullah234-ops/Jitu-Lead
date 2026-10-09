<x-filament-panels::page>
    @unless ($this->aiConfigured())
        <div style="padding:.75rem 1rem;border:1px solid #fecaca;background:#fef2f2;border-radius:.6rem;font-size:.9rem">
            Layanan AI belum diaktifkan di server ini, jadi pilihan AI belum berjalan. Hubungi penyedia aplikasi. Pilihan Anda di bawah tetap tersimpan.
        </div>
    @endunless
    <div style="font-size:.9rem;line-height:1.7;max-width:48rem">
        Pilih siapa yang membalas chat yang masuk ke tiap nomor WhatsApp. AI sudah tersedia dari sistem ini, Anda tidak perlu memasang apa pun.<br>
        <strong>Saya balas sendiri</strong>: AI tidak ikut campur, agen yang membalas semua chat. Ini bawaan untuk nomor baru.<br>
        <strong>AI menyiapkan, agen yang kirim</strong>: AI menulis saran balasan di Inbox, agen memilih memakainya atau tidak.<br>
        <strong>AI membalas otomatis</strong>: AI langsung membalas klien bila jawabannya ada di pengetahuan Anda. Bila tidak yakin atau klien minta bicara dengan orang, chat diserahkan ke agen. AI berhenti sendiri saat agen mulai mengetik.<br>
        Pilihan bisa diganti kapan saja dan langsung berlaku untuk pesan berikutnya.
    </div>
    {{ $this->table }}
</x-filament-panels::page>
