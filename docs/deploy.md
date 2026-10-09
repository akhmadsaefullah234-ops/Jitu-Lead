# Memasang di VPS

Syarat: Ubuntu 22.04/24.04 (atau Debian 12), RAM minimal 2 GB, akses root, dan domain yang record A-nya sudah mengarah ke IP VPS.

```bash
curl -fsSLO https://raw.githubusercontent.com/akhmadsaefullah234-ops/Jitu-Lead/main/deploy/install.sh
sudo bash install.sh app.contoh.com email@contoh.com
```

Skrip memasang PHP 8.3, PostgreSQL, Nginx, HTTPS, aplikasi, queue worker, dan scheduler. Aman dijalankan ulang untuk memperbarui kode (migrasi ikut dijalankan). Password database dibuat acak dan hanya ada di `.env` server.

Setelah selesai, buka `https://app.contoh.com/app` dan daftar akun admin pertama.

Memperbarui ke versi terbaru: jalankan lagi perintah di atas, atau `sudo bash install.sh app.contoh.com email@contoh.com nama-cabang` untuk mencoba cabang tertentu.

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

## Pendaftaran dengan undangan

Bawaan: pendaftaran akun baru butuh kode undangan. Buat kode di server:

```
cd /var/www/jitu-lead
sudo -u www-data php artisan invite:create --uses=1 --days=14 --note="Pak Budi"
sudo -u www-data php artisan invite:list
```

Atur di `.env` lalu jalankan `php artisan config:cache`:

```
REGISTRATION_MODE=invite   # bawaan, wajib kode undangan
# REGISTRATION_MODE=open   # siapa pun boleh mendaftar
# REGISTRATION_MODE=closed # halaman daftar ditiadakan
```

Akun yang sudah ada tidak terpengaruh.
