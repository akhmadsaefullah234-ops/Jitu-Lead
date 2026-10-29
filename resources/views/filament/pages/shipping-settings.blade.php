<x-filament-panels::page>
    <p style="color:var(--gray-600);max-width:46rem">
        Ongkir dihitung dari tabel di bawah, tanpa API kurir. AI wajib memakai tabel ini untuk menjawab ongkir dan tidak akan menebak angka sendiri.
        Isi berat dan ukuran tiap produk di menu <a href="{{ \App\Filament\Resources\Properties\PropertyResource::getUrl() }}" style="text-decoration:underline">{{ \App\Support\Industry::t('catalog') }}</a> agar ongkir tepat.
    </p>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
