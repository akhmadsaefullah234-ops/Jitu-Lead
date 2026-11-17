@php
    $readiness = $this->readiness();
    $latest = $this->latest();
@endphp
<x-filament-panels::page>
    <div @if ($this->running()) wire:poll.5s @endif></div>
    @unless ($this->aiConfigured())
        <div style="padding:.75rem 1rem;border:1px solid #fecaca;background:#fef2f2;border-radius:.6rem;font-size:.9rem">
            Layanan AI belum diaktifkan di server ini, jadi AI belum bisa diuji. Hubungi penyedia aplikasi.
        </div>
    @endunless

    <x-filament::section heading="Kesiapan AI" :description="'Skor '.$readiness['score'].' dari 100. AI boleh membalas otomatis setelah skor minimal '.\App\Ai\AiReadiness::PASS.' dan semua yang wajib terpenuhi.'">
        <div data-readiness="{{ $readiness['passed'] ? 'passed' : 'not-yet' }}" style="display:flex;flex-direction:column;gap:.6rem;font-size:.9rem">
            <div style="font-weight:700;font-size:1.1rem;color:{{ $readiness['passed'] ? '#166534' : '#92400e' }}">
                {{ $readiness['score'] }}/100 · {{ $readiness['passed'] ? 'Siap membalas otomatis' : 'Belum siap' }}
            </div>
            @foreach ($readiness['items'] as $item)
                <div style="display:flex;gap:.75rem;align-items:flex-start" data-item="{{ $item['key'] }}">
                    <span style="flex:none;border-radius:9999px;padding:.1rem .6rem;font-size:.75rem;font-weight:700;background:{{ $item['ok'] ? '#dcfce7' : ($item['required'] ? '#fee2e2' : '#fef3c7') }};color:{{ $item['ok'] ? '#166534' : ($item['required'] ? '#991b1b' : '#92400e') }}">
                        {{ $item['ok'] ? 'Ada' : ($item['required'] ? 'Wajib' : 'Belum') }}
                    </span>
                    <div>
                        <div style="font-weight:600">{{ $item['label'] }} <span style="color:var(--gray-500);font-weight:400">({{ $item['weight'] }} poin)</span></div>
                        @unless ($item['ok'])
                            <div style="color:var(--gray-600)">{{ $item['hint'] }}</div>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Hasil uji terakhir" :description="$this->autoEval() ? 'Uji otomatis menyala: AI diuji setelah pengetahuan berubah dan tiap malam.' : 'Uji otomatis mati. Pakai Uji AI saya, atau nyalakan uji otomatis.'">
        @if ($this->running())
            <div style="margin-bottom:.75rem;color:var(--primary-700)">Uji AI sedang berjalan...</div>
        @endif
        @if ($latest)
            <div style="font-weight:700;margin-bottom:.5rem" data-score="{{ $latest->score() }}">
                {{ $latest->passed }} dari {{ $latest->total }} lulus ({{ $latest->score() }}%) · {{ \App\Models\AiEvaluation::TRIGGERS[$latest->trigger] ?? $latest->trigger }} · {{ $latest->created_at->timezone(app(\App\Support\CurrentTenant::class)->get()->timezone ?: config('app.timezone'))->translatedFormat('j M Y H.i') }}
            </div>
            <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.875rem">
                @foreach ($latest->results as $r)
                    <div>
                        <span style="font-weight:600;color:{{ $r['passed'] ? '#166534' : '#991b1b' }}">{{ $r['passed'] ? 'Lulus' : 'Gagal' }}</span>
                        · {{ $r['title'] }} <span style="color:var(--gray-500)">({{ $r['outcome'] }})</span>
                        @unless ($r['passed'])
                            <div style="color:var(--gray-600)">{{ implode('; ', $r['problems']) }}</div>
                            @if ($r['reply'])
                                <div style="color:var(--gray-500)">Jawaban AI: {{ \Illuminate\Support\Str::limit($r['reply'], 300) }}</div>
                            @endif
                        @endunless
                    </div>
                @endforeach
            </div>
        @else
            <div style="color:var(--gray-500)">Belum pernah diuji. Tekan <b>Uji AI saya</b>.</div>
        @endif
    </x-filament::section>

    @if ($this->history()->count() > 1)
        <x-filament::section heading="Riwayat">
            <div style="font-size:.875rem;display:flex;flex-direction:column;gap:.25rem">
                @foreach ($this->history() as $run)
                    <div>{{ $run->created_at->timezone(app(\App\Support\CurrentTenant::class)->get()->timezone ?: config('app.timezone'))->translatedFormat('j M H.i') }} · {{ \App\Models\AiEvaluation::TRIGGERS[$run->trigger] ?? $run->trigger }} · {{ $run->passed }}/{{ $run->total }} ({{ $run->score() }}%)</div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
