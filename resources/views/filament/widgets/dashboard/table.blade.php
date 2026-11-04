{{-- A plain table of numbers for the Dashboard: $heading, $description, $columns [key => [label, numeric]], $rows (each may carry "url"), $empty. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" :description="$description">
        @if ($rows === [])
            <p class="jl-dash-empty">{{ $empty }}</p>
        @else
            <div class="jl-dash-scroll">
                <table class="jl-dash-table">
                    <thead>
                        <tr>
                            @foreach ($columns as $key => [$label, $numeric])
                                <th @class(['num' => $numeric])>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($columns as $key => [$label, $numeric])
                                    <td @class(['num' => $numeric]) data-label="{{ $label }}">
                                        @if ($loop->first && ! empty($row['url']))
                                            <a href="{{ $row['url'] }}" class="jl-dash-link">{{ $row[$key] }}</a>
                                        @else
                                            {{ $row[$key] }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
    @include('filament.widgets.dashboard.style')
</x-filament-widgets::widget>
