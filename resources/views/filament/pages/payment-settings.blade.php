<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:46rem">
        Isi rekening bank usaha Anda di sini agar instruksi bayar transfer dikirim otomatis oleh sistem begitu pesanan transfer dikonfirmasi lewat chat.
        AI tidak menulis nomor rekening sendiri, dan tetap tidak pernah menyatakan pembayaran diterima: admin yang mengecek bukti transfer.
    </p>
    <form wire:submit="save" style="display:grid;gap:1rem">
        {{ $this->form }}
        <div><x-filament::button type="submit">Simpan pembayaran</x-filament::button></div>
    </form>
</x-filament-panels::page>
