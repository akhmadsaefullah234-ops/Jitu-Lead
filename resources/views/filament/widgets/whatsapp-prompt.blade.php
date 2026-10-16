<x-filament-widgets::widget>
    @unless ($done)
    <div x-data="{ open: true }" x-show="open" style="border-radius:.9rem;padding:1rem 1.1rem;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46">
        <form wire:submit="save" style="display:grid;gap:.7rem">
            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start">
                <div>
                    <strong style="font-size:1rem">Lengkapi nomor WhatsApp Anda</strong>
                    <div style="font-size:.88rem;margin-top:.15rem">Kami memakainya untuk informasi penting tentang akun dan bantuan memakai JITU LEAD.</div>
                </div>
                <button type="button" wire:click="dismiss" x-on:click="open = false" aria-label="Tutup" title="Tutup" style="font-size:1.3rem;line-height:1;background:none;border:0;color:inherit;cursor:pointer">&times;</button>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                <input type="tel" wire:model="whatsapp" placeholder="0812 3456 7890" aria-label="Nomor WhatsApp" maxlength="30"
                    style="flex:1 1 14rem;min-width:0;border:1px solid #6ee7b7;border-radius:.6rem;padding:.55rem .75rem;background:#fff;color:#111827">
                <button type="submit" style="background:#059669;color:#fff;border:0;border-radius:.6rem;padding:.55rem 1rem;font-weight:700;cursor:pointer">Simpan</button>
            </div>
            @error('whatsapp') <div style="color:#b91c1c;font-size:.85rem">{{ $message }}</div> @enderror
            <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.88rem">
                <input type="checkbox" wire:model="consent" style="margin-top:.2rem">
                <span>Saya bersedia dihubungi via WhatsApp/email tentang akun dan penawaran JITU LEAD</span>
            </label>
        </form>
    </div>
    @endunless
</x-filament-widgets::widget>
