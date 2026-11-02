@php($sl = \App\LandingPages\Slider::view($p, $tenantId))
@if ($sl['slides'])
    <section class="{{ $cls }}"><div class="wrap">
        @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
        @include('public.partials.slider', ['sl' => $sl, 'eager' => $isFirst ?? false])
    </div></section>
@endif
