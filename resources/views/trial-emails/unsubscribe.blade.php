@extends('legal.layout')

@section('title', 'Berhenti menerima email')
@section('description', 'Berhenti menerima email masa percobaan JITU LEAD')

@section('content')
    <h1>Berhenti menerima email</h1>
    @if ($done)
        <p>Anda tidak akan lagi menerima email tips dan pengingat masa percobaan JITU LEAD. Email penting tentang akun, seperti verifikasi dan atur ulang kata sandi, tetap dikirim.</p>
    @else
        <p>Klik tombol di bawah untuk berhenti menerima email tips dan pengingat masa percobaan JITU LEAD.</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit" style="background:var(--red);color:#fff;border:0;border-radius:.6rem;padding:.7rem 1.2rem;font-weight:700;font-size:1rem;cursor:pointer">Berhenti menerima email ini</button>
        </form>
    @endif
    <p class="mute">Ingin tahu data apa yang kami simpan? Baca <a href="{{ url('/kebijakan-privasi') }}">Kebijakan Privasi</a>.</p>
@endsection
