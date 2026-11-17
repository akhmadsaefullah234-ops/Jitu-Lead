<x-mail::message>
# Nilai AI turun

Halo,

Uji otomatis asisten AI agensi **{{ $tenant->name }}** turun dari **{{ $before }}%** ke **{{ $now }}%** skenario lulus. Biasanya ini karena pengetahuan AI baru saja diubah.

@if ($failed !== [])
Yang gagal:

@foreach ($failed as $line)
- {{ $line }}
@endforeach
@endif

Periksa hasilnya di Kesehatan AI, lalu perbaiki pengetahuan atau data di "Isi properti".

<x-mail::button :url="$url">
Buka Kesehatan AI
</x-mail::button>
</x-mail::message>
