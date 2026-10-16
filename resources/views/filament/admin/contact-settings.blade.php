<x-filament-panels::page>
    <p style="color:#6b7280;max-width:46rem">
        Kontak usaha ini diminta saat mendaftar ke penyedia pembayaran. Yang diaktifkan "Tampilkan di halaman depan" muncul di bagian Kontak di <a href="{{ url('/#kontak') }}" target="_blank" style="text-decoration:underline">halaman depan</a>; yang dimatikan hanya tersimpan di sini. Bagian Kontak hilang bila tidak ada yang ditampilkan.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
