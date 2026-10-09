@php($img = \App\LandingPages\Embeds::image($p['image'] ?? null, $tenantId))
<section class="hero">
    @if ($img)<img class="bg" src="{{ $img }}" alt="" fetchpriority="high" decoding="async">@endif
    <div class="wrap">
        <h1>{{ $p['headline'] ?? $page->title }}</h1>
        @if (filled($p['subheadline'] ?? null))<p>{{ $p['subheadline'] }}</p>@endif
        <a class="btn" href="#daftar">{{ $p['cta_label'] ?? 'Daftar sekarang' }}</a>
    </div>
</section>
