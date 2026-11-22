@php
    $paid = $payment->status === \App\Models\DigitalPayment::PAID;
    $open = $payment->isOpen();
    $total = \App\Shipping\ShippingQuote::rupiah((int) $payment->amount);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $paid ? 'Pembayaran diterima' : 'Pembayaran' }} - {{ $tenant->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; line-height: 1.55; }
        .shell { max-width: 30rem; margin: 0 auto; padding: 1.5rem 1rem; }
        .card { background: #fff; border-radius: 1rem; padding: 1.5rem 1.25rem; box-shadow: 0 10px 30px rgba(17, 24, 39, .08); }
        h1 { font-size: 1.35rem; margin: 0 0 .5rem; }
        p { margin: 0 0 .9rem; color: #374151; }
        .total { font-size: 1.5rem; font-weight: 700; margin: .25rem 0 1rem; }
        .btn { display: block; text-align: center; padding: .85rem 1rem; min-height: 44px; border-radius: .65rem; background: #16a34a; color: #fff; font-weight: 600; text-decoration: none; }
        .muted { font-size: .875rem; color: #6b7280; }
    </style>
    @include('public.partials.tracking')
    @if ($paid && $purchaseEventId && $tracking?->meta_pixel_id)
        <script>
            try { fbq('track', 'Purchase', { value: {{ (int) $payment->amount }}, currency: 'IDR' }, { eventID: @json($purchaseEventId) }); } catch (e) {}
        </script>
    @endif
</head>
<body>
<main class="shell">
    <div class="card">
        @if ($paid)
            <h1>Pembayaran diterima 🎉</h1>
            <p>Terima kasih{{ $lead?->name ? ', '.$lead->name : '' }}. Pembayaran {{ $lead?->property?->name ?? 'produk' }} sebesar <b>{{ $total }}</b> sudah kami terima.</p>
            <p>Tautan akses dikirim ke WhatsApp{{ filled($lead?->email) ? ' dan email' : '' }} Anda.</p>
        @elseif ($open)
            <h1>Selesaikan pembayaran</h1>
            <p>{{ $lead?->property?->name ?? 'Pembelian' }} dari {{ $tenant->name }}</p>
            <div class="total">{{ $total }}</div>
            <a class="btn" href="{{ $payment->payment_url }}" rel="noopener">Bayar dengan QRIS atau virtual account</a>
            <p class="muted" style="margin-top:.9rem">Berlaku sampai {{ \App\Payments\PaymentInstructions::when($payment->expires_at, $tenant) }}. Halaman ini diperbarui setelah pembayaran diterima; bila belum, muat ulang beberapa saat lagi.</p>
        @else
            <h1>Tautan bayar tidak berlaku lagi</h1>
            <p>Tautan bayar ini sudah kedaluwarsa atau dibatalkan. Balas chat WhatsApp {{ $tenant->name }} untuk meminta tautan baru.</p>
        @endif
    </div>
</main>
</body>
</html>
