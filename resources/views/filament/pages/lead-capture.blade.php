<x-filament-panels::page>
    @if ($this->formUrl())
        <style>
            .lc { display: grid; gap: 1.25rem; grid-template-columns: 1fr; align-items: start; }
            @media (min-width: 1024px) { .lc { grid-template-columns: 26rem 1fr; } }
            .lc-frame { border: 1px solid var(--gray-200); border-radius: 1rem; overflow: hidden; background: #fff; box-shadow: 0 1px 2px rgba(17,24,39,.05); }
            .lc-frame .cap { padding: .6rem 1rem; font-size: .8125rem; color: var(--gray-600); border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; gap: .5rem; }
            .lc-frame iframe { display: block; width: 100%; height: 600px; border: 0; }
            .lc-code { width: 100%; font: .78rem/1.5 ui-monospace, monospace; padding: .75rem; border: 1px solid var(--gray-300); border-radius: .6rem; background: var(--gray-50); resize: vertical; }
            .lc-stack { display: grid; gap: 1.25rem; }
        </style>
        <div class="lc">
            <div class="lc-frame">
                <div class="cap"><span>Tampilan yang dilihat pengunjung</span><a href="{{ $this->formUrl() }}" target="_blank" rel="noopener" style="color:var(--primary-600);font-weight:600">Buka</a></div>
                <iframe src="{{ $this->previewUrl() }}" title="Pratinjau formulir"></iframe>
            </div>
            <div class="lc-stack">
                <x-filament::section heading="Cara 1: tautan" description="Bagikan di bio Instagram, iklan, atau pesan WhatsApp. Halaman formulir ini berdiri sendiri.">
                    <input class="lc-code" readonly value="{{ $this->formUrl() }}" onclick="this.select()">
                </x-filament::section>
                <x-filament::section heading="Cara 2: pasang di website (iframe)" description="Tempel kode ini di website mana pun. Tampilannya sama dengan di kiri.">
                    <textarea class="lc-code" rows="3" readonly onclick="this.select()">{{ $this->iframeCode() }}</textarea>
                </x-filament::section>
                <x-filament::section heading="Cara 3: formulir HTML sendiri" description="Untuk yang sudah punya desain sendiri. Kirim dengan metode POST; kolom 'website' adalah jebakan robot, jangan dihapus." collapsible collapsed>
                    <textarea class="lc-code" rows="9" readonly onclick="this.select()">{{ $this->htmlCode() }}</textarea>
                    <p style="font-size:.8125rem;color:var(--gray-600);margin-top:.5rem">Alamat ini terbuka untuk umum. Kalau disalahgunakan, klik Ganti alamat.</p>
                </x-filament::section>
                <x-filament::section heading="Mau halaman utuh, bukan hanya formulir?">
                    Buat halaman landing lengkap dengan foto, rincian, peta, dan formulir di menu
                    <a href="{{ \App\Filament\Resources\LandingPages\LandingPageResource::getUrl() }}" style="color:var(--primary-600);font-weight:600">Halaman landing</a>.
                </x-filament::section>
            </div>
        </div>
    @else
        <x-filament::section heading="Formulir belum aktif">
            Klik <b>Aktifkan formulir</b> di kanan atas. Setelah itu Anda langsung melihat tampilannya dan mendapat tautan serta kode untuk dipasang di website.
        </x-filament::section>
    @endif
</x-filament-panels::page>
