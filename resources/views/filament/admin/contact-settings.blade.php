<x-filament-panels::page>
    <p style="color:#6b7280;max-width:46rem">
        Kontak usaha ini diminta saat mendaftar ke penyedia pembayaran. Semuanya selalu tampil lengkap di <a href="{{ url('/kontak') }}" target="_blank" style="text-decoration:underline">/kontak</a>, dan email serta alamat dipakai di halaman hukum. Pengaturan "Tampilkan di halaman depan" hanya memengaruhi bagian Kontak di <a href="{{ url('/#kontak') }}" target="_blank" style="text-decoration:underline">halaman depan</a>, yang hilang bila tidak ada yang ditampilkan.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
