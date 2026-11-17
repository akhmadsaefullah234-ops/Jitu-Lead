<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:48rem">
        Data yang dibaca AI dari dokumen belum dipakai sebelum Anda setujui. Bila dua sumber menyebut nilai berbeda (misalnya luas tanah 72 dan 75 m2),
        keduanya ditandai <b>Bertentangan</b>: pilih <b>Pakai nilai ini</b> pada yang benar. Selama belum diputuskan, AI tidak menyebut data itu dan
        chat soal itu diserahkan ke agen.
    </p>
    {{ $this->table }}
</x-filament-panels::page>
