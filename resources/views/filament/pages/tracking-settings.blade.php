<x-filament-panels::page>
    @if ($this->lockedReason())
        @include('billing.locked', ['reason' => $this->lockedReason()])
    @else
    <x-filament::section heading="Cara kerja" description="Setiap kali pengunjung mengisi formulir di halaman landing atau formulir web Anda, pixel di browser mengirim event ke platform iklan. Bila token API diisi, server juga mengirim event yang sama sehingga tetap terhitung walau browser memblokir pixel.">
        <ul style="list-style:disc;padding-left:1.25rem;line-height:1.8;font-size:.9rem">
            <li>Data kontak dikirim ke server platform dalam bentuk hash (SHA-256), tidak pernah dalam bentuk asli.</li>
            <li>Pixel hanya dipasang di halaman landing dan formulir yang diterbitkan, tidak di halaman pratinjau.</li>
            <li>Uji dulu dengan fitur Test Events di Meta atau TikTok sebelum memasang iklan sungguhan.</li>
        </ul>
    </x-filament::section>

    <x-filament::section heading="Event dari pipeline" description="Lead dari WhatsApp dan perpindahan tahap juga bisa dilaporkan ke Meta dan TikTok. Atur di tombol &quot;Event dari pipeline&quot; di atas.">
        <div style="font-size:.9rem">{{ $this->pipelineSummary() }}</div>
    </x-filament::section>

    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))">
        @foreach ($this->platforms() as $p)
            <x-filament::section :heading="$p['name']">
                <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.875rem">
                    <span class="fi-badge" style="align-self:flex-start;border-radius:9999px;padding:.1rem .65rem;font-weight:700;{{ $p['ok'] ? 'background:#dcfce7;color:#166534' : 'background:#f3f4f6;color:#4b5563' }}">{{ $p['ok'] ? 'Terpasang' : 'Belum diisi' }}</span>
                    <div>Browser: <b>{{ $p['browser'] ? 'aktif' : 'belum' }}</b> &nbsp; Server: <b>{{ $p['server'] ? 'aktif' : ($p['name'] === 'Google (GA4 / Google Ads)' ? 'tidak tersedia' : 'belum') }}</b></div>
                    <span style="color:var(--gray-600)">{{ $p['hint'] }}</span>
                    @if ($result = \App\Filament\Pages\TrackingSettings::lastResult($p['last']))
                        <div>Kiriman terakhir dari server: <b style="color:{{ ($p['last']['ok'] ?? false) ? '#166534' : '#b91c1c' }}">{{ $result }}</b></div>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>
    @endif
</x-filament-panels::page>
