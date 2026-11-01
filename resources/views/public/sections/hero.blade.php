@php($img = \App\LandingPages\Embeds::image($p['image'] ?? null, $tenantId))
<section class="hero">
    @if ($img)<img class="bg" src="{{ $img }}" alt="" fetchpriority="high" decoding="async">@endif
    <div class="wrap">
        <h1>{{ $p['headline'] ?? $page->title }}</h1>
        @if (filled($p['subheadline'] ?? null))<p>{{ $p['subheadline'] }}</p>@endif
        <div class="jbs">@foreach (\App\LandingPages\Sections::buttonsOf('hero', $p) as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</div>
    </div>
</section>
