{{-- "Lihat tutorial" in a page header: the chapter about this page (App\Tutorials\Tutorials::forScopes). --}}
<a href="{{ \App\Filament\Pages\Tutorial::url($chapter) }}" class="jl-tutorial-link" title="Tutorial: {{ $chapter->title }}"
    style="display:inline-flex;align-items:center;gap:.3rem;font-size:.85rem;font-weight:600;color:var(--primary-700);white-space:nowrap">
    <x-filament::icon icon="heroicon-o-book-open" class="h-4 w-4" />
    Lihat tutorial
</a>
