<x-filament-widgets::widget>
    <div style="display:grid;gap:.5rem">
        @foreach ($alerts as $a)
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem;align-items:center;border-radius:.75rem;padding:.7rem 1rem;font-size:.9rem;{{ $a['level'] === 'danger' ? 'background:#fee2e2;color:#991b1b' : 'background:#fef3c7;color:#92400e' }}">
                <span>{{ $a['text'] }}</span>
                <a href="{{ $url }}" style="font-weight:700;text-decoration:underline">Lihat paket</a>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
