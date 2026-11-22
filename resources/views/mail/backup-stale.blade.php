<x-mail::message>
# Tidak ada cadangan database yang valid

Sudah lebih dari **{{ $hours }} jam** tidak ada cadangan database JITU LEAD yang lolos pemeriksaan.

@if ($when)
Cadangan database valid terakhir: **{{ $when }}**.
@else
Belum pernah ada cadangan database yang valid.
@endif

@if ($latest)
Percobaan terakhir: {{ $latest->started_at->timezone(config('app.timezone'))->format('d M Y H:i') }}, {{ $latest->label() }}@if ($latest->message) ({{ $latest->message }})@endif.
@else
Belum ada percobaan backup sama sekali: pastikan scheduler berjalan.
@endif

<x-mail::button :url="$url">
Lihat riwayat backup
</x-mail::button>

Untuk mengulang: `php artisan backup:database`
</x-mail::message>
