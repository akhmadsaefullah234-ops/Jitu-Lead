<x-filament-panels::page>
    <script>
        window.jituPush = function (publicKey) {
            const toKey = (b64) => {
                const pad = '='.repeat((4 - (b64.length % 4)) % 4);
                const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
                return Uint8Array.from(raw, (c) => c.charCodeAt(0));
            };

            return {
                supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
                secure: window.isSecureContext,
                ios: /iPhone|iPad|iPod/.test(navigator.userAgent),
                standalone: window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
                permission: 'Notification' in window ? Notification.permission : 'denied',
                subscribed: false,
                endpoint: null,
                busy: false,
                error: null,

                async init() {
                    if (!this.supported || !this.secure) return;
                    try {
                        const reg = await navigator.serviceWorker.register('/sw.js');
                        const sub = await reg.pushManager.getSubscription();
                        this.subscribed = !!sub;
                        this.endpoint = sub ? sub.endpoint : null;
                    } catch (e) {
                        this.error = 'Browser ini tidak bisa menjalankan notifikasi.';
                    }
                },

                async subscribe(reg) {
                    const options = { userVisibleOnly: true, applicationServerKey: toKey(publicKey) };
                    try {
                        return await reg.pushManager.subscribe(options);
                    } catch (e) {
                        // Left over from other server keys: drop it and subscribe again.
                        const old = await reg.pushManager.getSubscription();
                        if (old) { await old.unsubscribe(); return await reg.pushManager.subscribe(options); }
                        throw e;
                    }
                },

                async enable() {
                    this.busy = true; this.error = null;
                    try {
                        this.permission = await Notification.requestPermission();
                        if (this.permission !== 'granted') {
                            this.error = 'Izin notifikasi tidak diberikan. Buka pengaturan situs di browser Anda, izinkan Notifikasi, lalu coba lagi.';
                            return;
                        }
                        const reg = await navigator.serviceWorker.ready;
                        const sub = await this.subscribe(reg);
                        const ok = await this.$wire.saveSubscription(sub.toJSON(), navigator.userAgent);
                        if (!ok) { await sub.unsubscribe(); throw new Error('Server menolak perangkat ini.'); }
                        this.subscribed = true; this.endpoint = sub.endpoint;
                    } catch (e) {
                        this.error = 'Gagal mengaktifkan: ' + (e.message || e);
                    } finally { this.busy = false; }
                },

                async disable() {
                    this.busy = true; this.error = null;
                    try {
                        const reg = await navigator.serviceWorker.ready;
                        const sub = await reg.pushManager.getSubscription();
                        if (sub) { await this.$wire.removeSubscription(sub.endpoint); await sub.unsubscribe(); }
                        this.subscribed = false; this.endpoint = null;
                    } catch (e) {
                        this.error = 'Gagal mematikan: ' + (e.message || e);
                    } finally { this.busy = false; }
                },
            };
        };
    </script>

    @unless ($this->transportReady())
        <x-filament::section>
            <p class="text-sm">Notifikasi belum dihidupkan di server ini. Operator perlu menjalankan <code>php artisan push:keys</code> lalu <code>php artisan config:cache</code> (lihat docs/deploy.md).</p>
        </x-filament::section>
    @else
        <div x-data="jituPush(@js($this->publicKey()))" class="grid gap-6 max-w-3xl">
            <x-filament::section heading="Perangkat ini">
                <template x-if="!secure">
                    <p class="text-sm text-danger-600">Notifikasi hanya jalan lewat alamat https. Buka JITU LEAD dari alamat https Anda.</p>
                </template>
                <template x-if="secure && ios && !standalone">
                    <p class="text-sm">Di iPhone/iPad (iOS 16.4 ke atas): buka halaman ini di Safari, ketuk tombol Bagikan, pilih <b>Tambahkan ke Layar Utama</b>, lalu buka JITU LEAD dari ikon di layar utama dan kembali ke halaman ini untuk mengaktifkan.</p>
                </template>
                <template x-if="secure && !supported && !(ios && !standalone)">
                    <p class="text-sm">Browser ini tidak mendukung notifikasi. Pakai Chrome, Edge, Firefox, atau Safari terbaru.</p>
                </template>

                <div x-show="secure && supported && !(ios && !standalone)" class="grid gap-3">
                    <p class="text-sm" x-show="subscribed">Notifikasi <b>aktif</b> di perangkat ini.</p>
                    <p class="text-sm" x-show="!subscribed">Notifikasi belum aktif di perangkat ini.</p>
                    <p class="text-sm text-danger-600" x-show="error" x-text="error"></p>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button x-show="!subscribed" x-on:click="enable()" x-bind:disabled="busy" icon="heroicon-o-bell-alert">Aktifkan di perangkat ini</x-filament::button>
                        <x-filament::button x-show="subscribed" color="gray" x-on:click="disable()" x-bind:disabled="busy">Matikan di perangkat ini</x-filament::button>
                        <x-filament::button x-show="subscribed" color="gray" wire:click="sendTest" icon="heroicon-o-paper-airplane">Kirim notifikasi tes</x-filament::button>
                    </div>
                    <p class="text-xs text-gray-500">Untuk pengalaman seperti aplikasi: di Chrome atau Edge pilih menu Instal JITU LEAD; di HP pilih Tambahkan ke Layar Utama. Notifikasi tetap aktif di perangkat sampai Anda mematikannya, jadi matikan di komputer bersama sebelum keluar.</p>
                </div>
            </x-filament::section>

            <x-filament::section heading="Beri tahu saya tentang">
                <div class="grid gap-3 text-sm">
                    <label class="flex items-start gap-3"><input type="checkbox" wire:model.live="notifyLeads" class="mt-1 rounded"><span><b>Lead baru</b> dari formulir, landing page, dan WhatsApp. Admin menerima semuanya; agen menerima lead miliknya.</span></label>
                    <label class="flex items-start gap-3"><input type="checkbox" wire:model.live="notifyMessages" class="mt-1 rounded"><span><b>Pesan WhatsApp masuk</b> dari lead milik Anda (admin juga menerima pesan dari lead yang belum punya pemilik).</span></label>
                </div>
            </x-filament::section>

            <x-filament::section heading="Perangkat yang terdaftar" description="Semua perangkat tempat Anda mengaktifkan notifikasi.">
                @forelse ($this->devices() as $device)
                    <div class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="device-{{ $device['id'] }}">
                        <div>
                            <span class="font-medium">{{ $device['name'] }}</span>
                            <span class="text-gray-500">sejak {{ $device['since'] }}</span>
                            <span x-show="endpoint === @js($device['endpoint'])" class="ml-1 rounded bg-primary-50 px-2 py-0.5 text-xs text-primary-700">perangkat ini</span>
                        </div>
                        <x-filament::button size="xs" color="gray" wire:click="removeDevice({{ $device['id'] }})" wire:confirm="Hapus perangkat ini dari daftar?">Hapus</x-filament::button>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Belum ada.</p>
                @endforelse
            </x-filament::section>
        </div>
    @endunless
</x-filament-panels::page>
