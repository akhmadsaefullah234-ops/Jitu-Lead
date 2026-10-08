<x-filament-panels::page>
    <style>
        .jl-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .jl-chip { border: 1px solid var(--gray-300); background: white; border-radius: 9999px; padding: .3rem .8rem; font-size: .8125rem; font-weight: 500; }
        .jl-chip[aria-pressed="true"] { background: var(--primary-600); border-color: var(--primary-600); color: white; }
        .jl-input { border: 1px solid var(--gray-300); background: white; border-radius: .5rem; padding: .4rem .7rem; font-size: .875rem; min-width: 0; }
        .jl-board { display: flex; gap: .75rem; overflow-x: auto; padding-bottom: .75rem; align-items: flex-start; }
        .jl-col { flex: 0 0 17rem; background: var(--gray-100); border-radius: .75rem; display: flex; flex-direction: column; max-height: calc(100vh - 16rem); min-height: 12rem; }
        .jl-col.is-closed { flex-basis: 14rem; }
        .jl-col.is-over { outline: 2px dashed var(--primary-500); outline-offset: -2px; }
        .jl-colhead { padding: .75rem .75rem .5rem; }
        .jl-colhead .t { display: flex; justify-content: space-between; font-weight: 600; font-size: .875rem; }
        .jl-colhead .t span { color: var(--gray-500); font-weight: 500; }
        .jl-colhead .a { font-size: .75rem; color: var(--gray-500); }
        .jl-cards { overflow-y: auto; padding: .25rem .5rem .75rem; display: flex; flex-direction: column; gap: .5rem; }
        .jl-card { display: flex; flex-direction: column; gap: .4rem; background: white; border: 1px solid var(--gray-200); border-radius: .625rem; padding: .6rem .7rem; cursor: grab; }
        .jl-card:hover { border-color: var(--gray-400); }
        .jl-card.is-late { border-left: 3px solid var(--danger-600); }
        .jl-card .nm { font-weight: 600; font-size: .875rem; color: var(--gray-950); }
        .jl-card .sub { font-size: .75rem; color: var(--gray-500); }
        .jl-card .row { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; font-size: .75rem; }
        .jl-card .next { display: flex; gap: .4rem; font-size: .75rem; border-top: 1px solid var(--gray-200); padding-top: .4rem; }
        .jl-card .next .d { margin-left: auto; white-space: nowrap; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .jl-card .next .d.late { color: var(--danger-600); font-weight: 600; }
        .jl-card .acts { display: flex; justify-content: space-between; align-items: center; }
        .jl-link { font-size: .75rem; font-weight: 600; color: var(--primary-600); }
        .jl-board { scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; }
        .jl-col { scroll-snap-align: start; }
        @media (max-width: 640px) {
            .jl-col, .jl-col.is-closed { flex-basis: 84vw; max-height: none; }
            .jl-bar .jl-input[type="search"] { flex: 1 1 100% !important; max-width: none !important; }
            .jl-card { cursor: default; }
        }
        .jl-empty { font-size: .75rem; color: var(--gray-500); padding: .5rem .25rem; }
        .dark .jl-chip, .dark .jl-input { background: var(--gray-900); border-color: var(--gray-700); color: var(--gray-100); }
        .dark .jl-chip[aria-pressed="true"] { background: white; color: var(--gray-950); border-color: white; }
        .dark .jl-col { background: color-mix(in oklab, var(--gray-800) 60%, transparent); }
        .dark .jl-card { background: var(--gray-900); border-color: var(--gray-700); }
        .dark .jl-card .nm { color: white; }
        .dark .jl-card .next { border-color: var(--gray-700); }
        .dark .jl-card .next .d.late { color: var(--danger-400); }
        .dark .jl-link { color: var(--primary-400); }
    </style>

    <div class="jl-bar">
        <input type="search" class="jl-input" style="flex: 1 1 14rem; max-width: 20rem" placeholder="Cari nama, nomor, email" aria-label="Cari lead" wire:model.live.debounce.400ms="search">
        @foreach ($filters as $key => $label)
            <button type="button" class="jl-chip" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
        @endforeach
        @if ($agents->isNotEmpty())
            <select class="jl-input" aria-label="Filter agen" wire:model.live="agent">
                <option value="">Semua agen</option>
                @foreach ($agents as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    <div class="jl-board" x-data="{ dragging: null }">
        @foreach ($columns as $column)
            @php($stage = $column['stage'])
            <section
                wire:key="stage-{{ $stage->id }}"
                class="jl-col {{ $stage->isOpen() ? '' : 'is-closed' }}"
                aria-label="{{ $stage->name }}"
                x-data="{ over: false }"
                :class="{ 'is-over': over }"
                x-on:dragover.prevent="over = true"
                x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = false"
                x-on:drop.prevent="over = false; if (dragging) $wire.moveLead(dragging, {{ $stage->id }})"
            >
                <div class="jl-colhead">
                    <div class="t">{{ $stage->name }}<span>{{ $column['count'] }}</span></div>
                    <div class="a">{{ $stage->default_action ?? 'Wajib pilih alasan' }}</div>
                </div>
                <div class="jl-cards">
                    @forelse ($column['leads'] as $lead)
                        @php($late = $stage->isOpen() && $lead->isOverdue())
                        <article
                            wire:key="lead-{{ $lead->id }}"
                            class="jl-card {{ $late ? 'is-late' : '' }}"
                            draggable="true"
                            x-on:dragstart="dragging = {{ $lead->id }}"
                            x-on:dragend="dragging = null"
                        >
                            <div>
                                <div class="nm">{{ $lead->name }}</div>
                                <div class="sub">{{ $lead->property?->name ?? $lead->location ?? 'Properti belum dipilih' }}</div>
                            </div>
                            <div class="row">
                                <x-filament::badge :color="$lead->interest->getColor()" size="sm">{{ $lead->interest->getLabel() }}</x-filament::badge>
                                @if ($lead->deal_value)
                                    <strong>Rp {{ number_format($lead->deal_value, 0, ',', '.') }}</strong>
                                @elseif ($lead->budget_max)
                                    <span>s.d. Rp {{ number_format($lead->budget_max, 0, ',', '.') }}</span>
                                @endif
                                @if ($lead->owner)
                                    <span class="sub">· {{ $lead->owner->name }}</span>
                                @endif
                            </div>
                            @if ($lead->next_action)
                                <div class="next">
                                    <span>{{ $lead->next_action }}</span>
                                    <span class="d {{ $late ? 'late' : '' }}">
                                        {{ $late ? 'Terlambat '.$lead->next_action_due_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) : $lead->next_action_due_at?->translatedFormat('D, j M H:i') }}
                                    </span>
                                </div>
                            @endif
                            <div class="acts">
                                <span style="display:flex;gap:.75rem">
                                    <a class="jl-link" href="{{ $editUrl($lead) }}" wire:navigate>Buka</a>
                                    @if ($lead->phone)
                                        <a class="jl-link" href="{{ \App\Filament\Pages\Inbox::getUrl(['lead' => $lead->id]) }}" wire:navigate>Chat</a>
                                    @endif
                                </span>
                                <button type="button" class="jl-link" wire:click="mountAction('move', { lead: {{ $lead->id }} })">Pindah tahap</button>
                            </div>
                        </article>
                    @empty
                        <div class="jl-empty">Tidak ada lead</div>
                    @endforelse
                    @if ($column['count'] > $column['leads']->count())
                        <div class="jl-empty">{{ $column['count'] - $column['leads']->count() }} lead lain, cari atau filter untuk menemukannya.</div>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
