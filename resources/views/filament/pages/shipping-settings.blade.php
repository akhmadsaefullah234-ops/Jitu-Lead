<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:46rem">
        Ongkir dihitung dari tabel di bawah, tanpa API kurir. AI wajib memakai tabel ini untuk menjawab ongkir dan tidak akan menebak angka sendiri.
        Isi berat dan ukuran tiap produk di menu <a href="{{ \App\Filament\Resources\Properties\PropertyResource::getUrl() }}" style="text-decoration:underline">{{ \App\Support\Industry::t('catalog') }}</a> agar ongkir tepat.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>

    <form wire:submit="saveCarrier" style="display:grid;gap:1rem">
        {{ $this->carrierForm }}
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <x-filament::button type="submit">Simpan pengiriman otomatis</x-filament::button>
            @if (\App\Models\CarrierAccount::query()->exists())
                <x-filament::button color="gray" wire:click="testConnection" type="button">Uji koneksi</x-filament::button>
            @endif
        </div>
    </form>

    @if (\App\Models\CarrierAccount::query()->exists())
        <form wire:submit="saveNotices" style="display:grid;gap:1rem">
            {{ $this->noticesForm }}
            <div><x-filament::button type="submit">Simpan pesan status</x-filament::button></div>
        </form>
    @endif
</x-filament-panels::page>
