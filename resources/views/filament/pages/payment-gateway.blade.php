<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:46rem">
        Dengan pembayaran otomatis menyala, pembelian yang dikonfirmasi pembeli mendapat tautan bayar QRIS atau virtual account dari sistem lewat WhatsApp.
        Lead pindah ke <b>Lunas & akses terkirim</b> hanya setelah iPaymu sendiri menyatakan lunas, lalu pembeli menerima tautan akses bertanda tangan
        lewat WhatsApp dan email. Klaim pembeli atau AI tidak pernah dianggap lunas. Refund tetap diproses manual oleh admin.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
