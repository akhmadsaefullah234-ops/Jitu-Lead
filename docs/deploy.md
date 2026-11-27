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

## Pemantauan kesehatan untuk operator (heartbeat)

Isi `OPS_HEARTBEAT_URL` dengan URL heartbeat Better Stack (periode 3 menit, grace 5 menit). Tiap menit server mengirim
ping hanya bila database, antrean, gateway WhatsApp, job gagal, dan ruang disk sehat. Bila ping berhenti, operator
mendapat email. Setelah deploy, jalankan `php artisan ops:check`. Rinciannya ada di [ops-monitoring.md](ops-monitoring.md).

## Kebijakan privasi dan syarat layanan

Halaman publik `/kebijakan-privasi` dan `/syarat-layanan` sudah ada dan ditautkan di footer halaman depan, halaman daftar dan masuk, serta landing page agensi. Isinya disusun dari cara kerja aplikasi menurut UU No. 27 Tahun 2022 (data yang disimpan, tujuan, pihak penerima termasuk penyedia AI di luar negeri, lama simpan, hak subjek data, peran pengendali dan prosesor).

**Teks ini rancangan dan wajib ditinjau ahli hukum sebelum rilis.** Selama belum ditandai ditinjau, halaman menampilkan pita kuning "Rancangan, belum ditinjau ahli hukum".

- Isi `.env`: `LEGAL_COMPANY`, `LEGAL_CONTACT_EMAIL` (bawaan: `SUPPORT_EMAIL`), `LEGAL_ADDRESS`, lalu `php artisan config:cache`.
- Ubah teks di **/admin → Halaman hukum** (penyunting teks; penanda seperti `{perusahaan}` diganti otomatis). Nyalakan "Sudah ditinjau ahli hukum" setelah selesai. Teks bawaan ada di `config/legal.php`; tombol "Kembalikan ke teks bawaan" menghapus ubahan.
- Angka lama simpan cadangan di teks mengikuti `BACKUP_KEEP_DAYS` dan `BACKUP_REMOTE_KEEP_DAYS`.

## Hak atas data (ekspor dan hapus agensi)

Admin agensi punya menu **Profil agensi → Data & privasi (menu akun di kanan atas)** (tetap bisa dibuka saat masa percobaan atau langganan berakhir):

- **Unduh semua data (ZIP)**: lead, aktivitas, percakapan dan pesan WhatsApp, properti, landing page, aturan follow-up, pengetahuan dan draf AI, anggota, langganan, dan chat support, sebagai CSV (UTF-8, aman dibuka di Excel). Kata sandi, kunci integrasi, dan token formulir tidak ikut. Dibatasi 3 kali per jam per agensi.
- **Hapus agensi**: admin mengetik alamat ruang kerja dan kata sandinya; tidak perlu persetujuan super admin. Seluruh data agensi dihapus seketika, termasuk gambar landing page. Akun anggota yang hanya terdaftar di agensi itu ikut dihapus; akun admin yang menghapus dan akun super admin tetap ada. Salinan di cadangan hilang sendiri setelah masa simpannya (dump database 14 hari lokal dan 60 hari di luar server; berkas yang dihapus dari `storage/app` masih 30 hari di `storage-deleted`). Bila ada permintaan penghapusan yang mendesak, hapus juga folder cadangan terkait secara manual.

## Backup dan restore

Ada tiga jadwal (zona waktu WIB, semuanya dari scheduler yang sudah dipasang `install.sh`):

| Perintah | Kapan | Isi |
|---|---|---|
| `backup:database` | tiap `BACKUP_DB_EVERY_HOURS` jam (bawaan 6: 00.00, 06.00, 12.00, 18.00) | dump database saja, lalu langsung dikirim ke luar server |
| `backup:run` | 02.00 | dump database (dikirim lebih dulu), sesi WhatsApp gateway, lalu cermin `storage/app` |
| `backup:monitor` | tiap jam menit 30 | bila tidak ada dump database valid lebih dari `BACKUP_STALE_HOURS` (26) jam: push ke HP super admin dan email ke `SUPPORT_EMAIL`, diulang tiap 6 jam selama masih begitu |

