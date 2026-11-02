@php
    $visual = in_array($p['visual'] ?? 'image', ['image', 'video', 'slider'], true) ? ($p['visual'] ?? 'image') : 'image';
    $img = $visual === 'image' ? \App\LandingPages\Embeds::image($p['image'] ?? null, $tenantId) : null;
    $yt = $visual === 'video' ? \App\LandingPages\Embeds::youtubeId($p['video_url'] ?? null) : null;
    $mp4 = $visual === 'video' && ! $yt ? (\App\LandingPages\Embeds::video($p['video_file'] ?? null, $tenantId) ?? \App\LandingPages\Embeds::videoUrl($p['video_url'] ?? null)) : null;
    $sl = $visual === 'slider' ? \App\LandingPages\Slider::view($p, $tenantId, 'slider_') : null;
    $media = $yt || $mp4 || ($sl && $sl['slides']);
@endphp
<section class="hero{{ $media ? ' hero-m' : '' }}">
    @if ($img)<img class="bg" src="{{ $img }}" alt="" fetchpriority="high" decoding="async">@endif
    <div class="wrap{{ $media ? ' mx' : '' }}">
        <div class="mx-t">
            <h1>{{ $p['headline'] ?? $page->title }}</h1>
            @if (filled($p['subheadline'] ?? null))<p>{{ $p['subheadline'] }}</p>@endif
            <div class="jbs">@foreach (\App\LandingPages\Sections::buttonsOf('hero', $p) as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</div>
            @if (filled($p['assurance'] ?? null))<p class="assure">{{ $p['assurance'] }}</p>@endif
        </div>
        @if ($media)
            <div class="mx-m">
                @if ($yt)
                    <div class="video"><a href="https://www.youtube.com/watch?v={{ $yt }}" target="_blank" rel="noopener nofollow" data-yt="{{ $yt }}" aria-label="Putar video"><img src="https://i.ytimg.com/vi/{{ $yt }}/hqdefault.jpg" alt="" decoding="async"><span class="play" aria-hidden="true">&#9654;</span></a></div>
                @elseif ($mp4)
                    <video class="hero-vid" src="{{ $mp4 }}" controls playsinline preload="metadata"></video>
                @else
                    @include('public.partials.slider', ['sl' => $sl, 'eager' => $isFirst ?? false])
                @endif
            </div>
        @endif
    </div>
</section>
