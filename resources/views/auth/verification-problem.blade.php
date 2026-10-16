@extends('legal.layout')
@section('title', 'Verifikasi email')
@section('description', 'Tautan verifikasi email.')
@section('content')
    <style>
        .vp { max-width: 34rem; margin: 2.5rem auto; }
        .vp .btns { display: flex; flex-wrap: wrap; gap: .6rem; margin: 1.4rem 0; }
        .vp button, .vp a.btn { appearance: none; border: 0; cursor: pointer; font: inherit; font-weight: 600; text-decoration: none; background: var(--red); color: #fff; padding: .7rem 1.2rem; border-radius: .6rem; display: inline-block; }
        .vp a.btn.ghost, .vp button.ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
    </style>
    <div class="vp">
        @if ($kind === 'other')
            <h1>Tautan ini untuk akun lain</h1>
            <p>Tautan verifikasi ini dibuat untuk akun <b>{{ $ownerEmail }}</b>, sedangkan Anda sedang masuk sebagai <b>{{ $current?->email }}</b>.</p>
            <p>Keluar lalu masuk sebagai akun tersebut untuk menyelesaikan verifikasi. Setelah masuk, verifikasi dilanjutkan otomatis.</p>
            <div class="btns">
                <form method="post" action="{{ route('verification.switch') }}">
                    @csrf
                    <input type="hidden" name="url" value="{{ $verifyUrl }}">
                    <button type="submit">Keluar dan masuk sebagai akun itu</button>
                </form>
                <a class="btn ghost" href="{{ url('/app') }}">Kembali ke akun saya</a>
            </div>
        @elseif ($kind === 'expired')
            <h1>Tautan verifikasi tidak berlaku</h1>
            <p>Tautan ini sudah kedaluwarsa atau tidak cocok lagi dengan email akun Anda. Kirim ulang email verifikasi untuk mendapat tautan baru.</p>
            <div class="btns">
                @if ($current)
                    <form method="post" action="{{ route('verification.resend') }}">
                        @csrf
                        <button type="submit">Kirim ulang email verifikasi</button>
                    </form>
                    <a class="btn ghost" href="{{ url('/app') }}">Kembali ke aplikasi</a>
                @else
                    <a class="btn" href="{{ $loginUrl }}">Masuk</a>
                @endif
            </div>
        @elseif ($kind === 'sent')
            <h1>Email verifikasi dikirim</h1>
            <p>Kami mengirim tautan baru ke <b>{{ $current->email }}</b>. Buka email itu dan klik tautannya. Tautan berlaku {{ config('auth.verification.expire', 60) }} menit. Cek juga folder spam.</p>
            <div class="btns"><a class="btn ghost" href="{{ url('/app') }}">Kembali ke aplikasi</a></div>
        @else
            <h1>Email sudah terverifikasi</h1>
            <p>Email <b>{{ $current->email }}</b> sudah terverifikasi. Tidak ada yang perlu dilakukan lagi.</p>
            <div class="btns"><a class="btn" href="{{ url('/app') }}">Buka aplikasi</a></div>
        @endif
    </div>
@endsection
