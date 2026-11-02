<x-mail::message>
# Bukti transfer masuk, perlu dicek

Halo,

**{{ $lead->name }}**{{ $lead->phone ? ' ('.$lead->phone.')' : '' }} di agensi **{{ $tenant->name }}** sedang di tahap menunggu pembayaran dan baru saja mengirim gambar lewat WhatsApp. Kemungkinan itu bukti transfer.

Cek gambarnya dan mutasi rekening. Bila dana sudah masuk, tekan **Pembayaran diterima** di Inbox atau di halaman lead agar pesanan lanjut ke tahap dikemas dan dikirim. AI tidak pernah menyatakan pembayaran diterima, jadi pembeli menunggu konfirmasi dari Anda.

<x-mail::button :url="$url">
Buka chat
</x-mail::button>
</x-mail::message>
