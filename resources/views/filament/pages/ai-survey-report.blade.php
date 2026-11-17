@php
    $n = fn ($v) => $v === null ? '–' : number_format($v, is_float($v) ? 1 : 0, ',', '.');
    $pct = fn ($v) => $v === null ? '–' : str_replace('.', ',', (string) $v).'%';
    $time = fn ($h) => $h === null ? '–' : ($h < 48 ? $n($h).' jam' : $n(round($h / 24, 1)).' hari');
@endphp
<x-filament-panels::page>
    <style>
        .as-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: .75rem; }
        @media (min-width: 1024px) { .as-kpis { grid-template-columns: repeat(4, 1fr); } }
        .as-kpi { border: 1px solid var(--gray-200); border-radius: .85rem; padding: 1rem; background: #fff; min-width: 0; }
        .as-kpi .l { font-size: .8125rem; color: var(--gray-500); }
        .as-kpi .v { font-size: 1.4rem; font-weight: 800; margin-top: .15rem; word-break: break-word; }
        .as-kpi .s { font-size: .75rem; color: var(--gray-500); margin-top: .2rem; }
        .as-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 1024px) { .as-grid { grid-template-columns: 1fr 2fr; } }
        .as-grid > * { min-width: 0; }
        .as-tbl { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .as-tbl th { text-align: left; font-weight: 600; color: var(--gray-600); padding: .4rem .5rem; border-bottom: 1px solid var(--gray-200); }
        .as-tbl td { padding: .5rem; border-bottom: 1px solid var(--gray-100); vertical-align: top; }
        .as-tbl .n { text-align: right; font-variant-numeric: tabular-nums; }
        .as-muted { color: var(--gray-400); }
        .as-bad { color: #dc2626; }
        /* Phones: each stage becomes a small card, figures labelled under the stage name */
        @media (max-width: 639px) {
            .as-kpi .v { font-size: 1.15rem; }
            .as-tbl thead { display: none; }
            .as-tbl tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .3rem .75rem; padding: .6rem 0; border-bottom: 1px solid var(--gray-100); }
            .as-tbl td { padding: 0; border: 0; }
            .as-tbl td:first-child { grid-column: 1 / -1; font-weight: 600; }
            .as-tbl td.n { text-align: left; }
            .as-tbl td[data-label]::before { content: attr(data-label); display: block; font-size: .75rem; color: var(--gray-500); }
        }
    </style>

    <div>{{ $this->periodForm }}</div>

    <div class="as-kpis">
        <div class="as-kpi"><div class="l">Percakapan baru</div><div class="v">{{ $n($data['conversations']) }}</div><div class="s">{{ $n($data['answered']) }} sudah dijawab</div></div>
        <div class="as-kpi"><div class="l">Membalas pesan kedua</div><div class="v">{{ $pct($data['replied_rate']) }}</div><div class="s">{{ $n($data['replied']) }} chat lanjut setelah jawaban pertama</div></div>
        <div class="as-kpi"><div class="l">Survei dijadwalkan AI</div><div class="v">{{ $n($data['surveys']) }}</div><div class="s">{{ $n($data['surveys_auto']) }} otomatis, {{ $n($data['surveys_suggested']) }} dari saran AI</div></div>
        <div class="as-kpi"><div class="l">Rata-rata sampai survei</div><div class="v">{{ $time($data['hours_to_survey']) }}</div><div class="s">dari lead masuk sampai survei dijadwalkan</div></div>
    </div>

    <div class="as-grid">
        <x-filament::section heading="Serah-terima ke agen" description="{{ $n($data['handoffs']) }} kali AI menyerahkan chat ke agen ({{ $period->label() }}).">
            <table class="as-tbl">
                <tbody>
                    @forelse ($data['handoff_reasons'] as $reason => $count)
                        <tr><td>{{ $reason }}</td><td class="n" data-label="Jumlah">{{ $n($count) }}</td></tr>
                    @empty
                        <tr><td>Belum ada serah-terima pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Per tahap: event Meta dan label WhatsApp" description="Lead yang masuk tahap ini, event yang dikirim ke Meta (Pelacakan) dan label yang dipasang di chat (WhatsApp Business).">
            <div style="overflow-x: auto"><table class="as-tbl">
                <thead><tr><th>Tahap</th><th class="n">Masuk</th><th>Event Meta</th><th class="n">Terkirim</th><th>Label</th><th class="n">Terpasang</th></tr></thead>
                <tbody>
                    @foreach ($data['stages'] as $s)
                        <tr>
                            <td>{{ $s['name'] }}</td>
                            <td class="n" data-label="Masuk">{{ $n($s['entered']) }}</td>
                            <td data-label="Event Meta">@if ($s['event']) {{ $s['event'] }} @else <span class="as-muted">tidak ada</span> @endif</td>
                            <td class="n" data-label="Event terkirim">@if ($s['event']) {{ $n($s['event_sent']) }}@if ($s['event_waiting']) <span class="as-muted">+{{ $s['event_waiting'] }} antre</span>@endif @if ($s['event_failed']) <span class="as-bad">{{ $s['event_failed'] }} gagal</span>@endif @else <span class="as-muted">–</span> @endif</td>
                            <td data-label="Label">@if ($s['label']) {{ $s['label'] }} @else <span class="as-muted">tidak ada</span> @endif</td>
                            <td class="n" data-label="Label terpasang">@if ($s['label_shared']) <span class="as-muted">dihitung di atas</span> @elseif ($s['label']) {{ $n($s['label_sent']) }}@if ($s['label_open']) <span class="as-muted">+{{ $s['label_open'] }} menunggu</span>@endif @if ($s['label_failed']) <span class="as-bad">{{ $s['label_failed'] }} gagal</span>@endif @else <span class="as-muted">–</span> @endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
