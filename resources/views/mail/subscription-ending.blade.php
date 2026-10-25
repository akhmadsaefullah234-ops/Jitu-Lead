@php($grace = $trial ? (int) config('plans.trial_grace_days') : $grace)
<x-mail::message>
# {{ $trial ? 'Masa percobaan' : 'Langganan' }} segera berakhir

Halo,

{{ $trial ? 'Masa percobaan' : 'Langganan' }} agensi **{{ $tenant->name }}** di JITU LEAD berakhir pada **{{ $ends->translatedFormat('j F Y') }}** ({{ $days }} hari lagi).

@if ($trial)
{{ $grace > 0 ? "Setelah itu ada masa tenggang $grace hari. Lewat dari itu" : 'Setelah itu' }} semua menu CRM dikunci sampai agensi memilih paket, dan AI serta follow-up otomatis berhenti. Lead dan chat yang masuk tetap tersimpan dan tampil lagi begitu langganan aktif.
@else
{{ $grace > 0 ? "Setelah itu ada masa tenggang $grace hari. Lewat dari itu" : 'Setelah itu' }} akun menjadi hanya-baca: data tetap aman dan bisa dilihat, tetapi tidak bisa ditambah atau diubah, dan AI serta follow-up otomatis berhenti.
@endif

<x-mail::button :url="$url">
Pilih paket
</x-mail::button>
</x-mail::message>
