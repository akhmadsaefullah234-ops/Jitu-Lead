<x-filament-panels::page>
    <div wire:poll.10s style="max-width:46rem">
        <p style="color:#6b7280;margin:0 0 1rem">
            {{ $record->tenant?->name }} · {{ $record->user?->name ?? 'Pengguna dihapus' }} @if ($record->user)({{ $record->user->email }})@endif
            · {{ $record->status === 'open' ? 'Terbuka' : 'Selesai' }}
        </p>

        <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem">
            @foreach ($this->messages() as $m)
                <div style="align-self:{{ $m->from_staff ? 'flex-end' : 'flex-start' }};max-width:80%;background:{{ $m->from_staff ? '#fee2e2' : '#f3f4f6' }};border-radius:.8rem;padding:.6rem .8rem">
                    <div style="font-size:.72rem;color:#6b7280;margin-bottom:.15rem">{{ $m->from_staff ? 'Tim support' : ($m->author?->name ?? 'Pengguna') }} · {{ $m->created_at->timezone(config('app.timezone'))->format('d M H:i') }}</div>
                    <div style="white-space:pre-wrap;word-break:break-word">{{ $m->body }}</div>
                </div>
            @endforeach
        </div>

        <form wire:submit="send" style="display:flex;flex-direction:column;gap:.5rem">
            <textarea wire:model="reply" rows="3" maxlength="2000" placeholder="Tulis balasan…" style="width:100%;border:1px solid #d1d5db;border-radius:.6rem;padding:.6rem"></textarea>
            <div style="display:flex;gap:.5rem">
                <x-filament::button type="submit">Kirim balasan</x-filament::button>
                @if ($record->status === 'open')
                    <x-filament::button color="gray" wire:click="close" type="button">Tandai selesai</x-filament::button>
                @else
                    <x-filament::button color="gray" wire:click="reopen" type="button">Buka lagi</x-filament::button>
                @endif
            </div>
        </form>
    </div>
</x-filament-panels::page>
