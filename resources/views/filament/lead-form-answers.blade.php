@php($lead = $getRecord())
@if ($lead)
    <dl style="display:grid;grid-template-columns:minmax(8rem,auto) 1fr;gap:.35rem 1rem;font-size:.875rem;margin:0">
        @foreach ((array) ($lead->custom_fields['formulir'] ?? []) as $label => $value)
            <dt style="color:var(--gray-500)">{{ $label }}</dt><dd style="margin:0;overflow-wrap:anywhere">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
        @endforeach
    </dl>
    @if (filled($lead->custom_fields['foto_formulir'] ?? null))
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.75rem">
            @foreach ($lead->custom_fields['foto_formulir'] as $i => $photo)
                <a href="{{ route('lead.photo', [$lead->getKey(), $i]) }}" target="_blank" rel="noopener" style="display:grid;gap:.2rem;font-size:.75rem;color:var(--gray-600)">
                    <img src="{{ route('lead.photo', [$lead->getKey(), $i]) }}" alt="{{ $photo['label'] ?? 'Foto' }}" loading="lazy" style="width:7rem;height:7rem;object-fit:cover;border-radius:.5rem;border:1px solid var(--gray-200)">
                    <span>{{ $photo['label'] ?? 'Foto' }}</span>
                </a>
            @endforeach
        </div>
    @endif
@endif
