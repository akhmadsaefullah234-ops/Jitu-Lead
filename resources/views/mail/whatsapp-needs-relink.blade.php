<x-mail::message>
# WhatsApp perlu scan ulang

Halo,

@if ($removed)
Nomor WhatsApp **{{ $channel->name }}** milik agensi **{{ $tenant->name }}** terputus dari JITU LEAD karena **perangkat tertaut dilepas dari HP** (WhatsApp > Perangkat tertaut > keluar). Nomor ini tidak bisa lagi menerima atau mengirim pesan dari CRM sampai disambungkan ulang dengan scan QR.
@else
Nomor WhatsApp **{{ $channel->name }}** milik agensi **{{ $tenant->name }}** di JITU LEAD tidak bisa lagi menerima atau mengirim pesan. Login WhatsApp-nya rusak dan perlu disambungkan ulang dengan scan QR.
@if (filled($reason))

Penyebab: {{ $reason }}.
@endif
@endif

**Selama belum di-scan ulang, pesan pelanggan ke nomor ini tidak masuk ke CRM.** Pesan yang datang dalam masa itu bisa tidak terbaca di CRM, jadi cek langsung di HP bila perlu.

Cara memulihkan: buka menu Nomor WhatsApp, pilih nomor ini, tekan **Scan QR**, lalu pindai dari WhatsApp di HP (Perangkat tertaut).

<x-mail::button :url="$url">
Buka Nomor WhatsApp
</x-mail::button>
</x-mail::message>
