<x-filament-widgets::widget>
    <style>
        .jl-banner{position:relative;overflow:hidden;border-radius:.9rem;background:#e5e7eb}
        .jl-banner-track{display:flex;transition:transform .5s ease}
        .jl-banner-slide{flex:0 0 100%;min-width:0;display:block}
        .jl-banner-slide img{display:block;width:100%;aspect-ratio:3/1;object-fit:cover}
        .jl-banner-nav{position:absolute;top:50%;transform:translateY(-50%);width:2rem;height:2rem;border-radius:9999px;border:0;background:rgba(0,0,0,.45);color:#fff;font-size:1.1rem;line-height:1;cursor:pointer}
        .jl-banner-dots{position:absolute;left:0;right:0;bottom:.4rem;display:flex;justify-content:center;gap:.35rem}
        .jl-banner-dots button{width:.5rem;height:.5rem;padding:0;border:0;border-radius:9999px;background:rgba(255,255,255,.6);cursor:pointer}
        .jl-banner-dots button.on{background:#fff;box-shadow:0 0 0 1px rgba(0,0,0,.25)}
        @media (max-width:640px){.jl-banner-nav{display:none}}
    </style>
    <div class="jl-banner" role="region" aria-label="Informasi dan promo"
         x-data="{ i: 0, n: {{ $banners->count() }}, t: null,
                   go(k){ this.i = (k + this.n) % this.n },
                   play(){ this.stop(); if (this.n > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) this.t = setInterval(() => this.go(this.i + 1), 5000) },
                   stop(){ if (this.t) clearInterval(this.t); this.t = null },
                   x0: null }"
         x-init="play()" x-on:mouseenter="stop()" x-on:mouseleave="play()"
         x-on:touchstart.passive="x0 = $event.touches[0].clientX; stop()"
         x-on:touchend.passive="if (x0 !== null) { const d = $event.changedTouches[0].clientX - x0; if (Math.abs(d) > 40) go(i + (d < 0 ? 1 : -1)); x0 = null } play()">
        <div class="jl-banner-track" x-bind:style="'transform:translateX(-' + (i * 100) + '%)'">
            @foreach ($banners as $b)
                @if ($b->link_url)
                    <a class="jl-banner-slide" href="{{ $b->link_url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $b->title }}">
                        <img src="{{ $b->imageUrl() }}" alt="{{ $b->title }}" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                @else
                    <div class="jl-banner-slide">
                        <img src="{{ $b->imageUrl() }}" alt="{{ $b->title }}" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </div>
                @endif
            @endforeach
        </div>
        @if ($banners->count() > 1)
            <button type="button" class="jl-banner-nav" style="left:.5rem" aria-label="Banner sebelumnya" x-on:click="go(i - 1)">&#8249;</button>
            <button type="button" class="jl-banner-nav" style="right:.5rem" aria-label="Banner berikutnya" x-on:click="go(i + 1)">&#8250;</button>
            <div class="jl-banner-dots">
                @foreach ($banners as $b)
                    <button type="button" x-bind:class="{ on: i === {{ $loop->index }} }" aria-label="Banner {{ $loop->iteration }}" x-on:click="go({{ $loop->index }})"></button>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
