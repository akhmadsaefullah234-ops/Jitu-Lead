@php($unread = (bool) \App\Models\SupportThread::query()->where('user_id', auth()->id())->latest('id')->first()?->user_unread)
<button type="button" class="jl-menu-support" x-data
    x-on:click="$store.sidebar.close(); $nextTick(() => Livewire.dispatch('open-support'))">
    <x-filament::icon icon="heroicon-o-lifebuoy" class="h-6 w-6" />
    <span style="flex:1">Bantuan</span>
    @if ($unread)<span class="jl-tab-badge" style="position:static">!</span>@endif
</button>
