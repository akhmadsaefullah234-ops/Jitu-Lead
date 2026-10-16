<x-filament-panels::page>
    <div style="color:#6b7280;max-width:52rem;display:grid;gap:.4rem">
        <p>Email ini dikirim otomatis setiap hari pukul 09.00 WIB dari alamat <strong>{{ \App\Retention\TrialEmailSettings::fromAddress() }}</strong>, hanya ke admin agensi yang emailnya sudah terverifikasi, sekali per agensi. Pengiriman berhenti sendiri saat agensi membayar atau admin menekan "Berhenti menerima email ini".</p>
        <p>Paling banyak {{ (int) config('trial_emails.daily_limit') }} email per hari (TRIAL_EMAILS_DAILY_LIMIT); sisanya dikirim keesokan harinya.</p>
        <p>Kata yang bisa dipakai di subjek dan isi: {{ implode(' ', \App\Retention\TrialEmailSettings::PLACEHOLDERS) }}</p>
    </div>
    <form wire:submit="save">{{ $this->form }}</form>
</x-filament-panels::page>
