<x-filament-panels::page>
    <style>
        .ir-table { border-collapse: collapse; width: 100%; font-size: .85rem; }
        .ir-table th, .ir-table td { border-bottom: 1px solid var(--gray-200); padding: .4rem .5rem; text-align: left; vertical-align: top; }
        .ir-badge { display: inline-block; padding: .05rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .ir-cocok { background: #dcfce7; color: #166534; } .ir-ambigu { background: #fef9c3; color: #854d0e; }
        .ir-tidak_cocok { background: #ffe4e6; color: #9f1239; } .ir-sudah_terhubung, .ir-tidak_valid { background: var(--gray-100); color: var(--gray-600); }
        .ir-select { width: 100%; min-width: 12rem; border: 1px solid var(--gray-300); border-radius: .4rem; padding: .25rem .4rem; background: transparent; }
        .ir-cols { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
        @media (max-width: 639px) { .ir-table { font-size: .75rem; } .ir-hide-sm { display: none; } }
    </style>

    <x-filament::section heading="Cara impor">
        <ol style="list-style: decimal; padding-left: 1.25rem; line-height: 1.7">
            <li>Ekspor daftar pesanan dari aplikasi atau dashboard penyedia (CSV atau XLSX) yang berisi nomor resi, nama, dan HP penerima.</li>
            <li>Klik <b>Unggah file ekspor</b>. Kolom ditebak dari judulnya; ubah di bawah bila salah.</li>
            <li>Periksa pencocokan. Baris <b>Cocok</b> sudah dipilih; baris lain pilih lead-nya atau biarkan "Lewati".</li>
            <li>Klik <b>Simpan yang dipilih</b>. Resi ikut dilacak; pembeli hanya mendapat pesan untuk perubahan status setelah ini.</li>
        </ol>
    </x-filament::section>

    @if ($cells !== [])
        <x-filament::section :heading="'Kolom di '.$fileName">
            <div class="ir-cols">
                @foreach (['awb' => 'Nomor resi', 'name' => 'Nama penerima', 'phone' => 'HP penerima', 'courier' => 'Kurir (boleh kosong, bawaan JNE)'] as $field => $label)
                    <label style="display:grid;gap:.25rem;font-size:.85rem">
                        <span>{{ $label }}</span>
                        <select class="ir-select" wire:model.live="columns.{{ $field }}">
                            <option value="">-</option>
                            @foreach ($header as $i => $h)
                                <option value="{{ $i }}">{{ $h !== '' ? $h : 'Kolom '.($i + 1) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </x-filament::section>

        @if ($matches !== [])
            <x-filament::section heading="Pratinjau pencocokan">
                <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:.75rem;font-size:.85rem">
                    <label><input type="checkbox" wire:model="notify"> Kirim nomor resi ke pembeli sekarang</label>
                    <label><input type="checkbox" wire:model="closeDelivered"> Paket yang sudah diterima: pindahkan lead ke Selesai (tanpa pesan)</label>
                </div>
                <div style="overflow-x:auto">
                    <table class="ir-table">
                        <thead><tr><th>Baris</th><th>Resi</th><th class="ir-hide-sm">Penerima</th><th>Status</th><th>Lead</th></tr></thead>
                        <tbody>
                            @foreach ($matches as $i => $m)
                                <tr wire:key="ir-{{ $i }}">
                                    <td>{{ $m['row'] }}</td>
                                    <td style="font-family:monospace">{{ $m['awb'] }}</td>
                                    <td class="ir-hide-sm">{{ $m['name'] }}<br><span style="color:var(--gray-500)">{{ $m['phone'] }}</span></td>
                                    <td><span class="ir-badge ir-{{ $m['status'] }}">{{ $this->statusLabel($m['status']) }}</span><div style="color:var(--gray-500);font-size:.75rem">{{ $m['note'] }}</div></td>
                                    <td>
                                        @if (in_array($m['status'], ['tidak_valid', 'sudah_terhubung'], true))
                                            <span style="color:var(--gray-500)">Dilewati</span>
                                        @else
                                            <select class="ir-select" wire:model="choices.{{ $i }}">
                                                <option value="">Lewati</option>
                                                @foreach ($m['options'] as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:1rem">
                    <x-filament::button wire:click="save" icon="heroicon-o-link">Simpan yang dipilih</x-filament::button>
                </div>
                @if ($outcome !== [])
                    <ul style="margin-top:.75rem;color:#9f1239;font-size:.85rem">
                        @foreach ($outcome as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
