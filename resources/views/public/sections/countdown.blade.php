@php($end = filled($p['ends_at'] ?? null) ? \Illuminate\Support\Carbon::parse($p['ends_at'], 'Asia/Jakarta') : null)
@if ($end)
    <section class="{{ $cls }}"><div class="wrap">
        @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
        @if ($end->isPast())
            <p>{{ $p['ended_text'] ?? 'Promo telah berakhir.' }}</p>
        @else
            <div class="count" data-count="{{ $end->toIso8601String() }}" data-ended="{{ $p['ended_text'] ?? 'Promo telah berakhir.' }}">
                <div><b data-u="d">-</b>hari</div><div><b data-u="h">-</b>jam</div><div><b data-u="m">-</b>menit</div><div><b data-u="s">-</b>detik</div>
            </div>
            <noscript><p>Berakhir {{ $end->translatedFormat('j F Y H:i') }} WIB</p></noscript>
        @endif
    </div></section>
@endif
