<div wire:poll.{{ $open ? '5s' : '30s' }} style="position:fixed;right:1rem;bottom:1rem;z-index:50;font-family:inherit">
    @if ($open)
        <div style="width:min(22rem,calc(100vw - 2rem));background:#fff;border:1px solid #e5e7eb;border-radius:1rem;box-shadow:0 20px 50px rgba(0,0,0,.25);display:flex;flex-direction:column;max-height:min(32rem,calc(100vh - 6rem))">
            <div style="background:#dc2626;color:#fff;padding:.8rem 1rem;border-radius:1rem 1rem 0 0;display:flex;justify-content:space-between;align-items:center">
                <div><b>Bantuan JITU LEAD</b><div style="font-size:.75rem;opacity:.9">Ada kendala? Tulis di sini, kami balas.</div></div>
                <button type="button" wire:click="toggle" aria-label="Tutup" style="color:#fff;font-size:1.3rem;line-height:1">&times;</button>
            </div>
            <div style="padding:.8rem;overflow:auto;flex:1;display:flex;flex-direction:column;gap:.5rem;min-height:8rem">
                @forelse ($messages as $m)
                    <div style="align-self:{{ $m->from_staff ? 'flex-start' : 'flex-end' }};max-width:85%;background:{{ $m->from_staff ? '#f3f4f6' : '#fee2e2' }};border-radius:.8rem;padding:.5rem .7rem">
                        <div style="font-size:.68rem;color:#6b7280">{{ $m->from_staff ? 'Tim support' : 'Anda' }} · {{ $m->created_at->timezone(config('app.timezone'))->format('d M H:i') }}</div>
                        <div style="white-space:pre-wrap;word-break:break-word;font-size:.9rem">{{ $m->body }}</div>
                    </div>
                @empty
                    <p style="color:#6b7280;font-size:.88rem;margin:0">Halo! Ceritakan kendala Anda (fitur mana, apa yang terjadi). Tim kami akan membalas di sini.</p>
                @endforelse
                @if ($thread && $thread->status === 'closed')
                    <p style="color:#6b7280;font-size:.78rem;margin:0;text-align:center">Percakapan ditandai selesai. Kirim pesan baru untuk membukanya lagi.</p>
                @endif
            </div>
            <form wire:submit="send" style="border-top:1px solid #e5e7eb;padding:.6rem;display:flex;flex-direction:column;gap:.4rem">
                @if ($notice)<div style="color:#b91c1c;font-size:.8rem">{{ $notice }}</div>@endif
                <textarea wire:model="body" rows="2" maxlength="2000" placeholder="Tulis pesan…" style="width:100%;border:1px solid #d1d5db;border-radius:.6rem;padding:.5rem;font-size:.9rem"></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                    @if ($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#166534">Atau lewat WhatsApp</a>@else<span></span>@endif
                    <button type="submit" style="background:#dc2626;color:#fff;font-weight:700;border-radius:.6rem;padding:.4rem 1rem">Kirim</button>
                </div>
            </form>
        </div>
    @else
        <button type="button" wire:click="toggle" aria-label="Buka chat bantuan" style="position:relative;background:#dc2626;color:#fff;font-weight:700;border-radius:9999px;padding:.65rem 1.1rem;box-shadow:0 8px 20px rgba(220,38,38,.4)">
            Bantuan
            @if ($unread)<span style="position:absolute;top:-.3rem;right:-.2rem;background:#111827;color:#fff;border-radius:9999px;font-size:.7rem;padding:0 .4rem">!</span>@endif
        </button>
    @endif
</div>