**Isi folder lokal** `storage/app/backups/` (izin 700, berkas 600):

- `db/database-TANGGAL.sql.gz`: dump PostgreSQL. Hanya disimpan bila lolos `gzip -t`. Disimpan 14 hari (`BACKUP_DB_KEEP_DAYS`).
- `sessions/gateway-TANGGAL.tar.gz`: sesi WhatsApp di `/opt/jitu-gateway/data`, bila ada. Disimpan 7 hari (`BACKUP_KEEP_DAYS`).
- `storage/storage-TANGGAL.tar.gz`: seluruh `storage/app`, **hanya bila belum ada salinan di luar server**. Dengan rclone, unggahan tidak lagi dibuat tarball setiap malam.
- Folder bertanggal lama (`TANGGAL/` dari versi sebelumnya) tetap dirotasi 7 hari.

Yang terbaru dari tiap jenis tidak pernah dihapus karena umur. Bila ruang kosong disk di bawah 20% (`BACKUP_DISK_MIN_FREE_PERCENT`), cadangan lokal tertua dihapus lebih dulu sampai cukup, kecuali dump database valid terbaru; langkah ini tercatat sebagai peringatan.

**Di luar server** (`BACKUP_RCLONE_REMOTE`, misalnya `offsite:bucket/jitu-lead`):

- `db/`: tiap dump, 60 hari (`BACKUP_DB_REMOTE_KEEP_DAYS`).
- `sessions/`: tarball sesi, 14 hari (`BACKUP_SESSIONS_REMOTE_KEEP_DAYS`).
- `storage/`: cermin `storage/app` lewat `rclone sync`. Hanya berkas yang berubah yang dikirim. Berkas yang dihapus atau diganti di server dipindah ke `storage-deleted/TANGGAL/` dan disimpan 30 hari (`BACKUP_REMOTE_KEEP_DAYS`).
- `media-archive/`: media yang diarsipkan dengan `backup:prune-media` (tidak dihapus otomatis).
- Folder bertanggal dari versi lama dihapus setelah `BACKUP_LEGACY_REMOTE_KEEP_DAYS` (bawaan sama dengan `BACKUP_REMOTE_KEEP_DAYS`). Yang terbaru dipertahankan selama `db/` belum berisi dump. Setelah dump pertama masuk ke `db/`, Anda bisa menurunkannya, misalnya ke 3, supaya kuota cepat lega.

Salinan lama dihapus **sebelum** unggahan, jadi kuota yang penuh membebaskan dirinya sendiri walau unggahan malam itu gagal. Yang terbaru dari tiap jenis tidak pernah dihapus.

> **Backblaze B2:** atur *Lifecycle Settings* bucket ke **Keep only the last version of the file**. Tanpa itu, berkas yang dihapus lewat S3 hanya disembunyikan dan masih dihitung ke kuota/cap.

**Kepemilikan tabel.** Sebelum dump, backup memeriksa semua tabel, view, dan sequence milik pengguna database aplikasi (`DB_USERNAME`). Objek milik pengguna lain dicatat sebagai peringatan dengan namanya. Bila pengguna aplikasi tidak bisa membacanya, objek itu dilewati supaya sisa database tetap tercadangkan, dan peringatannya menyebut bahwa objek itu tidak ikut. Perbaiki sebagai postgres: `ALTER TABLE nama OWNER TO jitu;` atau hapus bila hanya salinan. Buat tabel baru selalu sebagai pengguna aplikasi, bukan `postgres`.

