<x-filament-panels::page>
    @if ($this->lockedReason())
        @include('billing.locked', ['reason' => $this->lockedReason()])
    @else
    <style>
        .rp-bar { display: flex; flex-wrap: wrap; gap: .5rem; }
        .rp-chip { border: 1px solid var(--gray-300); background: #fff; border-radius: 9999px; padding: .3rem .9rem; font-size: .8125rem; font-weight: 500; }
        .rp-chip[aria-pressed="true"] { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
        .rp-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: .75rem; }
        @media (min-width: 1024px) { .rp-kpis { grid-template-columns: repeat(4, 1fr); } }
        .rp-kpi { border: 1px solid var(--gray-200); border-radius: .85rem; padding: 1rem; background: #fff; }
        .rp-kpi .l { font-size: .8125rem; color: var(--gray-500); }
        .rp-kpi .v { font-size: 1.4rem; font-weight: 800; margin-top: .15rem; word-break: break-word; }
        .rp-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 1024px) { .rp-grid { grid-template-columns: 1fr 1fr; } }
        .rp-tbl { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .rp-tbl th { text-align: left; font-weight: 600; color: var(--gray-600); padding: .4rem .5rem; border-bottom: 1px solid var(--gray-200); }
        .rp-tbl td { padding: .5rem; border-bottom: 1px solid var(--gray-100); }
        .rp-tbl .n { text-align: right; font-variant-numeric: tabular-nums; }
        .rp-scroll { overflow-x: auto; }
        .rp-f { display: grid; grid-template-columns: 8.5rem 1fr 2rem; align-items: center; gap: .5rem; font-size: .8125rem; margin: .35rem 0; }
        .rp-f .b { height: .7rem; border-radius: 9999px; background: var(--gray-100); overflow: hidden; }
        .rp-f .b i { display: block; height: 100%; border-radius: 9999px; background: var(--primary-600); }
        .rp-f .b i.won { background: #16a34a; } .rp-f .b i.lost { background: #9ca3af; }
    </style>

    <div class="rp-bar">
        @foreach ($periods as $d => $label)
            <button type="button" class="rp-chip" aria-pressed="{{ $days === $d ? 'true' : 'false' }}" wire:click="$set('days', {{ $d }})">{{ $label }}</button>
        @endforeach
    </div>

    <div class="rp-kpis">
        @foreach ($summary as $label => $value)
            <div class="rp-kpi"><div class="l">{{ $label }}</div><div class="v">{{ $value }}</div></div>
        @endforeach
    </div>

    <div class="rp-grid">
        <x-filament::section heading="Corong pipeline" description="Jumlah lead yang masuk pada periode ini, menurut tahap sekarang.">
            @foreach ($funnel as $row)
                <div class="rp-f"><span>{{ $row['name'] }}</span><span class="b"><i class="{{ $row['type'] }}" style="width: {{ $row['count'] / $max * 100 }}%"></i></span><b>{{ $row['count'] }}</b></div>
            @endforeach
        </x-filament::section>

        <x-filament::section heading="Per sumber lead">
            <div class="rp-scroll"><table class="rp-tbl">
                <thead><tr><th>Sumber</th><th class="n">Lead</th><th class="n">Closing</th><th class="n">Konversi</th></tr></thead>
                <tbody>
                    @forelse ($perSource as $r)
                        <tr><td>{{ $r['name'] }}</td><td class="n">{{ $r['leads'] }}</td><td class="n">{{ $r['won'] }}</td><td class="n">{{ $r['rate'] }}</td></tr>
                    @empty
                        <tr><td colspan="4">Belum ada data pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </x-filament::section>

        <x-filament::section heading="Per agen" style="grid-column: 1 / -1">
            <div class="rp-scroll"><table class="rp-tbl">
                <thead><tr><th>Agen</th><th class="n">Lead</th><th class="n">Closing</th><th class="n">Konversi</th><th class="n">Nilai transaksi</th></tr></thead>
                <tbody>
                    @forelse ($perAgent as $r)
                        <tr><td>{{ $r['name'] }}</td><td class="n">{{ $r['leads'] }}</td><td class="n">{{ $r['won'] }}</td><td class="n">{{ $r['rate'] }}</td><td class="n">Rp {{ number_format($r['value'], 0, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="5">Belum ada data pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    </div>
    @endif
</x-filament-panels::page>
