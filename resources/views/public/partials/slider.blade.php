{{-- A carousel (App\LandingPages\Slider::view). Needs: $sl, $btnCtx, $tracking, $eager. --}}
@if ($sl['slides'])
    @php($count = count($sl['slides']))
    <div class="sld {{ $sl['fade'] ? 'fade' : '' }}" data-slider @if ($sl['autoplay'] && $count > 1) data-auto="{{ $sl['interval'] }}" @endif @if ($sl['loop']) data-loop @endif
         style="--pvm: {{ $sl['per'][0] }}; --pvt: {{ $sl['per'][1] }}; --pvd: {{ $sl['per'][2] }}; --sg: {{ $sl['gap'] }}px; --sr: {{ $sl['radius'] }}px; --sf: {{ $sl['fit'] }}; @if ($sl['ratio']) --sar: {{ $sl['ratio'] }}; @endif"
         role="region" aria-roledescription="carousel" aria-label="Slider gambar">
        <div class="sl-track" tabindex="0">
            @foreach ($sl['slides'] as $i => $s)
                <figure class="slide {{ $i === 0 ? 'on' : '' }}" aria-roledescription="slide" aria-label="{{ $i + 1 }} dari {{ $count }}">
                    @if ($s['link'])<a href="{{ $s['link'] }}" rel="noopener" target="_blank">@endif
                    <img class="sl-img" src="{{ $s['src'] }}" alt="{{ $s['alt'] }}" decoding="async" @if ($i === 0 && ($eager ?? false)) fetchpriority="high" @else loading="lazy" @endif>
                    @if ($s['link'])</a>@endif
                    @if ($s['title'] !== '' || $s['caption'] !== '' || $s['button'])
                        <figcaption>
                            @if ($s['title'] !== '')<strong>{{ $s['title'] }}</strong>@endif
                            @if ($s['caption'] !== '')<span>{{ $s['caption'] }}</span>@endif
                            @if ($s['button'])<div class="jbs">@include('public.partials.button', ['b' => $s['button'], 'ctx' => $btnCtx, 'tracking' => $tracking])</div>@endif
                        </figcaption>
                    @endif
                </figure>
            @endforeach
        </div>
        @if ($count > 1 && $sl['arrows'])
            <button type="button" class="sl-arrow prev" data-prev aria-label="Slide sebelumnya">&#8249;</button>
            <button type="button" class="sl-arrow next" data-next aria-label="Slide berikutnya">&#8250;</button>
        @endif
        @if ($count > 1 && $sl['dots'])
            <div class="sl-dots" data-dots>@foreach ($sl['slides'] as $i => $s)<button type="button" aria-label="Ke slide {{ $i + 1 }}" @if ($i === 0) aria-current="true" @endif></button>@endforeach</div>
        @endif
    </div>
@endif
