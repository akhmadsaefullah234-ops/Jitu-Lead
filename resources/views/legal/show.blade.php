@extends('legal.layout')
@section('title', $doc['title'])
@section('description', $doc['title'].' JITU LEAD, CRM untuk agen dan agensi properti.')
@section('content')
    <h1>{{ $doc['title'] }}</h1>
    <p class="mute">Terakhir diperbarui {{ $doc['updated']->translatedFormat('j F Y') }}</p>
    @unless ($doc['reviewed'])
        <div class="draft" role="note"><b>Rancangan.</b> Teks ini belum ditinjau oleh ahli hukum dan dapat berubah sebelum layanan dirilis penuh.</div>
    @endunless
    @if ($doc['intro'])<p>{{ $doc['intro'] }}</p>@endif
    {!! $doc['html'] !!}
@endsection
