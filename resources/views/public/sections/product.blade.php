@php
    $imgs = \App\LandingPages\Embeds::images($p['images'] ?? [], $tenantId);
    $prices = \App\LandingPages\Sections::prices($p);
    $variants = [];
    foreach ((array) ($p['variants'] ?? []) as $v) {
        if (is_array($v) && filled($v['name'] ?? null)) {
            $variants[] = [
                'name' => (string) $v['name'],
                'swatch' => preg_match(\App\Models\LandingPage::COLOR_PATTERN, (string) ($v['swatch'] ?? '')) ? $v['swatch'] : null,
                'images' => \App\LandingPages\Embeds::images($v['images'] ?? [], $tenantId),
            ];
        }
    }
    $sizes = array_values(array_filter(array_map(fn ($s) => is_scalar($s) ? trim((string) $s) : '', (array) ($p['sizes'] ?? [])), 'strlen'));
    $points = array_values(array_filter(array_map(fn ($s) => is_array($s) ? trim((string) ($s['text'] ?? '')) : (is_scalar($s) ? trim((string) $s) : ''), (array) ($p['points'] ?? [])), 'strlen'));
    $stock = array_key_exists($p['stock'] ?? '', \App\LandingPages\Sections::STOCK) ? $p['stock'] : null;
    $slide = function (array $list, bool $eager) {
        $out = '';
        foreach ($list as $i => $src) {
            $out .= '<img src="'.e($src).'" alt="" decoding="async" '.($eager && $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"').'>';
        }
        return $out;
    };
@endphp
<section class="{{ $cls }} prd" data-product><div class="wrap prd-g">
    <div class="prd-media">
        @if ($imgs)<div class="sl" data-gal="all">{!! $slide($imgs, $isFirst ?? false) !!}</div>@endif
        @foreach ($variants as $i => $v)
            @if ($v['images'])<div class="sl" data-gal="{{ $i }}" @if ($imgs || $i > 0) hidden @endif>{!! $slide($v['images'], ($isFirst ?? false) && ! $imgs && $i === 0) !!}</div>@endif
        @endforeach
        @if (count($imgs) > 1)<p class="sl-hint">Geser untuk melihat foto lain ({{ count($imgs) }} foto)</p>@endif
    </div>
    <div class="prd-info">
        @if ($prices['off'] > 0)<span class="off">Hemat {{ $prices['off'] }}%</span>@endif
        @if ($isFirst ?? false)<h1 class="prd-name">{{ $p['name'] ?? $page->title }}</h1>@else<h2 class="prd-name">{{ $p['name'] ?? $page->title }}</h2>@endif
        @if ($prices['now'])
            <div class="prc">@if ($prices['was'])<s>{{ \App\LandingPages\Sections::rupiah($prices['was']) }}</s>@endif<strong>{{ \App\LandingPages\Sections::rupiah($prices['now']) }}</strong></div>
        @endif
        @if ($stock)<p class="stk stk-{{ $stock }}">{{ \App\LandingPages\Sections::STOCK[$stock] }}@if (filled($p['stock_note'] ?? null)) · {{ $p['stock_note'] }}@endif</p>@endif
        @if ($variants)
            <div class="opt"><p class="opt-l">{{ $p['variant_label'] ?? 'Warna' }}: <b data-vname></b></p>
                <div class="chips" role="group" aria-label="{{ $p['variant_label'] ?? 'Warna' }}">
                    @foreach ($variants as $i => $v)<button type="button" class="chip" data-v="{{ $i }}" data-pick="{{ $v['name'] }}" aria-pressed="false">@if ($v['swatch'])<i style="background: {{ $v['swatch'] }}"></i>@endif{{ $v['name'] }}</button>@endforeach
                </div>
            </div>
        @endif
        @if ($sizes)
            <div class="opt"><p class="opt-l">Ukuran: <b data-vname></b></p>
                <div class="chips" role="group" aria-label="Ukuran">@foreach ($sizes as $s)<button type="button" class="chip" data-pick="{{ $s }}" aria-pressed="false">{{ $s }}</button>@endforeach</div>
            </div>
        @endif
        @if ($points)<ul class="pts">@foreach ($points as $pt)<li>{{ $pt }}</li>@endforeach</ul>@endif
        <div class="jbs full">@foreach (\App\LandingPages\Sections::buttonsOf('product', $p) as $b)@include('public.partials.button', ['b' => $b, 'ctx' => $btnCtx, 'tracking' => $tracking])@endforeach</div>
    </div>
</div></section>
