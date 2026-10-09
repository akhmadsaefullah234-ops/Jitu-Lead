<section class="{{ $cls }}" id="daftar"><div class="wrap">
    <div class="formbox">
        <h2>{{ $p['heading'] ?? $settings['title'] }}</h2>
        @if (filled($p['intro'] ?? $settings['intro']))<p class="intro">{{ $p['intro'] ?? $settings['intro'] }}</p>@endif
        @include('public.partials.lead-form', ['formId' => 'lead-form', 'pageId' => $page->getKey(), 'settings' => array_replace($settings, array_filter(['button' => $p['button'] ?? null])), 'endpoint' => $endpoint, 'tracking' => $tracking])
    </div>
</div></section>
