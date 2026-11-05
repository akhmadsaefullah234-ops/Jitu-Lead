<x-mail::message>
# {{ $headline }}

Halo,

Paket **{{ $lead->name }}** di agensi **{{ $tenant->name }}** (resi {{ $shipment->awb ?: '-' }}, {{ $shipment->courier ?: '-' }}) perlu dicek.

{{ $detail }}

Hubungi pembeli untuk memastikan alamat lengkap atau nomor yang bisa dihubungi kurir, lalu sampaikan ke kurir lewat dashboard Everpro.

<x-mail::button :url="$url">
Buka chat
</x-mail::button>
</x-mail::message>