Hasil tiap langkah tampil di **/admin**. Dasbor menampilkan backup malam terakhir, **database valid terakhir** dengan ukurannya, dan pemakaian **disk server** (kuning di atas 70%). Kegagalan dikirim lewat email (butuh `MAIL_*` benar) dan push ke HP/PC super admin yang sudah mengaktifkan notifikasi. Push dikirim langsung, tidak menunggu queue.

**Salinan di luar server (rclone).** Skrip `install.sh` memasang rclone. Atur remote lewat `.env` tanpa file konfigurasi rclone, contoh Backblaze B2 / S3-compatible (nama remote `OFFSITE`; ganti sesuai penyedia):

```
BACKUP_RCLONE_REMOTE=offsite:nama-bucket/jitu-lead
RCLONE_CONFIG_OFFSITE_TYPE=s3
RCLONE_CONFIG_OFFSITE_PROVIDER=Other
RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=...
RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_OFFSITE_ENDPOINT=https://s3.us-west-004.backblazeb2.com
```

Google Drive memakai `RCLONE_CONFIG_OFFSITE_TYPE=drive` dan token OAuth dari `rclone config` di komputer Anda (lihat dokumentasi rclone). Lalu `php artisan config:cache`. Pakai folder atau bucket khusus, karena salinan lama di folder itu dihapus otomatis. Kunci butuh izin hapus (rotasi dan cermin), tetapi jangan diberi akses ke bucket lain.

**Sesi WhatsApp gateway.** Folder itu milik pengguna `jitu-gw`. `sudo bash gateway/install.sh` memasukkan `www-data` ke grup `jitu-gw` dan mengatur folder `2750` serta berkas `0640` (hanya baca untuk grup, tertutup untuk lainnya), jadi backup ikut mengambilnya tanpa pengaturan tambahan. Gateway yang berjalan memakai `UMask=0027`, jadi berkas baru ikut aturan yang sama. Aman dijalankan ulang di server lama. Bila sebelumnya Anda memasang `setfacl` atau cron `setfacl` sebagai jalan pintas, hapus: skrip ini membersihkan ACL-nya, dan cron itu tidak diperlukan lagi.

**Coba sekarang:** `sudo -u www-data php artisan backup:database`, lalu `sudo -u www-data php artisan backup:run` (cek hasilnya di /admin → Backup).

### Memeriksa dump bisa dipulihkan

`sudo -u www-data php artisan backup:restore-check` memeriksa dump terbaru tanpa menyentuh database produksi:

1. `gzip -t` (arsip utuh);
2. jumlah tabel di dump minimal `BACKUP_DB_MIN_TABLES` (40);
3. dump dimuat ke database sementara `jitu_restore_check_TANGGAL`, jumlah tabelnya dihitung, lalu database itu dihapus lagi.

Langkah 3 butuh izin membuat database untuk pengguna aplikasi, sekali saja: `sudo -u postgres psql -c 'ALTER ROLE jitu CREATEDB;'`. Tanpa izin itu langkah 3 dilewati dan disebutkan. `--no-load` hanya menjalankan langkah 1 dan 2; `--file=...` memeriksa dump lain. Jalankan sebulan sekali, atau setelah mengubah skema.

### Media WhatsApp lama

`php artisan backup:prune-media` menampilkan media WhatsApp (`storage/app/private/wa-media`) yang lebih tua dari 90 hari (`BACKUP_MEDIA_KEEP_DAYS`, atau `--days=`), per agensi. Ini **uji kering**: tidak ada yang diubah. Daftar berkasnya ditulis ke `storage/logs/media-prune-*.log`.

- `--apply` menjalankannya. Di produksi perintah ini bertanya sekali lagi; `--force` melewati pertanyaan.
- `--mode=archive` (bawaan, `BACKUP_MEDIA_PRUNE_MODE`): berkas dipindah ke `<remote>/media-archive` lebih dulu. Bila pemindahan gagal, tidak ada yang dihapus.
- `--mode=delete`: berkas dihapus. Cermin malam berikutnya menyimpannya 30 hari lagi di `storage-deleted`.
- `--tenant=slug-atau-id` (bisa diulang) membatasi ke agensi tertentu.

