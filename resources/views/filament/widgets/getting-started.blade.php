<x-filament-widgets::widget>
    <x-filament::section>
        <div style="display:flex;flex-direction:column;gap:.5rem">
            <h2 style="font-size:1.25rem;font-weight:800">Selamat datang di JITU LEAD</h2>
            <p style="color:var(--gray-600)">Belum ada lead. Mulai dengan salah satu cara ini:</p>
            <ul style="list-style:disc;padding-left:1.25rem;line-height:1.8">
                <li><a href="{{ \App\Filament\Resources\Leads\LeadResource::getUrl('create') }}" style="color:var(--primary-600);font-weight:600">Tambah lead pertama</a></li>
                <li><a href="{{ \App\Filament\Pages\ImportLeads::getUrl() }}" style="color:var(--primary-600);font-weight:600">Impor lead dari file CSV</a></li>
                @if ($this->isAdmin())
                    <li>Pasang formulir di website lewat menu <b>Pengaturan, Formulir web</b></li>
                    <li>Atau klik <b>Isi data contoh</b> di kanan atas untuk melihat dasbor dan pipeline berisi data.</li>
                @endif
            </ul>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
