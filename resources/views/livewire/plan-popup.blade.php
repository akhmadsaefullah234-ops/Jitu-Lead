<div>
@if ($show)
    @php
        $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
        $title = match ($state) {
            'trial_ended' => 'Masa percobaan 14 hari Anda sudah berakhir',
            'read_only' => 'Langganan Anda berakhir',
            'past_due' => 'Langganan Anda jatuh tempo',
            default => 'Masa langganan Anda segera berakhir',
        };
        $text = match ($state) {
            'trial_ended' => 'Data Anda aman dan masih bisa dilihat. Pilih paket supaya CRM bisa dipakai lagi: menambah dan mengubah data, AI, dan follow-up otomatis.',
            'read_only' => 'Data Anda aman dan masih bisa dilihat. Pilih paket untuk memakai CRM lagi.',
            'past_due' => 'Perpanjang sebelum masa tenggang berakhir supaya akun tidak menjadi hanya-baca.',
            default => 'Sisa '.$sub->daysLeft().' hari. Pilih paket sekarang supaya tidak terputus.',
        };
        $blocking = in_array($state, ['trial_ended', 'read_only'], true);
    @endphp
    <div style="position:fixed;inset:0;z-index:60;background:rgba(17,24,39,.6);display:grid;place-items:center;padding:1rem;overflow:auto" role="dialog" aria-modal="true" aria-label="Pilih paket">
        <div style="background:#fff;border-radius:1.1rem;max-width:56rem;width:100%;padding:1.4rem;box-shadow:0 25px 60px rgba(0,0,0,.35);max-height:94vh;overflow:auto">
            <h2 style="margin:0 0 .25rem;font-size:1.35rem;font-weight:800">{{ $title }}</h2>
            <p style="margin:0 0 1rem;color:#4b5563">{{ $text }}</p>

            @if ($request)
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:.8rem;padding:1rem;color:#166534">
                    <strong>Permintaan paket {{ $plans[$request->plan]['name'] ?? $request->plan }} sudah dibuat.</strong>
                    <p style="margin:.4rem 0 0">Transfer {{ $rp($request->amount) }} ({{ $request->billing_cycle === 'yearly' ? 'per tahun' : 'per bulan' }}).
                        Paket aktif otomatis setelah kami mengonfirmasi pembayaran.</p>
                    @if ($bankInfo)<pre style="white-space:pre-wrap;font:inherit;margin:.6rem 0 0;background:#fff;border-radius:.5rem;padding:.6rem">{{ $bankInfo }}</pre>@endif
                    @if ($contact)<p style="margin:.5rem 0 0">Konfirmasi pembayaran: {{ $contact }}</p>@endif
                </div>
                <div style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap">
                    <button type="button" wire:click="snooze" style="padding:.6rem 1.1rem;border-radius:.7rem;border:1px solid #d1d5db;font-weight:600">Tutup</button>
                    <a href="{{ $pageUrl }}" style="padding:.6rem 1.1rem;border-radius:.7rem;background:#dc2626;color:#fff;font-weight:700;text-decoration:none">Buka halaman Langganan</a>
                </div>
            @elseif ($admin)
                <div style="display:flex;justify-content:center;margin-bottom:1rem">
                    <div style="display:inline-flex;border:1px solid #d1d5db;border-radius:9999px;overflow:hidden">
                        <button type="button" wire:click="$set('cycle','monthly')" style="padding:.35rem 1rem;font-weight:600;{{ $cycle === 'monthly' ? 'background:#dc2626;color:#fff' : '' }}">Bulanan</button>
                        <button type="button" wire:click="$set('cycle','yearly')" style="padding:.35rem 1rem;font-weight:600;{{ $cycle === 'yearly' ? 'background:#dc2626;color:#fff' : '' }}">Tahunan (hemat 2 bulan)</button>
                    </div>
                </div>
                <div style="display:grid;gap:.8rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr))">
                    @foreach ($plans as $key => $plan)
                        <div style="border:{{ $plan['popular'] ? '2px solid #dc2626' : '1px solid #e5e7eb' }};border-radius:.9rem;padding:1rem;display:flex;flex-direction:column;gap:.35rem;position:relative">
                            @if ($plan['popular'])<span style="position:absolute;top:-.7rem;right:.8rem;background:#dc2626;color:#fff;font-size:.72rem;font-weight:700;padding:.1rem .6rem;border-radius:9999px">Paling populer</span>@endif
                            <div style="font-weight:800;font-size:1.05rem">{{ $plan['name'] }}</div>
                            <div style="color:#6b7280;font-size:.85rem">{{ $plan['tagline'] }}</div>
                            <div style="font-size:1.5rem;font-weight:800">{{ $rp($plan['price'][$cycle]) }}<small style="font-size:.8rem;font-weight:500;color:#6b7280"> / {{ $cycle === 'yearly' ? 'tahun' : 'bulan' }}</small></div>
                            <ul style="list-style:none;padding:0;margin:0;font-size:.85rem;color:#374151;display:grid;gap:.15rem">
                                @foreach ($plan['limits'] as $lk => $lv)<li>&#10003; {{ config('plans.limit_labels.'.$lk) }}: <b>{{ $lv === null ? 'tanpa batas' : number_format($lv, 0, ',', '.') }}</b></li>@endforeach
                            </ul>
                            <button type="button" wire:click="choose('{{ $key }}')" style="margin-top:auto;padding:.65rem;border-radius:.7rem;background:#dc2626;color:#fff;font-weight:700">Pilih {{ $plan['name'] }}</button>
                        </div>
                    @endforeach
                </div>
                <div style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
                    <button type="button" wire:click="snooze" style="padding:.55rem 1.1rem;border-radius:.7rem;border:1px solid #d1d5db;font-weight:600">{{ $blocking ? 'Lihat data saja' : 'Nanti' }}</button>
                    <span style="font-size:.8rem;color:#6b7280">Pembayaran lewat transfer bank. Paket aktif setelah kami mengonfirmasi.</span>
                </div>
            @else
                <div style="background:#fef3c7;border-radius:.8rem;padding:1rem;color:#92400e">
                    Hubungi admin agensi Anda @if ($admins)({{ implode(', ', $admins) }})@endif untuk memilih paket.
                </div>
                <div style="margin-top:1rem"><button type="button" wire:click="snooze" style="padding:.55rem 1.1rem;border-radius:.7rem;border:1px solid #d1d5db;font-weight:600">{{ $blocking ? 'Lihat data saja' : 'Tutup' }}</button></div>
            @endif
        </div>
    </div>
@endif
</div>
