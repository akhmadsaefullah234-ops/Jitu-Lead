<x-filament-panels::page>
    <x-filament::section>
        @if ($locked)
            <div style="display:grid;gap:.85rem;font-size:.95rem;line-height:1.6">
                <p style="font-size:1.1rem;font-weight:700">Masa percobaan {{ $tenant->name }} sudah berakhir{{ $endedAt ? ' pada '.$endedAt->translatedFormat('j F Y') : '' }}.</p>
                <p>Semua menu dikunci sampai agensi memilih paket berlangganan. Data Anda tetap aman: lead, chat WhatsApp, dan formulir web yang masuk tetap tersimpan di CRM, dan bisa dibuka lagi begitu langganan aktif.</p>
                @if ($this->isAdmin())
                    <div><x-filament::button tag="a" :href="\App\Filament\Pages\Subscription::getUrl()" icon="heroicon-o-credit-card">Pilih paket dan perpanjang</x-filament::button></div>
                @else
                    @php($admins = $this->adminNames())
                    <p>Untuk membuka kembali, minta admin agensi{{ $admins->isNotEmpty() ? ' ('.$admins->join(', ', ' dan ').')' : '' }} memilih paket di menu Langganan.</p>
                @endif
            </div>
        @else
            <p style="font-size:.95rem">Langganan agensi aktif, semua menu sudah bisa dipakai.</p>
            <div style="margin-top:.75rem"><x-filament::button tag="a" :href="\App\Filament\Pages\Dashboard::getUrl()">Ke dasbor</x-filament::button></div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
