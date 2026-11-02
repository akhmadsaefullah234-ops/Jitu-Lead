@php($assurances = array_values(array_filter(array_map(fn ($a) => is_scalar($a) ? trim((string) $a) : '', (array) ($p['assurances'] ?? [])), 'strlen')))
<section class="{{ $cls }} closing"><div class="wrap cta">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    @if (filled($p['text'] ?? null))<p>{{ $p['text'] }}</p>@endif
    @if ($assurances)<ul class="assures">@foreach ($assurances as $a)<li>{{ $a }}</li>@endforeach</ul>@endif
    <div class="jbs center">@foreach (\App\LandingPages\Sections::buttonsOf('closing', $p) as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</div>
</div></section>
