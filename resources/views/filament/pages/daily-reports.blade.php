<x-filament-panels::page>
    <style>
        .dr-box { padding: 1rem; border: 1px solid var(--gray-200); border-radius: .75rem; background: #fff; }
        .dr-row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: .5rem; }
        .dr-row input { flex: 1 1 12rem; min-width: 0; max-width: 18rem; padding: .45rem .7rem; border: 1px solid var(--gray-300); border-radius: .5rem; }
        .dr-badge { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .dr-ok { background: #dcfce7; color: #166534; } .dr-warn { background: #fef3c7; color: #92400e; } .dr-bad { background: #ffe4e6; color: #9f1239; }
        .dr-list { list-style: none; margin: 0; padding: 0; font-size: .875rem; }
        .dr-list li { padding: .6rem 0; border-bottom: 1px solid var(--gray-100); display: grid; gap: .2rem; }
        .dr-list li:last-child { border-bottom: 0; }
        .dr-muted { color: var(--gray-500); font-size: .8rem; }
    </style>

    <p style="color:var(--gray-600);max-width:46rem">
        Ringkasan lead, penjualan, AI dan hal yang perlu perhatian dikirim ke WhatsApp Anda setiap pagi dari nomor resmi JITU LEAD
        (bukan nomor agensi Anda). Angkanya sama dengan Dasbor periode "Kemarin" dan tidak memuat nama atau nomor pembeli.
        Bila WhatsApp tidak bisa dipakai, laporan dikirim ke email Anda.
    </p>

    {{ $this->mineForm }}

    <div class="dr-box">
        <strong>Nomor WhatsApp penerima</strong>
        @if ($this->verified())
            <span class="dr-badge dr-ok">Terverifikasi</span>
        @else
            <span class="dr-badge dr-warn">Belum terverifikasi</span>
        @endif
        <div class="dr-muted">Diambil dari profil Anda. Nomor baru harus dikonfirmasi dengan kode 6 digit yang kami kirim lewat WhatsApp.</div>

        <div class="dr-row">
            <input type="tel" wire:model="number" placeholder="0812 3456 7890" aria-label="Nomor WhatsApp">
            <x-filament::button color="gray" wire:click="sendCode" wire:loading.attr="disabled">Kirim kode</x-filament::button>
        </div>

        @if ($this->codePending())
            <div class="dr-row">
                <input type="text" inputmode="numeric" maxlength="6" wire:model="code" placeholder="6 digit kode" aria-label="Kode verifikasi" autocomplete="one-time-code">
                <x-filament::button wire:click="verifyCode" wire:loading.attr="disabled">Verifikasi</x-filament::button>
            </div>
        @endif
    </div>

    @if ($this->isAdmin())
        {{ $this->agencyForm }}
    @endif

    <x-filament::section heading="Riwayat 14 laporan terakhir" compact>
        @php($history = $this->history())
        @if ($history->isEmpty())
            <p class="dr-muted">Belum ada laporan terkirim. Tekan "Kirim contoh sekarang" untuk mencoba.</p>
        @else
            <ul class="dr-list">
                @foreach ($history as $row)
                    <li>
                        <div>
                            <span @class(['dr-badge', 'dr-ok' => $row->status === 'sent', 'dr-warn' => in_array($row->status, ['email', 'pending', 'sending']), 'dr-bad' => $row->status === 'failed'])>
                                {{ \App\Models\ReportDelivery::STATUSES[$row->status] ?? $row->status }}
                            </span>
                            <strong>{{ \App\Models\ReportDelivery::KINDS[$row->kind] ?? $row->kind }}</strong> · {{ $row->period_label ?? '—' }}
                        </div>
                        <div class="dr-muted">
                            {{ ($row->sent_at ?? $row->due_at)->timezone($this->tenant()->timezone ?: 'Asia/Jakarta')->format('d M Y H:i') }}@if ($this->isAdmin()) · {{ $row->user?->name }}@endif
                        </div>
                        @if ($row->error)<div class="dr-muted">{{ $row->error }}</div>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
