# Memasang di VPS

Syarat: Ubuntu 22.04/24.04 (atau Debian 12), RAM minimal 2 GB, akses root, dan domain yang record A-nya sudah mengarah ke IP VPS.

```bash
curl -fsSLO https://raw.githubusercontent.com/akhmadsaefullah234-ops/Jitu-Lead/main/deploy/install.sh
sudo bash install.sh app.contoh.com email@contoh.com
```

Skrip memasang PHP 8.3, PostgreSQL, Nginx, HTTPS, aplikasi, queue worker, dan scheduler. Aman dijalankan ulang untuk memperbarui kode (migrasi ikut dijalankan). Password database dibuat acak dan hanya ada di `.env` server.

Setelah selesai, buka `https://app.contoh.com/app` dan daftar akun admin pertama.

Memperbarui ke versi terbaru: pakai `deploy/update.sh` (lebih aman, lihat bagian "Update aman dan staging"). Menjalankan ulang `install.sh` juga masih bisa.

## Update aman dan staging (preview)

Tujuannya: versi baru dicoba dulu di salinan terpisah, lalu dipasang ke produksi dengan cadangan dan jaring pengaman, sehingga pengguna tidak terganggu. Skrip ini belum pernah dijalankan di server sungguhan; jalankan pertama kali saat sepi.

**1. Coba di staging** (alamat terpisah, mis. `staging.contoh.com`, DNS A-nya harus sudah mengarah ke VPS):

```bash
sudo bash deploy/staging.sh staging.contoh.com email@contoh.com nama-cabang
# atau dengan salinan data produksi (wajib kata sandi akses):
sudo bash deploy/staging.sh staging.contoh.com email@contoh.com nama-cabang --copy-data --basic-auth tim:sandi-panjang-ya
```

- Folder, database, dan cookie login sendiri; produksi tidak disentuh. Ada pita kuning "LINGKUNGAN UJI COBA" di panel, dan mesin pencari diminta tidak mengindeks.
- Tidak ada yang terkirim dari staging: email hanya ke log, tanpa queue worker/scheduler, kunci AI kosong.
- Tanpa `--copy-data` database kosong (daftar akun baru, pakai "Isi data contoh"). Dengan `--copy-data`, data produksi disalin lalu dimigrasi dengan kode baru, jadi terlihat bagaimana update berjalan pada data asli. Kredensial WhatsApp/pixel serta antrean dibuang dari salinan. Isinya data pelanggan asli: wajib `--basic-auth`, dan hapus staging bila tak dipakai lagi.
- Dijalankan ulang: kode diperbarui dan dimigrasi, data staging dipertahankan (kecuali `--copy-data` lagi).

**2. Pasang ke produksi:**

```bash
sudo bash deploy/update.sh nama-cabang      # atau tanpa nama = main
```

Urutannya: tampilkan versi lama vs baru dan peringatan bila belum dicoba di staging → cadangkan database ke `/var/backups/jitu-lead` (14 terakhir disimpan) → halaman "Sedang diperbarui" untuk pengunjung (memuat ulang sendiri) → ambil kode, composer, migrasi → cek `/` dan `/app/login` lewat tautan rahasia → buka kembali, queue worker dimulai ulang setelah pekerjaannya selesai. Pemilik tetap bisa melihat situs saat pemeliharaan lewat tautan rahasia yang dicetak `artisan down`.

**Jika gagal:** kode otomatis dikembalikan ke versi sebelumnya dan situs dibuka lagi. Migrasi berjalan dalam transaksi di PostgreSQL, jadi migrasi yang gagal tidak setengah jadi. Migrasi yang sudah selesai tidak dibatalkan otomatis; karena migrasi proyek ini hanya menambah (tidak mengubah yang lama), kode lama biasanya tetap jalan di skema baru. Bila benar-benar harus kembali ke data sebelum update, pulihkan cadangan (data yang masuk setelah cadangan akan hilang):

```bash
sudo -u www-data php artisan down
sudo -u postgres psql jitu_lead -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO jitu;'
gunzip -c /var/backups/jitu-lead/db-TANGGAL-XXXX.sql.gz | sudo -u postgres psql jitu_lead
sudo -u www-data php artisan up
```

Opsi: `-y` melewati pertanyaan konfirmasi.

## Mengaktifkan AI Asisten

AI Asisten membutuhkan kunci API Anthropic. Tambahkan di `/var/www/jitu-lead/.env` (sesuaikan folder instalasi), lalu muat ulang konfigurasi dan queue worker:

```
ANTHROPIC_API_KEY=sk-ant-...
# opsional, bawaan: claude-haiku-5-5
ANTHROPIC_MODEL=claude-haiku-5-5
# opsional, bawaan: thinking mati dan effort low supaya jawaban pendek tidak terpotong.
# Kosongkan salah satu bila model yang Anda pakai menolaknya.
ANTHROPIC_THINKING=disabled
ANTHROPIC_EFFORT=low
```

```
sudo -u www-data php artisan config:cache && sudo systemctl restart jitu-queue
```

Tanpa kunci, menu AI tetap bisa diisi tetapi tidak ada balasan yang dibuat. Biaya pemakaian AI ditagihkan ke akun Anthropic pemilik kunci.

