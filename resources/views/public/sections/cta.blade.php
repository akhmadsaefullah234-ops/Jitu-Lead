@php($buttons = collect(\App\LandingPages\Sections::buttonsOf('cta', $p))->filter(fn ($b) => \App\LandingPages\Buttons::link($b, $btnCtx) !== null))
@if ($buttons->isNotEmpty())
    <section class="{{ $cls }}"><div class="wrap cta">
        @if (filled($p['heading'] ?? null))<h2 style="display:inline-block">{{ $p['heading'] }}</h2>@endif
        @if (filled($p['text'] ?? null))<p>{{ $p['text'] }}</p>@endif
        <div class="jbs center">@foreach ($buttons as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</div>
    </div></section>
@endif
