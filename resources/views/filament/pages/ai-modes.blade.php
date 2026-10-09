<x-filament-panels::page>
    @unless ($this->aiConfigured())
        <div style="padding:.75rem 1rem;border:1px solid #fecaca;background:#fef2f2;border-radius:.6rem;font-size:.9rem">
            AI belum bisa dipakai karena kunci API belum diisi di server (ANTHROPIC_API_KEY). Pilihan di bawah tetap tersimpan.
        </div>
    @endunless
    <div style="font-size:.9rem;line-height:1.6;max-width:48rem">
        <strong>Mati</strong>: semua chat dibalas agen secara manual (bawaan untuk nomor baru).<br>
        <strong>Draft untuk agen</strong>: AI menyiapkan saran balasan di Inbox, agen yang memutuskan mengirim.<br>
        <strong>Balas otomatis</strong>: AI langsung membalas bila jawabannya ada di pengetahuan Anda; selain itu chat diserahkan ke agen.
    </div>
    {{ $this->table }}
</x-filament-panels::page>
