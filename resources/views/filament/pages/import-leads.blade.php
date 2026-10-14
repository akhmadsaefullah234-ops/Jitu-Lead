<x-filament-panels::page>
    <x-filament::section heading="Cara impor">
        <ol style="list-style: decimal; padding-left: 1.25rem; line-height: 1.7">
            <li>Klik <b>Unduh template</b>, lalu buka di Excel atau Google Sheets dan isi datanya di bawah judul kolom. Jangan ubah judul kolomnya.</li>
            <li>Simpan sebagai <b>CSV</b> (maksimal 2.000 baris, 2 MB). Kolom wajib: <b>nama</b>, dan <b>telepon</b> atau <b>email</b>. Kolom <b>catatan</b> boleh kosong.</li>
            <li>Tips: format kolom telepon sebagai <b>Teks</b> agar angka 0 di depan nomor tidak hilang.</li>
            <li>Klik <b>Unggah file CSV</b>. Lead yang nomor atau emailnya sudah ada tidak dibuat dua kali.</li>
            <li>Lead baru masuk ke tahap pertama pipeline dan dibagi rata ke agen, kecuali Anda memilih satu agen.</li>
        </ol>
    </x-filament::section>

    <x-filament::section heading="Contoh isi file">
        <div style="overflow-x: auto">
            <table style="border-collapse: collapse; font-size: .875rem">
                <thead>
                    <tr>@foreach (['nama', 'telepon', 'email', 'catatan'] as $h)<th style="border: 1px solid #d1d5db; padding: .25rem .75rem; text-align: left">{{ $h }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    <tr>@foreach (['Budi Santoso', '081234567890', 'budi@contoh.com', 'Cari rumah 2 kamar'] as $c)<td style="border: 1px solid #d1d5db; padding: .25rem .75rem">{{ $c }}</td>@endforeach</tr>
                    <tr>@foreach (['Siti Aminah', '+6281398765432', '', 'Tanya KPR'] as $c)<td style="border: 1px solid #d1d5db; padding: .25rem .75rem">{{ $c }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
