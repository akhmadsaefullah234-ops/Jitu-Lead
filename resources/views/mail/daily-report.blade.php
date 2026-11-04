<x-mail::message>
{!! $body !!}

<x-mail::button :url="$url">
Buka Dasbor
</x-mail::button>

<small>Laporan ini dikirim lewat email karena WhatsApp tidak bisa dipakai saat itu. Atur di menu Pengaturan > Laporan harian.</small>
</x-mail::message>
