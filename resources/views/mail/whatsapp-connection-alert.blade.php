<x-mail::message>
@if ($up)
# WhatsApp tersambung lagi

Halo,

Nomor WhatsApp **{{ $channel->name }}** milik agensi **{{ $tenant->name }}** sudah tersambung lagi ke JITU LEAD. Pesan pelanggan kembali masuk ke CRM.

Pesan yang datang selama nomor terputus bisa tidak tercatat di CRM. Cek chat di HP untuk masa itu bila perlu.
@else
# WhatsApp terputus

Halo,

Nomor WhatsApp **{{ $channel->name }}** milik agensi **{{ $tenant->name }}** sudah lebih dari 5 menit tidak tersambung ke JITU LEAD{{ $channel->down_since ? ' (sejak '.$channel->down_since->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB)' : '' }}.

@if (filled($channel->last_error))
Catatan: {{ $channel->last_error }}
@endif

**Selama terputus, pesan pelanggan ke nomor ini tidak masuk ke CRM dan balasan dari CRM tidak terkirim.**

Cara memeriksa: pastikan HP dengan nomor ini menyala dan tersambung ke internet, lalu buka menu Nomor WhatsApp dan tekan **Tes koneksi**. Bila diminta, tekan **Scan QR** dan pindai dari WhatsApp di HP (Perangkat tertaut). Kami akan mengabari lagi saat nomor ini tersambung.
@endif

<x-mail::button :url="$url">
Buka Nomor WhatsApp
</x-mail::button>
</x-mail::message>
