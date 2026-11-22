<x-mail::message>
# Pembayaran diterima

Halo {{ $lead->name ?: 'Kak' }},

Pembayaran **{{ $lead->property?->name ?? 'produk' }}** di **{{ $tenant->name }}** sudah kami terima. Terima kasih.

Tautan di bawah khusus untuk Anda dan berlaku {{ $days }} hari. Jangan dibagikan ke orang lain.

<x-mail::button :url="$link">
Buka akses
</x-mail::button>

Kalau ada kendala, balas chat WhatsApp {{ $tenant->name }}.
</x-mail::message>
