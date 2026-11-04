<x-filament-widgets::widget>
    <x-filament::section heading="AI & otomatis" description="Yang dikerjakan AI dan pesan otomatis pada periode ini.">
        <dl class="jl-dash-list">
            @foreach ($groups as $group => $items)
                <div class="jl-dash-group">{{ $group }}</div>
                @foreach ($items as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ is_int($value) ? number_format($value, 0, ',', '.') : $value }}</dd>
                @endforeach
            @endforeach
        </dl>
    </x-filament::section>
    @include('filament.widgets.dashboard.style')
</x-filament-widgets::widget>
