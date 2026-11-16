@php($guide = \App\WhatsApp\BusinessLabels::GUIDE)
@if ($known === [])
    <span style="color:var(--gray-500)">Belum ada label yang dimuat. Klik <strong>Muat label dari WhatsApp</strong>.</span>
    <div class="text-sm" style="color:var(--gray-600);margin-top:.25rem">{{ $guide }}</div>
@else
    <div class="text-sm" style="line-height:1.7">
        @foreach ($checks as $rule => $found)
            <div>
                @if ($found !== null)
                    <span style="color:var(--success-600);font-weight:700">&#10003;</span> {{ $rule }}@if ($found !== $rule) <span style="color:var(--gray-500)">(sebagai "{{ $found }}")</span>@endif
                @else
                    <span style="color:var(--danger-600);font-weight:700">&#10007;</span> {{ $rule }} <span style="color:var(--gray-500)">belum ada</span>
                @endif
                @unless (\App\WhatsApp\BusinessLabels::isSignal($rule))
                    <span style="color:var(--warning-600)">bukan sinyal Meta</span>
                @endunless
            </div>
        @endforeach
        <div style="color:var(--gray-500)">Semua label: {{ implode(', ', $known) }}</div>
        @if ($stale ?? false)
            <div style="color:var(--warning-700)">Gateway hanya melihat daftar bawaan WhatsApp, belum satu pun label bisnis. Daftar label di gateway kemungkinan usang: pakai label sekali di HP, lalu muat lagi.</div>
        @endif
        @if (in_array(null, $checks, true))
            <div style="color:var(--gray-600);margin-top:.25rem">{{ $guide }}</div>
        @endif
    </div>
@endif