## Pendaftaran dan uji coba 14 hari

Bawaan: pendaftaran **terbuka**. Siapa pun yang mendaftar langsung masuk uji coba 14 hari (batas paket Tim). Saat uji coba habis, akun menjadi hanya-baca dan muncul popup untuk memilih paket (Mandiri/Tim/Agensi). Data tidak hilang.

Atur di `.env` lalu jalankan `php artisan config:cache`:

```
REGISTRATION_MODE=open     # bawaan: daftar bebas, langsung uji coba 14 hari
# REGISTRATION_MODE=invite # wajib kode undangan
# REGISTRATION_MODE=closed # halaman daftar ditiadakan
TRIAL_GRACE_DAYS=0         # hari tenggang setelah uji coba (0 = langsung hanya-baca)
```

Kode undangan (hanya dipakai bila `REGISTRATION_MODE=invite`) dibuat oleh pemilik aplikasi di server:

```
cd /var/www/jitu-lead
sudo -u www-data php artisan invite:create --uses=1 --days=14 --note="Pak Budi"
sudo -u www-data php artisan invite:list
```

Verifikasi email butuh `MAIL_*` di `.env` yang benar (SMTP). Akun yang sudah ada tidak terpengaruh.

## WhatsApp lewat scan QR (opsional)

Agar agensi menyambungkan nomor cukup dengan scan QR (tanpa mengisi API key), jalankan gateway yang mengikuti `docs/whatsapp-gateway-contract.md` lalu isi di `.env`:

```
WHATSAPP_GATEWAY_URL=https://gateway.domainanda.com
WHATSAPP_GATEWAY_API_KEY=...
WHATSAPP_GATEWAY_SIGNING_SECRET=...
```

Cara termudah (satu VPS): `sudo bash gateway/install.sh` memasang gateway dan mengisi .env otomatis (lihat `gateway/README.md`). Atau isi manual lalu `php artisan config:cache`. Tanpa isian ini, agensi yang memilih "WhatsApp biasa" harus mengisi alamat dan kunci gateway mereka sendiri di bagian lanjutan.


## Paket langganan (tahap 1, pembayaran manual)

Paket, harga, dan batas didefinisikan di satu tempat: `config/plans.php`. Halaman `/harga`, menu Langganan, dan semua pengecekan batas membaca file itu.

Isi di `.env` lalu `php artisan config:cache`:

```
BILLING_BANK_INFO="Bank BCA 1234567890
a.n. Nama Anda"
BILLING_CONTACT="WhatsApp 0812xxxxxxx"
```

Agensi baru mendapat percobaan 14 hari dengan batas paket Tim Kecil. Agensi yang sudah ada mendapat 14 hari dihitung sejak migrasi `2026_10_17_000100_create_subscriptions` dijalankan.

Alur: admin agensi memilih paket di menu Langganan, itu membuat permintaan (pending) berisi nominal dan instruksi transfer. Setelah Anda menerima pembayaran, aktifkan lewat artisan:

```
php artisan plan:set {slug-atau-id} {mandiri|tim|agensi} [--cycle=monthly|yearly] [--months=N]
php artisan plan:addon {slug-atau-id} {ai|user|wa} [--qty=1]   # qty negatif untuk mengurangi
php artisan plan:list                                          # paket, status, berakhir, pemakaian AI
```

`plan:set` memperpanjang periode yang masih berjalan (bukan mengulang dari hari ini) dan menandai permintaan pending agensi itu sebagai disetujui. Tanpa `--months`, bulanan = 1 bulan dan tahunan = 12 bulan.

Pemeriksaan harian `plan:check` (dijadwalkan 01:00, butuh `schedule:run` di cron seperti `followups:run`):

- masa percobaan atau periode habis, status menjadi `past_due` dan masa tenggang 3 hari dimulai
- tenggang habis, status menjadi `read_only`: data bisa dilihat, tidak bisa ditambah atau diubah, AI dan follow-up otomatis berhenti
- email pengingat ke admin agensi 3 hari sebelum berakhir (perlu pengaturan `MAIL_*`)

Lead baru dari formulir dan WhatsApp tetap diterima walau melebihi batas lead aktif atau status hanya-baca; lead tidak pernah dibuang. Biaya template WhatsApp resmi ditagih Meta langsung ke agensi dan bukan bagian paket. Pembayaran otomatis (Xendit/Midtrans) dan invoice belum ada (tahap 2).

## Landing page builder

Panduan lengkap dan format data ada di [landing-builder.md](landing-builder.md). Untuk server:

- `deploy/install.sh` memasang `php8.3-gd` (pengecil gambar, WebP) dan menulis `/etc/php/8.3/fpm/conf.d/90-jitu.ini`
  (`upload_max_filesize=10M`, `post_max_size=12M`, `memory_limit=256M`). Di server yang sudah berjalan, jalankan ulang
  `install.sh`, atau buat file itu sendiri lalu `sudo systemctl restart php8.3-fpm`.
- Setelah `git pull`: `php artisan migrate --force` (menambah kolom halaman dan mengubah format halaman lama sekali),
  lalu `php artisan view:clear`.
