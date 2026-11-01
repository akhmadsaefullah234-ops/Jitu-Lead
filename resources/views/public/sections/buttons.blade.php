@php($align = in_array($p['align'] ?? 'center', ['center', 'left', 'full'], true) ? ($p['align'] ?? 'center') : 'center')
<section class="{{ $cls }}"><div class="wrap {{ $align === 'center' ? 'cta' : '' }}">
    @if (filled($p['heading'] ?? null))<h2 @if ($align === 'center') style="display:inline-block" @endif>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['text'] ?? null))<p>{{ $p['text'] }}</p>@endif
    <div class="jbs {{ $align === 'center' ? 'center' : ($align === 'full' ? 'full' : '') }}">@foreach (\App\LandingPages\Sections::buttonsOf('buttons', $p) as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</div>
</div></section>
