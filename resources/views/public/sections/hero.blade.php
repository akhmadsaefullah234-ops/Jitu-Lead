@php
    $visual = in_array($p['visual'] ?? 'image', ['image', 'video', 'slider', 'product'], true) ? ($p['visual'] ?? 'image') : 'image';
    $img = $visual === 'image' ? \App\LandingPages\Embeds::image($p['image'] ?? null, $tenantId) : null;
    $yt = $visual === 'video' ? \App\LandingPages\Embeds::youtubeId($p['video_url'] ?? null) : null;
    $mp4 = $visual === 'video' && ! $yt ? (\App\LandingPages\Embeds::video($p['video_file'] ?? null, $tenantId) ?? \App\LandingPages\Embeds::videoUrl($p['video_url'] ?? null)) : null;
    $sl = $visual === 'slider' ? \App\LandingPages\Slider::view($p, $tenantId, 'slider_') : null;
    // "Gambar produk": the picture beside the text on a wide screen, under it on a phone, with its own alt text.
    $shot = $visual === 'product' ? \App\LandingPages\Embeds::image($p['image'] ?? null, $tenantId) : null;
    $shotSize = $shot ? \App\LandingPages\Embeds::imageSize($p['image'], $tenantId) : null;
    $media = $yt || $mp4 || ($sl && $sl['slides']) || $shot;
@endphp
<section class="hero{{ $media ? ' hero-m' : '' }}{{ $shot ? ' hero-p' : '' }}">
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
                @if ($shot)
                    <img class="hero-shot" src="{{ $shot }}" alt="{{ trim((string) ($p['image_alt'] ?? '')) ?: ($p['headline'] ?? $page->title) }}" fetchpriority="high" decoding="async" @if ($shotSize) width="{{ $shotSize[0] }}" height="{{ $shotSize[1] }}" @endif>
                @elseif ($yt)
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
