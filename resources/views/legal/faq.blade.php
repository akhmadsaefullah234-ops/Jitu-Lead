@extends('legal.layout')
@section('title', 'Tanya Jawab')
@section('description', 'Pertanyaan yang sering diajukan tentang JITU LEAD, CRM untuk agen dan agensi properti.')
@section('content')
    <h1>Pertanyaan yang sering diajukan</h1>
    <p class="mute">Belum ada jawabannya di sini? Hubungi kami lewat halaman <a href="{{ url('/kontak') }}">Kontak</a>.</p>
    <div class="faq">
        @foreach ($faq as $item)
            <details><summary>{{ $item['q'] }}</summary><p>{{ $item['a'] }}</p></details>
        @endforeach
    </div>
@endsection
