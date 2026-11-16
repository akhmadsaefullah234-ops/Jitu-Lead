@php
    $colors = ['ok' => ['#dcfce7', '#166534', 'Siap'], 'warn' => ['#fef3c7', '#92400e', 'Perlu dicek'], 'fail' => ['#fee2e2', '#991b1b', 'Belum'], 'manual' => ['#e0e7ff', '#3730a3', 'Cek manual']];
    $summary = $this->summary();
    $triggers = ['stage' => 'pindah tahap', 'hot' => 'Hot', 'reply' => 'klien membalas', 'form' => 'formulir'];
    $statuses = \App\Models\WaLabelEvent::STATUSES;
@endphp
<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:48rem">
        Iklan Klik ke WhatsApp belajar dari sinyal yang Anda kirim ke Meta: event Conversions API dari pipeline (Lead, QualifiedLead, Schedule,
        InitiateCheckout, Purchase) dan label WhatsApp Business (Prospek, Order baru, Lunas). Halaman ini memeriksa semuanya dan tidak mengubah apa pun.
    </p>

    <x-filament::section heading="Pemeriksaan">
        <div style="display:flex;flex-direction:column;gap:.75rem">
            @foreach ($this->checks() as $check)
                @php([$bg, $fg, $word] = $colors[$check['state']])
                <div style="display:flex;gap:.75rem;align-items:flex-start" data-check="{{ $check['key'] }}">
                    <span style="flex:none;border-radius:9999px;padding:.1rem .6rem;font-size:.75rem;font-weight:700;background:{{ $bg }};color:{{ $fg }}">{{ $word }}</span>
                    <div style="font-size:.9rem">
                        <div style="font-weight:600">{{ $check['title'] }}</div>
                        <div style="color:var(--gray-600)">{{ $check['detail'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Ringkasan 30 hari" description="Bandingkan dengan Ads Manager: kampanye Klik ke WhatsApp, kolom hasil (Lead, QualifiedLead, Schedule, Purchase dari dataset Anda) dan hasil percakapan WhatsApp. Angka bisa berbeda karena Meta mencocokkan pengguna lebih dulu dan atribusinya terlambat beberapa hari.">
        <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));font-size:.9rem">
            <div>
                <div style="font-weight:600;margin-bottom:.25rem">Event ke Meta</div>
                @forelse ($summary['events'] as $event => $byTrigger)
                    <div>{{ $event }}: <b>{{ array_sum($byTrigger) }}</b> <span style="color:var(--gray-500)">({{ collect($byTrigger)->map(fn ($n, $t) => ($triggers[$t] ?? $t).' '.$n)->implode(', ') }})</span></div>
                @empty
                    <div style="color:var(--gray-500)">Belum ada event dalam 30 hari.</div>
                @endforelse
            </div>
            <div>
                <div style="font-weight:600;margin-bottom:.25rem">Label WhatsApp</div>
                @forelse ($summary['labels'] as $label => $counts)
                    <div>{{ $label }}: {{ collect($counts)->filter()->map(fn ($n, $s) => ($statuses[$s] ?? $s).' '.$n)->implode(', ') }}</div>
                @empty
                    <div style="color:var(--gray-500)">Belum ada label dalam 30 hari.</div>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
