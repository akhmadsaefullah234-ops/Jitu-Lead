<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <div class="prose">{!! \App\LandingPages\RichText::clean($p['html'] ?? '') !!}</div>
</div></section>
