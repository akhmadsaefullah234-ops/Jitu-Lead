<x-mail::message>
# AI gagal membalas chat

Halo,

Asisten AI agensi **{{ $tenant->name }}** tidak bisa membalas chat WhatsApp klien: {{ $reason }}. AI sudah mencoba ulang satu kali.

**Chat itu sekarang menunggu dibalas agen.** Di Inbox, chatnya ditandai "AI menyerahkan chat ini ke Anda", dengan alasan di aktivitas lead. Bila dalam satu jam ada chat lain yang gagal, email ini tidak dikirim ulang, tetapi setiap chat tetap ditandai di Inbox.

<x-mail::button :url="$url">
Buka Inbox
</x-mail::button>
</x-mail::message>
