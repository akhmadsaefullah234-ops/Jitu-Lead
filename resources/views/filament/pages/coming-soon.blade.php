<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-direction:column;gap:.75rem;max-width:42rem">
            <span class="fi-badge" style="align-self:flex-start;background:#fef2f2;color:#b91c1c;border-radius:9999px;padding:.15rem .7rem;font-size:.75rem;font-weight:700">Segera hadir</span>
            <p>Fitur ini sudah dirancang dan akan dibuka di pembaruan berikutnya. Rencananya:</p>
            <ul style="list-style:disc;padding-left:1.25rem;line-height:1.8">
                @foreach ($this->points() as $point)
                    <li>{{ $point }}</li>
                @endforeach
            </ul>
        </div>
    </x-filament::section>
</x-filament-panels::page>
