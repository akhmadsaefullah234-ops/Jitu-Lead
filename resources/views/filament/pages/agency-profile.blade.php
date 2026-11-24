<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:46rem">
        Profil ini dipakai bersama oleh landing page (logo, warna, tombol WhatsApp, kaki halaman) dan template pesan
        (<code>{agensi}</code>, <code>{wa_agensi}</code>). Landing page yang sudah mengisi logo, warna, atau nomor sendiri tetap memakai miliknya.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
