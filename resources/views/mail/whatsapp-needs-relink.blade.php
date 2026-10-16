<x-mail::message>
# WhatsApp perlu scan ulang

Halo,

Nomor WhatsApp **{{ $channel->name }}** milik agensi **{{ $tenant->name }}** di JITU LEAD tidak bisa lagi menerima atau mengirim pesan. Login WhatsApp-nya rusak dan perlu disambungkan ulang dengan scan QR.

**Selama belum di-scan ulang, pesan pelanggan ke nomor ini tidak masuk ke CRM.** Pesan yang datang dalam masa itu bisa tidak terbaca di CRM, jadi cek langsung di HP bila perlu.

Cara memulihkan: buka menu Nomor WhatsApp, pilih nomor ini, tekan **Scan QR**, lalu pindai dari WhatsApp di HP (Perangkat tertaut).

<x-mail::button :url="$url">
Buka Nomor WhatsApp
</x-mail::button>
</x-mail::message>
