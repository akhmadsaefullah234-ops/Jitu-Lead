@php($images = \App\LandingPages\Embeds::images($p['images'] ?? [], $tenantId))
@if ($images)
    <section class="{{ $cls }}"><div class="wrap">
        @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
        <div class="gal">@foreach ($images as $src)<img src="{{ $src }}" alt="" loading="lazy" decoding="async">@endforeach</div>
    </div></section>
@endif
