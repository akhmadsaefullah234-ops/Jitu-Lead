@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $limitText = fn ($n) => $n === null ? 'tanpa batas' : number_format($n, 0, ',', '.');
    $end = $sub->endsAt();
@endphp
<x-filament-panels::page>
    <style>
        .sb-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 1024px) { .sb-grid.three { grid-template-columns: repeat(3, 1fr); } .sb-grid.two { grid-template-columns: 1fr 1fr; } }
        .sb-card { border: 1px solid var(--gray-200); border-radius: .9rem; padding: 1.1rem; background: #fff; display: flex; flex-direction: column; gap: .6rem; position: relative; }
        .sb-card.pop { border-color: var(--primary-600); box-shadow: 0 0 0 2px var(--primary-600) inset; }
        .sb-card.now { background: var(--gray-50); }
        .sb-tag { position: absolute; top: -.7rem; right: 1rem; background: var(--primary-600); color: #fff; font-size: .72rem; font-weight: 700; padding: .15rem .65rem; border-radius: 9999px; }
        .sb-price { font-size: 1.6rem; font-weight: 800; }
        .sb-price small { font-size: .8rem; font-weight: 500; color: var(--gray-500); }
        .sb-list { list-style: none; padding: 0; margin: 0; display: grid; gap: .3rem; font-size: .875rem; }
        .sb-list .no { color: var(--gray-400); text-decoration: line-through; }
        .sb-bar { height: .6rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden; }
        .sb-bar > span { display: block; height: 100%; background: #16a34a; }
        .sb-bar > span.warn { background: #f59e0b; } .sb-bar > span.full { background: #dc2626; }
        .sb-row { display: grid; gap: .25rem; font-size: .875rem; }
        .sb-row .top { display: flex; justify-content: space-between; gap: .5rem; }
        .sb-seg { display: inline-flex; border: 1px solid var(--gray-300); border-radius: 9999px; overflow: hidden; background: #fff; }
        .sb-seg button { padding: .35rem 1rem; font-size: .85rem; font-weight: 600; }
        .sb-seg button[aria-pressed="true"] { background: var(--primary-600); color: #fff; }
        .sb-note { font-size: .8125rem; color: var(--gray-600); }
        .sb-pill { display: inline-block; border-radius: 9999px; padding: .1rem .7rem; font-weight: 700; font-size: .8rem; }
    </style>

    <x-filament::section heading="Paket Anda">
        <div style="display:flex;flex-wrap:wrap;gap:.75rem 2rem;align-items:center">
            <div>
                <div style="font-size:1.25rem;font-weight:800">{{ $currentPlan['name'] }}@if ($sub->status === 'trial') <span class="sb-note">(setara paket ini selama percobaan)</span>@endif</div>
                <span class="sb-pill" style="{{ in_array($sub->effectiveStatus(), ['active', 'trial']) ? 'background:#dcfce7;color:#166534' : ($sub->effectiveStatus() === 'past_due' ? 'background:#fef3c7;color:#92400e' : 'background:#fee2e2;color:#991b1b') }}">{{ $sub->statusLabel() }}</span>
            </div>
            @if ($end)
                <div class="sb-note">
                    {{ $sub->status === 'trial' ? 'Percobaan berakhir' : 'Periode berakhir' }} {{ $end->timezone(config('app.timezone'))->translatedFormat('j F Y') }}
                    @if ($sub->daysLeft() !== null) ({{ $sub->daysLeft() }} hari lagi)@endif
                </div>
            @endif
            @if ($sub->effectiveStatus() === 'past_due')
                <div class="sb-note" style="color:#92400e">Segera perpanjang. Setelah masa tenggang berakhir, akun menjadi hanya-baca.</div>
            @elseif ($sub->effectiveStatus() === 'read_only')
                <div class="sb-note" style="color:#991b1b">Data Anda aman dan masih bisa dilihat. Perpanjang paket untuk menambah dan mengubah data, serta mengaktifkan AI dan follow-up otomatis lagi.</div>
            @endif
        </div>
        @if ($sub->addon_ai || $sub->addon_user || $sub->addon_wa)
            <p class="sb-note" style="margin-top:.5rem">Tambahan aktif:
                @foreach ($addons as $key => $addon)
                    @php($qty = $sub->{'addon_'.$key})
                    @if ($qty) {{ $qty }}x {{ $addon['unit_label'] }}; @endif
                @endforeach
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Pemakaian" description="Lead baru dari formulir dan WhatsApp tetap diterima walau melewati batas. Anda hanya mendapat peringatan.">
        <div class="sb-grid two">
            @foreach ($rows as $row)
                <div class="sb-row">
                    <div class="top"><span>{{ $row['label'] }}</span><b>{{ number_format($row['usage'], 0, ',', '.') }} / {{ $limitText($row['limit']) }}</b></div>
                    @if ($row['percent'] !== null)
                        <div class="sb-bar"><span class="{{ $row['percent'] >= 100 ? 'full' : ($row['percent'] >= 80 ? 'warn' : '') }}" style="width: {{ $row['percent'] }}%"></span></div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @if ($pending)
        <x-filament::section heading="Permintaan menunggu pembayaran" icon="heroicon-o-clock">
            <p style="font-size:.9rem;line-height:1.7">
                Paket <b>{{ $plans[$pending->plan]['name'] }}</b>, {{ $pending->billing_cycle === 'yearly' ? 'tahunan' : 'bulanan' }}. Total <b>{{ $rp($pending->amount) }}</b>.
                Transfer sesuai nominal, lalu kami aktifkan setelah pembayaran terkonfirmasi.
            </p>
            @if ($bankInfo)
                <pre style="margin-top:.5rem;padding:.75rem 1rem;border-radius:.6rem;background:var(--gray-50);white-space:pre-wrap;font-family:inherit;font-size:.9rem">{{ $bankInfo }}</pre>
            @else
                <p class="sb-note" style="margin-top:.5rem">Nomor rekening belum diisi oleh pengelola. Hubungi pengelola untuk instruksi pembayaran.</p>
            @endif
            @if ($contact)<p class="sb-note" style="margin-top:.5rem">Konfirmasi pembayaran: {{ $contact }}</p>@endif
            <div style="margin-top:.75rem"><x-filament::button color="gray" size="sm" wire:click="cancelRequest">Batalkan permintaan</x-filament::button></div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Pilih paket" description="Harga tetap per tim, bukan per pengguna. Tahunan = bayar 10 bulan.">
        <div class="sb-seg" role="group" aria-label="Siklus tagihan" style="margin-bottom:1rem">
            <button type="button" wire:click="$set('cycle','monthly')" aria-pressed="{{ $cycle === 'monthly' ? 'true' : 'false' }}">Bulanan</button>
            <button type="button" wire:click="$set('cycle','yearly')" aria-pressed="{{ $cycle === 'yearly' ? 'true' : 'false' }}">Tahunan (hemat 2 bulan)</button>
        </div>
        <div class="sb-grid three">
            @foreach ($plans as $key => $plan)
                @php($isNow = $sub->status !== 'trial' && $sub->plan === $key)
                <div class="sb-card {{ $plan['popular'] ? 'pop' : '' }} {{ $isNow ? 'now' : '' }}">
                    @if ($plan['popular'])<span class="sb-tag">Paling populer</span>@endif
                    <div style="font-weight:800;font-size:1.1rem">{{ $plan['name'] }}</div>
                    <div class="sb-note">{{ $plan['tagline'] }}</div>
                    <div class="sb-price">{{ $rp($plan['price'][$cycle]) }}<small> / {{ $cycle === 'yearly' ? 'tahun' : 'bulan' }}</small></div>
                    <ul class="sb-list">
                        @foreach ($plan['limits'] as $lk => $lv)
                            <li>&#10003; {{ $limitLabels[$lk] }}: <b>{{ $limitText($lv) }}</b></li>
                        @endforeach
                        @foreach ($plan['features'] as $fk => $fv)
                            <li class="{{ $fv ? '' : 'no' }}">{{ $fv ? '✓' : '✕' }} {{ $featureLabels[$fk] }}</li>
                        @endforeach
                    </ul>
                    <div style="margin-top:auto;padding-top:.5rem">
                        @if ($isNow)
                            <x-filament::button disabled color="gray" style="width:100%">Paket Anda sekarang</x-filament::button>
                        @else
                            <x-filament::button style="width:100%" wire:click="choose('{{ $key }}')">Pilih paket ini</x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Cara berlangganan" collapsible collapsed>
        <ol style="list-style:decimal;padding-left:1.25rem;line-height:1.8;font-size:.9rem">
            <li>Pilih bulanan atau tahunan, lalu klik <b>Pilih paket ini</b> pada paket yang cocok.</li>
            <li>Transfer sesuai nominal ke rekening yang tampil di halaman ini.</li>
            <li>Setelah pembayaran terkonfirmasi, pengelola mengaktifkan paket Anda. Halaman ini otomatis menunjukkan paket dan tanggal berakhir yang baru.</li>
            <li>Bila paket habis, ada masa tenggang {{ config('plans.grace_days') }} hari. Setelah itu akun menjadi hanya-baca: data aman dan bisa dilihat, tetapi tidak bisa ditambah atau diubah.</li>
        </ol>
        <p class="sb-note" style="margin-top:.5rem">Turun ke paket lebih kecil tidak menghapus data. Hanya penambahan baru yang dibatasi mengikuti paket.</p>
    </x-filament::section>

    <x-filament::section heading="Tambahan (per bulan)" description="Butuh lebih dari batas paket? Hubungi pengelola untuk menambahkan.">
        <ul class="sb-list">
            @foreach ($addons as $addon)
                <li>{{ $addon['unit_label'] }}: <b>{{ $rp($addon['price']) }}</b> / bulan</li>
            @endforeach
        </ul>
        <p class="sb-note" style="margin-top:.5rem">Biaya template WhatsApp resmi ditagih langsung oleh Meta ke akun Anda dan bukan bagian dari paket.</p>
    </x-filament::section>
</x-filament-panels::page>
