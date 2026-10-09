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

## Kebijakan privasi dan syarat layanan

Halaman publik `/kebijakan-privasi` dan `/syarat-layanan` sudah ada dan ditautkan di footer halaman depan, halaman daftar dan masuk, serta landing page agensi. Isinya disusun dari cara kerja aplikasi menurut UU No. 27 Tahun 2022 (data yang disimpan, tujuan, pihak penerima termasuk penyedia AI di luar negeri, lama simpan, hak subjek data, peran pengendali dan prosesor).

**Teks ini rancangan dan wajib ditinjau ahli hukum sebelum rilis.** Selama belum ditandai ditinjau, halaman menampilkan pita kuning "Rancangan, belum ditinjau ahli hukum".

- Isi `.env`: `LEGAL_COMPANY`, `LEGAL_CONTACT_EMAIL` (bawaan: `SUPPORT_EMAIL`), `LEGAL_ADDRESS`, lalu `php artisan config:cache`.
- Ubah teks di **/admin → Halaman hukum** (penyunting teks; penanda seperti `{perusahaan}` diganti otomatis). Nyalakan "Sudah ditinjau ahli hukum" setelah selesai. Teks bawaan ada di `config/legal.php`; tombol "Kembalikan ke teks bawaan" menghapus ubahan.
- Angka lama simpan cadangan di teks mengikuti `BACKUP_KEEP_DAYS` dan `BACKUP_REMOTE_KEEP_DAYS`.

## Hak atas data (ekspor dan hapus agensi)

Admin agensi punya menu **Pengaturan → Data & privasi** (tetap bisa dibuka saat masa percobaan atau langganan berakhir):

- **Unduh semua data (ZIP)**: lead, aktivitas, percakapan dan pesan WhatsApp, properti, landing page, aturan follow-up, pengetahuan dan draf AI, anggota, langganan, dan chat support, sebagai CSV (UTF-8, aman dibuka di Excel). Kata sandi, kunci integrasi, dan token formulir tidak ikut. Dibatasi 3 kali per jam per agensi.
- **Hapus agensi**: admin mengetik alamat ruang kerja dan kata sandinya; tidak perlu persetujuan super admin. Seluruh data agensi dihapus seketika, termasuk gambar landing page. Akun anggota yang hanya terdaftar di agensi itu ikut dihapus; akun admin yang menghapus dan akun super admin tetap ada. Salinan di cadangan hilang sendiri setelah masa simpannya (7 hari lokal, 30 hari di luar server). Bila ada permintaan penghapusan yang mendesak, hapus juga folder cadangan terkait secara manual.

## Backup harian dan restore

Scheduler menjalankan `php artisan backup:run` tiap hari **02:00 WIB** (cron dari `install.sh` sudah menjalankan scheduler). Satu backup = satu folder bertanggal di `storage/app/backups/` (izin 700, file 600) berisi:

- `database.sql.gz`: dump PostgreSQL
- `storage.tar.gz`: seluruh `storage/app` (unggahan: gambar landing page, dokumen pengetahuan AI)
- `gateway.tar.gz`: sesi WhatsApp di `/opt/jitu-gateway/data`, bila ada

Folder lokal lebih dari 7 hari dihapus (`BACKUP_KEEP_DAYS`). Hasil tiap langkah dan status terakhir tampil di **/admin** (dasbor dan menu Backup). Bila gagal, email dikirim ke `SUPPORT_EMAIL` (butuh `MAIL_*` benar). Tanpa salinan di luar server, backup berstatus "peringatan": cadangan yang hanya ada di server ikut hilang bila server hilang.

**Salinan di luar server (rclone).** Skrip `install.sh` memasang rclone. Atur remote lewat `.env` tanpa file konfigurasi rclone, contoh Backblaze B2 / S3-compatible (nama remote `OFFSITE`; ganti sesuai penyedia):

```
BACKUP_RCLONE_REMOTE=offsite:nama-bucket/jitu-lead
RCLONE_CONFIG_OFFSITE_TYPE=s3
RCLONE_CONFIG_OFFSITE_PROVIDER=Other
RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=...
RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_OFFSITE_ENDPOINT=https://s3.us-west-004.backblazeb2.com
```

Google Drive memakai `RCLONE_CONFIG_OFFSITE_TYPE=drive` dan token OAuth dari `rclone config` di komputer Anda (lihat dokumentasi rclone). Lalu `php artisan config:cache`. Pakai folder/bucket khusus: salinan lebih dari 30 hari (`BACKUP_REMOTE_KEEP_DAYS`) di folder itu dihapus otomatis. Kunci jangan diberi izin hapus-semua bila penyedia mendukung izin terbatas.

**Sesi WhatsApp gateway.** Folder itu milik pengguna `jitu-gw`. Beri aplikasi hak baca agar ikut tercadang (tanpa ini langkahnya berstatus peringatan):

```
sudo setfacl -R -m u:www-data:rX /opt/jitu-gateway/data
sudo setfacl -R -d -m u:www-data:rX /opt/jitu-gateway/data
```

**Coba sekarang:** `sudo -u www-data php artisan backup:run` (cek hasilnya di /admin → Backup).

### Memulihkan (restore)

Uji prosedur ini sekali di staging sebelum Anda membutuhkannya. Data yang masuk setelah backup akan hilang.

1. Ambil backup: dari `storage/app/backups/TANGGAL/`, atau dari penyimpanan luar: `rclone copy offsite:nama-bucket/jitu-lead/TANGGAL /tmp/restore` (butuh variabel `RCLONE_CONFIG_*` di shell).
2. Hentikan aplikasi: `cd /var/www/jitu-lead && sudo -u www-data php artisan down`, lalu `sudo systemctl stop jitu-queue`.
3. Database (menggantikan isi sekarang):
   ```
   sudo -u postgres psql jitu_lead -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO jitu;'
   gunzip -c /tmp/restore/database.sql.gz | sudo -u postgres psql -v ON_ERROR_STOP=1 jitu_lead
   ```
4. Unggahan: `sudo -u www-data tar -xzf /tmp/restore/storage.tar.gz -C /var/www/jitu-lead/storage/app` lalu `sudo -u www-data php artisan storage:link` bila perlu.
5. WhatsApp (jika ada): `sudo systemctl stop jitu-gateway && sudo tar -xzf /tmp/restore/gateway.tar.gz -C /opt/jitu-gateway/data && sudo chown -R jitu-gw:jitu-gw /opt/jitu-gateway/data && sudo systemctl start jitu-gateway`.
6. Nyalakan lagi: `sudo -u www-data php artisan migrate --force` (bila kode lebih baru dari backup), `sudo systemctl start jitu-queue`, `sudo -u www-data php artisan up`.
7. Hapus `/tmp/restore` (berisi data pelanggan).

Langkah database sudah dicoba di mesin pengembangan (dump lalu pulihkan ke database kosong); prosedur lengkap di VPS belum.

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
