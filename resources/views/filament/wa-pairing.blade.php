<div wire:poll.3s class="text-center text-sm" style="line-height:1.6">
    @if ($pairing['status'] === 'connected')
        <p style="font-size:1.1rem;font-weight:600;color:#16a34a">Terhubung &#10003;</p>
        <p>WhatsApp sudah tersambung ke CRM. Anda bisa menutup jendela ini.</p>
    @elseif ($pairing['status'] === 'error')
        <p style="font-weight:600;color:#dc2626">Belum bisa menampilkan QR</p>
        <p>{{ $pairing['error'] }}</p>
    @else
        <ol style="list-style:decimal;text-align:left;padding-left:1.25rem;margin-bottom:.75rem">
            <li>Di HP, buka <strong>WhatsApp &rarr; Perangkat tertaut</strong>.</li>
            <li>Ketuk <strong>Tautkan perangkat</strong>.</li>
            <li>Arahkan kamera ke QR di bawah ini.</li>
        </ol>
        @if ($pairing['qr'])
            <img src="{{ $pairing['qr'] }}" alt="QR WhatsApp" style="margin:0 auto;width:16rem;max-width:100%;background:#fff;padding:.5rem;border-radius:.5rem">
            <p style="margin-top:.5rem;opacity:.7">QR berganti otomatis. Halaman ini memeriksa koneksi tiap 3 detik.</p>
        @else
            <p style="opacity:.7">Menyiapkan QR...</p>
        @endif
    @endif
</div>
