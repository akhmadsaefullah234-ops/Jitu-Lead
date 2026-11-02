@php
    $items = array_values(array_filter((array) ($p['items'] ?? []), fn ($i) => is_array($i) && filled($i['title'] ?? null)));
    $layout = ($p['layout'] ?? 'list') === 'grid' ? 'grid' : 'list';
    $pos = in_array($p['icon_pos'] ?? '', ['left', 'top', 'right'], true) ? $p['icon_pos'] : ($layout === 'grid' ? 'top' : 'left');
    $cd = max(1, min(4, (int) ($p['cols_desktop'] ?? 2)));
    $cm = max(1, min(4, (int) ($p['cols_mobile'] ?? 1)));
    $size = max(16, min(96, (int) ($p['icon_size'] ?? 28)));
    $shape = in_array($p['icon_shape'] ?? 'circle', ['circle', 'square', 'none'], true) ? ($p['icon_shape'] ?? 'circle') : 'circle';
    $hex = fn ($v) => preg_match(\App\Models\LandingPage::COLOR_PATTERN, (string) $v) ? $v : null;
    $vars = '--icm: '.$cm.'; --icd: '.$cd.'; --ics: '.$size.'px;'.(($c = $hex($p['icon_color'] ?? null)) ? ' --icc: '.$c.';' : '').(($c = $hex($p['icon_bg'] ?? null)) ? ' --icb: '.$c.';' : '');
@endphp
@if ($items)
<section class="{{ $cls }}"><div class="wrap">
    @if (filled($p['heading'] ?? null))<h2>{{ $p['heading'] }}</h2>@endif
    <ul class="ic ic-{{ $layout }} ic-pos-{{ $pos }} ic-{{ $shape }} {{ ! empty($p['divider']) ? 'ic-div' : '' }}" style="{{ $vars }}">
        @foreach ($items as $it)
            @php($href = \App\LandingPages\Buttons::safeUrl($it['link'] ?? null))
            <li>@if ($href)<a href="{{ $href }}" rel="noopener" target="_blank" class="ic-a">@endif
                @include('public.partials.item-icon', ['it' => $it])
                <span class="ic-t"><b>{{ $it['title'] }}</b>@if (filled($it['text'] ?? null))<span>{{ $it['text'] }}</span>@endif</span>
            @if ($href)</a>@endif</li>
        @endforeach
    </ul>
</div></section>
@endif
