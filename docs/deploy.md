# Memasang di VPS

Syarat: Ubuntu 22.04/24.04 (atau Debian 12), RAM minimal 2 GB, akses root, dan domain yang record A-nya sudah mengarah ke IP VPS.

```bash
curl -fsSLO https://raw.githubusercontent.com/akhmadsaefullah234-ops/Jitu-Lead/main/deploy/install.sh
sudo bash install.sh app.contoh.com email@contoh.com
```

Skrip memasang PHP 8.3, PostgreSQL, Nginx, HTTPS, aplikasi, queue worker, dan scheduler. Aman dijalankan ulang untuk memperbarui kode (migrasi ikut dijalankan). Password database dibuat acak dan hanya ada di `.env` server.

Setelah selesai, buka `https://app.contoh.com/app` dan daftar akun admin pertama.

Memperbarui ke versi terbaru: jalankan lagi perintah di atas, atau `sudo bash install.sh app.contoh.com email@contoh.com nama-cabang` untuk mencoba cabang tertentu.
