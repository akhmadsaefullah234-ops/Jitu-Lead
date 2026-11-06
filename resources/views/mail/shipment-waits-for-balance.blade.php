<x-mail::message>
# {{ $headline }}

Halo,

Kiriman **{{ $lead->name }}** di agensi **{{ $tenant->name }}** ({{ $shipment->courier ?: '-' }}, {{ $shipment->client_order_no }}) belum bisa dibuat resinya karena saldo Everpro kurang.

{{ $detail }}

Isi saldo di dashboard Everpro. Setelah saldo cukup, resi dicoba lagi otomatis tanpa membuat order baru, atau tekan **Coba lagi** di menu Pengiriman > Menunggu saldo.

<x-mail::button :url="$url">
Buka Pengiriman
</x-mail::button>
</x-mail::message>
