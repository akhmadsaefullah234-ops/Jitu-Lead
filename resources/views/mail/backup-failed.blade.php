<x-mail::message>
# Backup harian GAGAL

Backup harian JITU LEAD pada **{{ $when }}** gagal.

> {{ $errorMessage }}

**Langkah:**

{{ $steps }}

<x-mail::button :url="$url">
Lihat riwayat backup
</x-mail::button>

Untuk mengulang: `php artisan backup:run`
</x-mail::message>
