@php($record = $record ?? (isset($getRecord) ? $getRecord() : null))
@if ($record)
    <div style="display:grid;gap:1rem;font-size:.875rem">
        <div>
            <div style="font-weight:600;margin-bottom:.3rem">Tautan (bio Instagram, iklan, pesan WhatsApp)</div>
            <input readonly value="{{ $record->publicUrl() }}" onclick="this.select()" style="width:100%;font:.8rem/1.5 ui-monospace,monospace;padding:.6rem;border:1px solid var(--gray-300);border-radius:.5rem;background:var(--gray-50)">
        </div>
        <div>
            <div style="font-weight:600;margin-bottom:.3rem">Pasang di website lain (iframe)</div>
            <textarea readonly rows="3" onclick="this.select()" style="width:100%;font:.8rem/1.5 ui-monospace,monospace;padding:.6rem;border:1px solid var(--gray-300);border-radius:.5rem;background:var(--gray-50)">{{ $record->embedCode() }}</textarea>
        </div>
        <div style="color:var(--gray-600)">Di halaman landing, tambahkan bagian "Formulir pendaftaran" lalu pilih formulir ini.</div>
    </div>
@endif
