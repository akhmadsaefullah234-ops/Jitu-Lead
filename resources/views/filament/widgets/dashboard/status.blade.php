<x-filament-widgets::widget>
    <x-filament::section heading="Status saat ini" description="Tidak mengikuti periode." compact>
        <div class="jl-status">
            @forelse ($channels as $c)
                <span class="pill"><span @class(['dot', 'ok' => $c->isConnected(), 'bad' => ! $c->isConnected()])></span>{{ $c->name }} · {{ $c->isConnected() ? 'tersambung' : 'terputus' }}</span>
            @empty
                <span class="pill"><span class="dot bad"></span>Belum ada nomor WhatsApp terhubung</span>
            @endforelse
            <a class="pill" href="{{ $tasksUrl ?? $overdueUrl }}"><span @class(['dot', 'bad' => $overdue > 0, 'ok' => $overdue === 0])></span>Tugas terlambat <b>{{ number_format($overdue, 0, ',', '.') }}</b></a>
            @if ($unattended !== null)
                <a class="pill" href="{{ $unattendedUrl }}"><span @class(['dot', 'bad' => $unattended > 0, 'ok' => $unattended === 0])></span>Lead belum ditangani <b>{{ number_format($unattended, 0, ',', '.') }}</b></a>
            @endif
        </div>
    </x-filament::section>
    @include('filament.widgets.dashboard.style')
</x-filament-widgets::widget>
