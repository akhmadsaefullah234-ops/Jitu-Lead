@php($num = \App\LandingPages\PageData::digits($p['number'] ?? null) ?: $waNumber)
@php($link = $num ? \App\LandingPages\PageData::waLink($num, (string) ($p['message'] ?? 'Halo, saya tertarik dengan '.$page->title)) : null)
@if ($link)
    <section class="{{ $cls }}"><div class="wrap cta">
        @if (filled($p['heading'] ?? null))<h2 style="display:inline-block">{{ $p['heading'] }}</h2>@endif
        @if (filled($p['text'] ?? null))<p>{{ $p['text'] }}</p>@endif
        <a class="btn wa" href="{{ $link }}" rel="noopener">{{ $p['label'] ?? 'Chat via WhatsApp' }}</a>
    </div></section>
@endif
