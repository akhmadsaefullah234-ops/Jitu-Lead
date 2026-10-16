@extends('legal.layout')
@section('title', 'Kontak')
@section('description', 'Kontak dan alamat usaha JITU LEAD.')
@section('content')
    <h1>Kontak</h1>
    <p class="mute">Hubungi kami untuk pertanyaan, bantuan, atau pengajuan pengembalian dana.</p>
    <dl class="contact">
        <div><dt>Nama usaha</dt><dd>{{ $company }}</dd></div>
        @if ($contact['address'])<div><dt>Alamat usaha</dt><dd><address style="font-style:normal">{{ $contact['address'] }}</address></dd></div>@endif
        @if ($contact['email'])<div><dt>Email</dt><dd><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></dd></div>@endif
        @if ($contact['phone'])
            <div><dt>Telepon / WhatsApp</dt><dd>
                @if ($contact['phone_digits'])<a href="tel:+{{ $contact['phone_digits'] }}">{{ $contact['phone'] }}</a> · <a href="https://wa.me/{{ $contact['phone_digits'] }}" target="_blank" rel="noopener">Chat WhatsApp</a>@else{{ $contact['phone'] }}@endif
            </dd></div>
        @endif
        @if ($contact['hours'])<div><dt>Jam layanan</dt><dd>{{ $contact['hours'] }}</dd></div>@endif
    </dl>
@endsection