Di inbox, pesannya lalu menampilkan "[Gambar sudah diarsipkan dari server karena sudah lama]" atau "[… sudah dihapus …]", bukan gambar rusak. Jalur lamanya disimpan di pesan (`media.pruned_path`) untuk pemulihan manual.

### Memulihkan (restore)

Uji prosedur ini sekali di staging sebelum Anda membutuhkannya. Data yang masuk setelah backup akan hilang.

1. Ambil backup:
   - database: `storage/app/backups/db/database-TANGGAL.sql.gz`, atau `rclone copy offsite:nama-bucket/jitu-lead/db/database-TANGGAL.sql.gz /tmp/restore`;
   - unggahan: `rclone copy offsite:nama-bucket/jitu-lead/storage /tmp/restore/storage` (cermin terbaru), atau `storage/storage-TANGGAL.tar.gz` bila tanpa remote;
   - sesi: `sessions/gateway-TANGGAL.tar.gz`, lokal atau dari `offsite:…/sessions`.

   Perintah rclone butuh variabel `RCLONE_CONFIG_*` di shell. Cadangan dari versi lama masih berupa folder `TANGGAL/` berisi `database.sql.gz`, `storage.tar.gz`, dan `gateway.tar.gz`.
2. Hentikan aplikasi: `cd /var/www/jitu-lead && sudo -u www-data php artisan down`, lalu `sudo systemctl stop jitu-queue`.
3. Database (menggantikan isi sekarang):
   ```
   sudo -u postgres psql jitu_lead -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO jitu;'
   gunzip -c /tmp/restore/database-TANGGAL.sql.gz | psql -h 127.0.0.1 -U jitu -v ON_ERROR_STOP=1 jitu_lead
   ```
   Muat sebagai `jitu` (kata sandinya `DB_PASSWORD`), bukan `postgres`, supaya semua tabel kembali dimiliki pengguna aplikasi dan backup berikutnya bisa membacanya.
4. Unggahan: dari cermin, `sudo rsync -a /tmp/restore/storage/ /var/www/jitu-lead/storage/app/ && sudo chown -R www-data:www-data /var/www/jitu-lead/storage/app`. Dari tarball: `sudo -u www-data tar -xzf /tmp/restore/storage-TANGGAL.tar.gz -C /var/www/jitu-lead/storage/app`. Lalu `sudo -u www-data php artisan storage:link` bila perlu.
5. WhatsApp (jika ada): `sudo systemctl stop jitu-gateway && sudo tar -xzf /tmp/restore/gateway-TANGGAL.tar.gz -C /opt/jitu-gateway/data && sudo chown -R jitu-gw:jitu-gw /opt/jitu-gateway/data && sudo systemctl start jitu-gateway`.
6. Nyalakan lagi: `sudo -u www-data php artisan migrate --force` (bila kode lebih baru dari backup), `sudo systemctl start jitu-queue`, `sudo -u www-data php artisan up`.
7. Hapus `/tmp/restore` (berisi data pelanggan).

Langkah database sudah dicoba di mesin pengembangan (dump lalu pulihkan ke database kosong, juga otomatis lewat `backup:restore-check` di tes); prosedur lengkap di VPS belum.

## Notifikasi di HP dan PC (push)

Jitu Lead mengirim notifikasi sendiri saat ada lead baru (formulir, landing page, WhatsApp) dan pesan WhatsApp masuk, ke HP maupun PC, walau tab sedang ditutup.

