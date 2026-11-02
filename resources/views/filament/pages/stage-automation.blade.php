<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:46rem">
        Lead pindah tahap sendiri menurut aturan di bawah. Setiap perpindahan otomatis tercatat di aktivitas lead ("Pindah otomatis ...") dan
        memicu follow-up tahap barunya seperti biasa. Agen tetap bisa memindah lead secara manual, dan lead yang sudah Selesai tidak pernah dipindah otomatis.
        Tahap dikenali dari urutannya di templat toko, jadi aturan tetap jalan meski nama tahap diubah.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
