@if ($known === [])
    <span style="color:var(--gray-500)">Belum ada label yang dimuat. Klik <strong>Muat label dari WhatsApp</strong>.</span>
@else
    <div class="text-sm" style="line-height:1.7">
        @foreach ($checks as $rule => $found)
            <div>
                @if ($found !== null)
                    <span style="color:var(--success-600);font-weight:700">&#10003;</span> {{ $rule }}@if ($found !== $rule) <span style="color:var(--gray-500)">(sebagai "{{ $found }}")</span>@endif
                @else
                    <span style="color:var(--danger-600);font-weight:700">&#10007;</span> {{ $rule }} <span style="color:var(--gray-500)">belum ada: pasang sekali di HP, lalu muat lagi</span>
                @endif
            </div>
        @endforeach
        <div style="color:var(--gray-500)">Semua label: {{ implode(', ', $known) }}</div>
    </div>
@endif