- Kunci VAPID dibuat otomatis oleh `php artisan push:keys` (sudah ada di `install.sh` dan `update.sh`). Perintah ini hanya mengisi kunci sekali dan tidak menimpa yang sudah ada. **Jangan ganti kuncinya**, karena semua perangkat yang sudah aktif harus didaftarkan ulang.
- Setelah kunci terisi, jalankan `php artisan config:cache` (update.sh sudah melakukannya lewat `optimize`).
- Wajib HTTPS. Browser tidak mengizinkan push di http biasa.
- Setiap orang mengaktifkannya di perangkatnya sendiri lewat **Profil agensi → Notifikasi (menu akun di kanan atas)**, lalu bisa mematikan jenis notifikasi yang tidak diinginkan.
- iPhone/iPad: butuh iOS 16.4 atau lebih baru, dan Jitu Lead harus dipasang dulu lewat Bagikan → **Tambah ke Layar Utama**, baru notifikasi bisa diaktifkan.
- Android/PC (Chrome, Edge, Firefox): cukup tekan Aktifkan. Boleh juga dipasang sebagai aplikasi lewat menu "Instal".
- Worker antrean (`queue:work`) harus berjalan, karena notifikasi dikirim lewat antrean.

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

Cara termudah (satu VPS): `sudo bash gateway/install.sh` (juga untuk memperbarui gateway; setelah pembaruan ke Baileys 7 semua nomor perlu scan QR ulang, lihat `gateway/README.md`) memasang gateway dan mengisi .env otomatis (lihat `gateway/README.md`). Atau isi manual lalu `php artisan config:cache`. Tanpa isian ini, agensi yang memilih "WhatsApp biasa" harus mengisi alamat dan kunci gateway mereka sendiri di bagian lanjutan.


## Email bermerek (logo dan tema)

Semua email (verifikasi, reset sandi, undangan, notifikasi pemilik, backup gagal, langganan) memakai satu tema di `resources/views/vendor/mail` dengan logo `public/images/email-logo.png`, dimuat lewat alamat absolut `APP_URL` (Gmail tidak menampilkan SVG). Pastikan `APP_URL` di `.env` benar (https dan domain publik), lalu `php artisan config:cache`. Untuk mengganti logo, ganti PNG itu (lebar tampil 160px, simpan 2x agar tajam).

Tautan verifikasi email yang dibuka oleh akun lain atau sudah kedaluwarsa kini menampilkan halaman penjelasan dengan tombol keluar-lalu-masuk dan kirim ulang, bukan 403 polos. Aksi "Kirim tautan reset sandi" di /admin → Users mengirim tautan ke halaman reset panel /app.

## Paket langganan (tahap 1, pembayaran manual)

Paket, harga, dan batas didefinisikan di satu tempat: `config/plans.php`. Halaman `/harga`, Profil agensi → Langganan, dan semua pengecekan batas membaca file itu.

Isi di `.env` lalu `php artisan config:cache`:

```
BILLING_BANK_INFO="Bank BCA 1234567890
a.n. Nama Anda"
BILLING_CONTACT="WhatsApp 0812xxxxxxx"
```

Agensi baru mendapat percobaan 14 hari dengan batas paket Tim Kecil. Agensi yang sudah ada mendapat 14 hari dihitung sejak migrasi `2026_10_17_000100_create_subscriptions` dijalankan.

Alur: admin agensi memilih paket di Profil agensi → Langganan, itu membuat permintaan (pending) berisi nominal dan instruksi transfer. Setelah Anda menerima pembayaran, aktifkan lewat artisan:

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

Lead baru dari formulir dan WhatsApp tetap diterima walau melebihi batas lead aktif atau status hanya-baca; lead tidak pernah dibuang. Biaya template WhatsApp resmi ditagih Meta langsung ke agensi dan bukan bagian paket. Pembayaran otomatis lewat iPaymu ada di bagian berikut. Invoice belum ada.

## Pembayaran otomatis iPaymu (QRIS, Virtual Account, e-wallet)

Memakai iPaymu API v2 (redirect payment). Transfer manual tetap jalan sebagai cadangan. Tanpa `IPAYMU_VA` dan `IPAYMU_API_KEY`, tombol bayar disembunyikan.

