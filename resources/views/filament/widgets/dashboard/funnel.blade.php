<x-filament-widgets::widget>
    <x-filament::section heading="Corong tahap" description="Lead yang masuk pada periode ini dan tahap terjauh yang sudah dicapai.">
        @if ($top <= 1 && ($stages[0]['count'] ?? 0) === 0)
            <p class="jl-dash-empty">Belum ada lead pada periode ini.</p>
        @else
            <div class="jl-funnel">
                @foreach ($stages as $s)
                    <div class="row">
                        <span>{{ $s['name'] }}</span>
                        <div class="bar"><span @class(['won' => $s['type'] === 'won']) style="width: {{ round($s['count'] / $top * 100) }}%"></span></div>
                        <span class="n">{{ number_format($s['count'], 0, ',', '.') }} <small>{{ round($s['count'] / $top * 100) }}%</small></span>
                    </div>
                @endforeach
                @if ($lost > 0)
                    <p class="jl-dash-empty">{{ number_format($lost, 0, ',', '.') }} lead dari periode ini sekarang di tahap gugur/batal.</p>
                @endif
            </div>
        @endif
    </x-filament::section>
    @include('filament.widgets.dashboard.style')
</x-filament-widgets::widget>
