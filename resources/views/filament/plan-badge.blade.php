@php
    $tenant = \Filament\Facades\Filament::getTenant();
    $sub = $tenant?->currentSubscription();
    $status = $sub?->effectiveStatus();
    $days = $sub?->daysLeft();
    $admin = app(\App\Support\CurrentTenant::class)->roleOf(auth()->user()) === \App\Enums\Role::Admin;
    [$text, $style] = match (true) {
        $sub === null => [null, ''],
        $status === 'trial' => ["Percobaan: $days hari lagi", $days <= 3 ? 'background:#fef3c7;color:#92400e' : 'background:#dcfce7;color:#166534'],
        $status === 'read_only' => [$sub->neverPaid() ? 'Percobaan berakhir' : 'Hanya-baca', 'background:#fee2e2;color:#991b1b'],
        $status === 'past_due' => ['Jatuh tempo', 'background:#fee2e2;color:#991b1b'],
        $days !== null && $days <= 3 => ["Langganan: $days hari lagi", 'background:#fef3c7;color:#92400e'],
        default => [null, ''],
    };
@endphp
@if ($text)
    @if ($admin)
        <a href="{{ \App\Filament\Pages\Subscription::getUrl() }}" style="{{ $style }};font-size:.78rem;font-weight:700;padding:.2rem .7rem;border-radius:9999px;text-decoration:none;white-space:nowrap">{{ $text }}</a>
    @else
        <span style="{{ $style }};font-size:.78rem;font-weight:700;padding:.2rem .7rem;border-radius:9999px;white-space:nowrap">{{ $text }}</span>
    @endif
@endif
