<x-filament-panels::page>
    @if ($this->url())
        <x-filament::section heading="Alamat formulir" description="Kirim data formulir dengan metode POST ke alamat ini. Jangan bagikan alamat ke orang lain di luar tim.">
            <code style="word-break: break-all">{{ $this->url() }}</code>
        </x-filament::section>
        <x-filament::section heading="Contoh formulir HTML" description="Tempel di website atau landing page. Kolom 'website' disembunyikan untuk menjebak robot, jangan dihapus.">
            <pre style="white-space: pre-wrap; font-size: .8125rem; user-select: all">{{ $this->snippet() }}</pre>
        </x-filament::section>
        <x-filament::section heading="Tanpa HTML formulir" description="Builder landing page atau aplikasi lain bisa mengirim JSON (Content-Type: application/json) dengan kolom name, phone, email, note, source.">
        </x-filament::section>
    @else
        <x-filament::section heading="Formulir belum aktif">
            Klik <b>Aktifkan formulir</b> di kanan atas untuk membuat alamatnya.
        </x-filament::section>
    @endif
</x-filament-panels::page>
