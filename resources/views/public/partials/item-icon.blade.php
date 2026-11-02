{{-- The icon of an item: from the set, an emoji, or an uploaded picture. Needs: $it, $tenantId. --}}
@php
    $type = $it['icon_type'] ?? 'icon';
    $iconImg = $type === 'image' ? \App\LandingPages\Embeds::image($it['icon_image'] ?? null, $tenantId) : null;
    $emoji = $type === 'emoji' ? mb_substr(trim((string) ($it['emoji'] ?? '')), 0, 8) : '';
@endphp
<span class="ic-i" aria-hidden="true">@if ($iconImg)<img class="ic-img" src="{{ $iconImg }}" alt="" loading="lazy" decoding="async">@elseif ($emoji !== ''){{ $emoji }}@else{!! \App\LandingPages\Icons::svg($it['icon'] ?? null) !!}@endif</span>
