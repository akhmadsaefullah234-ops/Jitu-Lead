@php
    use App\Filament\Pages\{Dashboard, Inbox, Pipeline};
    use App\Filament\Resources\Leads\LeadResource;

    $tabs = collect([
        ['Dasbor', 'heroicon-o-home', 'heroicon-s-home', Dashboard::getUrl(), Dashboard::class],
        ['Lead', 'heroicon-o-user-group', 'heroicon-s-user-group', LeadResource::getUrl('index'), LeadResource::class],
        ['Inbox', 'heroicon-o-chat-bubble-left-right', 'heroicon-s-chat-bubble-left-right', Inbox::getUrl(), Inbox::class],
        ['Pipeline', 'heroicon-o-view-columns', 'heroicon-s-view-columns', Pipeline::getUrl(), Pipeline::class],
    ])->filter(fn ($t) => $t[4]::canAccess());

    $here = rtrim(url()->current(), '/');
    $onLeadList = $here === rtrim(LeadResource::getUrl('index'), '/');
    $badge = Inbox::canAccess() ? Inbox::getNavigationBadge() : null;
@endphp
<nav class="jl-tabbar" aria-label="Menu utama">
    @foreach ($tabs as [$label, $icon, $iconOn, $url, $class])
        @php($active = $class === Dashboard::class ? $here === rtrim($url, '/') : str_starts_with($here, rtrim($url, '/')))
        <a href="{{ $url }}" wire:navigate class="jl-tab {{ $active ? 'is-active' : '' }}" @if ($active) aria-current="page" @endif>
            <span class="jl-tab-icon">
                <x-filament::icon :icon="$active ? $iconOn : $icon" class="h-6 w-6" />
                @if ($class === Inbox::class && $badge)<span class="jl-tab-badge">{{ $badge }}</span>@endif
            </span>
            <span>{{ $label }}</span>
        </a>
    @endforeach
    <button type="button" class="jl-tab" x-data x-on:click="$store.sidebar.open()" aria-label="Buka semua menu">
        <span class="jl-tab-icon"><x-filament::icon icon="heroicon-o-bars-3" class="h-6 w-6" /></span>
        <span>Menu</span>
    </button>
</nav>
@if ($onLeadList && LeadResource::canCreate())
    <a href="{{ LeadResource::getUrl('create') }}" wire:navigate class="jl-fab" aria-label="Tambah lead">
        <x-filament::icon icon="heroicon-m-plus" class="h-7 w-7" />
    </a>
@endif
