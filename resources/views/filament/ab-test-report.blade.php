{{-- The A/B test comparison under a landing page's "Uji A/B" tab (App\LandingPages\AbTest::report). --}}
<div style="display:grid;gap:.75rem;font-size:.875rem">
    @if ($url)
        <div>
            <div style="font-weight:600;margin-bottom:.3rem">Alamat uji A/B (pakai di iklan)</div>
            <input readonly value="{{ $url }}" onclick="this.select()" style="width:100%;font:.8rem/1.5 ui-monospace,monospace;padding:.6rem;border:1px solid var(--gray-300);border-radius:.5rem;background:var(--gray-50)">
        </div>
    @endif
    @if ($rows === [])
        <div style="color:var(--gray-600)">Pilih halaman B, aktifkan, lalu simpan. Hasil perbandingan tampil di sini.</div>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;min-width:640px">
                <thead><tr style="text-align:left;border-bottom:1px solid var(--gray-200)">
                    @foreach (['Varian', 'Pengunjung', 'Prospek', 'Tingkat isi form', 'Closing', 'Gagal antar', 'Belanja iklan', 'Biaya per closing'] as $h)<th style="padding:.4rem .5rem">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-bottom:1px solid var(--gray-100)">
                            <td style="padding:.4rem .5rem"><b>{{ $r['variant'] }}</b> · {{ $r['page'] }}</td>
                            <td style="padding:.4rem .5rem">{{ number_format($r['visitors'], 0, ',', '.') }}</td>
                            <td style="padding:.4rem .5rem">{{ number_format($r['leads'], 0, ',', '.') }}</td>
                            <td style="padding:.4rem .5rem">{{ $r['rate'] === null ? '-' : number_format($r['rate'], 1, ',', '.').'%' }}</td>
                            <td style="padding:.4rem .5rem">{{ $r['closings'] }}</td>
                            <td style="padding:.4rem .5rem">{{ $r['failed'] }}</td>
                            <td style="padding:.4rem .5rem">{{ \App\LandingPages\AbTest::money($r['spend']) }}</td>
                            <td style="padding:.4rem .5rem">{{ \App\LandingPages\AbTest::money($r['cost_per_closing']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="color:var(--gray-600)">Pengunjung = kunjungan lewat alamat uji A/B. Closing = lead yang sampai Dikemas &amp; dikirim, Selesai, atau tahap menang. Belanja iklan diisi manual di atas.</div>
    @endif
</div>
