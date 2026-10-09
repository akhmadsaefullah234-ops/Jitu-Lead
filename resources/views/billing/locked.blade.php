<x-filament::section heading="Fitur ini belum termasuk paket Anda" icon="heroicon-o-lock-closed">
    <p style="font-size:.9rem;line-height:1.6;margin-bottom:.75rem">{{ $reason }}</p>
    <x-filament::button tag="a" :href="\App\Filament\Pages\Subscription::getUrl()">Lihat paket</x-filament::button>
</x-filament::section>
