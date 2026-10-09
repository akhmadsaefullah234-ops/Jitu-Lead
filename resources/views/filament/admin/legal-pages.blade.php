<x-filament-panels::page>
    <p style="color:#6b7280;max-width:46rem">
        Teks bawaan ditulis dari cara kerja aplikasi dan <b>harus ditinjau ahli hukum</b> sebelum rilis. Setelah ditinjau dan disesuaikan, nyalakan "Sudah ditinjau" agar pita rancangan hilang.
        Lihat hasilnya di <a href="{{ url('/kebijakan-privasi') }}" target="_blank" style="text-decoration:underline">/kebijakan-privasi</a> dan <a href="{{ url('/syarat-layanan') }}" target="_blank" style="text-decoration:underline">/syarat-layanan</a>.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