Isi di `.env` lalu `php artisan config:cache`:

```
IPAYMU_VA=0000001234567890
IPAYMU_API_KEY=xxxxxxxx
IPAYMU_SANDBOX=true      # true: https://sandbox.ipaymu.com, false: https://my.ipaymu.com
IPAYMU_EXPIRED_HOURS=24  # batas waktu bayar
```

VA dan API key sandbox berbeda dengan produksi (dashboard iPaymu, menu Integrasi). Saat produksi, iPaymu mewajibkan IP server statis dan domain yang terdaftar. Jalankan `php artisan migrate` (tabel `ipaymu_payments`).

**URL callback (notify):** `APP_URL/billing/ipaymu/notify`, dikirim otomatis di tiap transaksi (`notifyUrl`), jadi tidak perlu diisi di dashboard. Pastikan APP_URL benar dan dapat dijangkau dari internet.

Alur: admin agensi memilih paket di Profil agensi → Langganan lalu klik **Bayar sekarang**, dan dialihkan ke halaman iPaymu. Setelah bayar, iPaymu memanggil URL callback. Aplikasi memeriksa tanda tangan (`X-Signature`, kunci VA), lalu menanyakan ulang status transaksi ke API iPaymu, dan hanya bila statusnya berhasil serta harga paket (SubTotal dari API iPaymu, bukan total yang dibayar pembeli) dan referensinya cocok, paket diaktifkan dengan logika yang sama seperti tombol "Setujui" di super admin. Callback ganda aman (paket diaktifkan sekali). Pemilik platform menerima email dan push "Pembayaran diterima". Daftar transaksi ada di **/admin, menu Transaksi iPaymu**, berisi harga paket, total yang dibayar pembeli (bila biaya ditanggung pembeli), biaya, dan jumlah yang diterima.

Kode status iPaymu: 1 berhasil, 6 berhasil (belum settle), dan 7 escrow (pembeli sudah bayar, dana ditahan iPaymu) mengaktifkan paket; 0 menunggu; -2 kedaluwarsa; 2 batal, 3 refund, 4 error, 5 gagal ditandai gagal. Kode lain dianggap menunggu dan dicatat sebagai warning. Transaksi escrow tampil sebagai "Berhasil (escrow)" di Transaksi iPaymu. API v2 tidak mendokumentasikan parameter untuk mematikan escrow; bila perlu, minta iPaymu menonaktifkannya untuk akun Anda.

Pengecekan: setiap callback dicatat di log aplikasi (`storage/logs`: ID transaksi, status, referensi, hasil), tanpa API key. Callback dengan tanda tangan salah dibalas 400, referensi atau nominal tidak cocok dibalas 422, dan bila API iPaymu tidak terjangkau dibalas 503 agar iPaymu mengirim ulang.

Uji sandbox: isi kunci sandbox, `IPAYMU_SANDBOX=true`, pilih paket, klik Bayar sekarang, selesaikan pembayaran di sandbox, lalu pastikan status di Profil agensi → Langganan berubah menjadi Berhasil dan paket aktif.

## Landing page builder

Panduan lengkap dan format data ada di [landing-builder.md](landing-builder.md). Untuk server:

- `deploy/install.sh` memasang `php8.3-gd` (pengecil gambar, WebP) dan menulis `/etc/php/8.3/fpm/conf.d/90-jitu.ini`
  (`upload_max_filesize=10M`, `post_max_size=12M`, `memory_limit=256M`). Di server yang sudah berjalan, jalankan ulang
  `install.sh`, atau buat file itu sendiri lalu `sudo systemctl restart php8.3-fpm`.
- Setelah `git pull`: `php artisan migrate --force` (menambah kolom halaman dan mengubah format halaman lama sekali),
  lalu `php artisan view:clear`.
