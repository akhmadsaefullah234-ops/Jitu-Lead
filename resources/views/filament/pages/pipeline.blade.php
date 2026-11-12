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
        .jl-colhead .v { font-size: .75rem; font-weight: 600; color: var(--success-700); font-variant-numeric: tabular-nums; }
        .jl-period { margin: .75rem 0 1rem; }
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
        /* Phones: one stage per screen. The board swipes sideways and snaps to a stage; the stage chips above it
           show where you are and jump to a stage when tapped. Agent, period and product sit behind "Filter". */
        .jl-tabs, .jl-filter-btn, .jl-filters .jl-quick { display: none; }
        .jl-quick { display: contents; }
        @media (max-width: 640px) {
            .jl-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .75rem; }
            .jl-tabs .jl-chip { display: inline-flex; gap: .35rem; align-items: center; min-height: 2.75rem; }
            .jl-tabs .jl-chip span { font-size: .75rem; opacity: .75; }
            .jl-board { position: relative; overflow-x: auto; scroll-snap-type: x mandatory; overscroll-behavior-x: contain;
                margin-inline: -.75rem; padding: 0 .75rem .75rem; scroll-padding-inline: .75rem; scrollbar-width: none; }
            .jl-board::-webkit-scrollbar { display: none; }
            .jl-col, .jl-col.is-closed { flex: 0 0 100%; max-height: none; scroll-snap-align: start; scroll-snap-stop: always; }
            .jl-filter-btn { display: inline-flex; gap: .35rem; align-items: center; min-height: 2.75rem; }
            .jl-filter-btn b { min-width: 1.15rem; height: 1.15rem; border-radius: 9999px; background: var(--primary-600); color: white; font-size: .7rem; line-height: 1.15rem; text-align: center; }
            .jl-filters:not(.is-open) { display: none; }
            /* The quick filters live in the Filter panel too: one row (search + Filter) above the board */
            .jl-bar { flex-wrap: nowrap; }
            .jl-bar .jl-input[type="search"] { flex: 1 1 auto !important; min-width: 0; min-height: 2.75rem; }
            .jl-bar .jl-quick { display: none; }
            .jl-filters .jl-quick { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .75rem; }
            /* Stage chips: one row that scrolls; the chip of the stage on screen is kept in sight */
            .jl-tabs { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; margin-inline: -.75rem; padding-inline: .75rem; scroll-padding-inline: .75rem; }
            .jl-tabs::-webkit-scrollbar { display: none; }
            .jl-tabs .jl-chip { flex: none; }
            .jl-filters { margin-top: .5rem; padding: .75rem; border: 1px solid var(--gray-200); border-radius: .75rem; background: white; }
            .jl-filters .jl-period { margin: .75rem 0 0; }
            .jl-filters select.jl-input { width: 100%; min-height: 2.75rem; }
            .jl-bar .jl-chip { min-height: 2.75rem; }
            .jl-card .acts .jl-link { display: inline-flex; align-items: center; min-height: 2.75rem; }
            .jl-card .acts > span { gap: 1.25rem !important; }
            .jl-period { margin: .75rem 0; }
        }
        .dark .jl-filters { background: var(--gray-900); border-color: var(--gray-700); }
        .jl-tag { display: inline-block; border: 1px solid var(--gray-200); border-radius: .375rem; padding: 0 .35rem; font-size: .6875rem; color: var(--gray-700); font-variant-numeric: tabular-nums; }
        .dark .jl-tag { border-color: var(--gray-700); color: var(--gray-300); }
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

    @php($activeFilters = ($filter !== array_key_first($filters) ? 1 : 0) + ($agent ? 1 : 0) + ($this->period() ? 1 : 0) + (filled($this->produk) ? 1 : 0))
    <div x-data="{ open: false }">
    <div class="jl-bar">
        <input type="search" class="jl-input" style="flex: 1 1 14rem; max-width: 20rem" placeholder="Cari nama, nomor, email" aria-label="Cari lead" wire:model.live.debounce.400ms="search">
        <button type="button" class="jl-chip jl-filter-btn" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="jl-filters">
            <x-filament::icon icon="heroicon-m-funnel" class="h-4 w-4" /> Filter @if ($activeFilters)<b>{{ $activeFilters }}</b>@endif
        </button>
        <span class="jl-quick" role="group" aria-label="Saring cepat">
            @foreach ($filters as $key => $label)
                <button type="button" class="jl-chip" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </span>
    </div>

    <div class="jl-filters" id="jl-filters" x-bind:class="{ 'is-open': open }">
        <div class="jl-quick" role="group" aria-label="Saring cepat">
            @foreach ($filters as $key => $label)
                <button type="button" class="jl-chip" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
        @if ($agents->isNotEmpty())
            <select class="jl-input" aria-label="Filter agen" wire:model.live="agent">
                <option value="">Semua agen</option>
                @foreach ($agents as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <div class="jl-period">{{ $this->periodForm }}</div>
    </div>
    </div>

    {{-- stage: the stage on screen (phones). go() scrolls the board to a stage; sync() follows a swipe. --}}
    <div x-data="{
        stage: @js(collect($columns)->first()['key'] ?? null),
        dragging: null,
        keys: @js(collect($columns)->pluck('key')->values()),
        go(key) {
            this.stage = key;
            const board = this.$refs.board, col = board.children[this.keys.indexOf(key)];
            if (col && board.scrollWidth > board.clientWidth) board.scrollTo({ left: col.offsetLeft - parseFloat(getComputedStyle(board).paddingLeft), behavior: 'smooth' });
        },
        sync() {
            const board = this.$refs.board;
            if (board.scrollWidth <= board.clientWidth) return;
            const pad = parseFloat(getComputedStyle(board).paddingLeft);
            let best = 0;
            [...board.children].forEach((col, i) => { if (Math.abs(col.offsetLeft - pad - board.scrollLeft) < Math.abs(board.children[best].offsetLeft - pad - board.scrollLeft)) best = i; });
            this.stage = this.keys[best] ?? this.stage;
        },
        init() {
            this.$watch('stage', (key) => this.$nextTick(() => this.$refs.tabs?.children[this.keys.indexOf(key)]?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' })));
        },
    }">
    <div class="jl-tabs" role="tablist" aria-label="Tahap" x-ref="tabs">
        @foreach ($columns as $column)
            <button type="button" class="jl-chip" role="tab" wire:key="tab-{{ $column['key'] }}"
                x-bind:aria-pressed="stage === @js($column['key']) ? 'true' : 'false'" x-bind:aria-selected="stage === @js($column['key'])"
                x-on:click="go(@js($column['key']))">{{ $column['name'] }} <span>{{ $column['count'] }}</span></button>
        @endforeach
    </div>
    <div class="jl-board" x-ref="board" x-on:scroll.debounce.80ms="sync()">
        @foreach ($columns as $column)
            @php($stage = $column['stage'])
            <section
                wire:key="stage-{{ $column['key'] }}"
                class="jl-col {{ $stage->isOpen() ? '' : 'is-closed' }}"
                aria-label="{{ $column['name'] }}"
                x-data="{ over: false }"
                :class="{ 'is-over': over, 'is-current': stage === @js($column['key']) }"
                x-on:dragover.prevent="over = true"
                x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = false"
                x-on:drop.prevent="over = false; if (dragging) $wire.moveLead(dragging, {{ $stage->id }}, @js($column['part']))"
            >
                <div class="jl-colhead">
                    <div class="t">{{ $column['name'] }}<span>{{ $column['count'] }}</span></div>
                    @if ($column['value'] > 0)
                        <div class="v">Rp {{ number_format($column['value'], 0, ',', '.') }}</div>
                    @endif
                    <div class="a">{{ $column['hint'] }}</div>
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
                                <div class="sub">{{ $lead->property?->name ?? $lead->location ?? \App\Support\Industry::t('item_missing') }}</div>
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
                            @if ($pack = $packing[$lead->id] ?? null)
                                <div class="row jl-ship">
                                    @if ($pack->phase === \App\Shipping\Shipments\PackingState::NEEDS_RESI)
                                        <x-filament::badge :color="$pack->held ? 'danger' : 'warning'" size="sm">Perlu resi</x-filament::badge>
                                        @if ($pack->reason)<span class="sub">{{ $pack->reason }}</span>@endif
                                    @elseif ($pack->phase === \App\Shipping\Shipments\PackingState::READY)
                                        <x-filament::badge color="info" size="sm">Siap cetak</x-filament::badge>
                                    @else
                                        <x-filament::badge :color="in_array($pack->phase, ['delivery_failed', 'pickup_failed'], true) ? 'danger' : 'gray'" size="sm">{{ $pack->courierStatus() }}</x-filament::badge>
                                        @if (($days = $pack->daysSincePrinted()) !== null)<span class="sub">{{ $days === 0 ? 'dicetak hari ini' : $days.' hari sejak dicetak' }}</span>@endif
                                    @endif
                                </div>
                                @if ($pack->shipment?->awb || $lead->tracking_number || $lead->courier || $lead->payment_type)
                                    <div class="row">
                                        @if ($awb = $pack->shipment?->awb ?? $lead->tracking_number)<span class="jl-tag" title="Nomor resi">{{ $awb }}</span>@endif
                                        @if ($courier = $pack->shipment?->courier ?? $lead->courier)<span class="jl-tag">{{ $courier }}</span>@endif
                                        @php($cod = $pack->shipment ? $pack->shipment->is_cod : ($lead->payment_type === 'cod' ? true : ($lead->payment_type ? false : null)))
                                        @if ($cod !== null)<span class="jl-tag">{{ $cod ? 'COD' : 'Transfer' }}</span>@endif
                                    </div>
                                @endif
                            @endif
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
    </div>
</x-filament-panels::page>
