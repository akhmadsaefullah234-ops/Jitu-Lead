# Dashboard super admin dan chat support

Panel pemilik aplikasi ada di `/admin` (terpisah dari panel agensi di `/app`). Hanya akun yang ditandai super admin yang bisa masuk; pengguna biasa mendapat 403.

## Membuat super admin

```
cd /var/www/jitu-lead
sudo -u www-data php artisan admin:make email@anda.com --name="Nama Anda"   # akun baru: ditanya kata sandi
sudo -u www-data php artisan admin:make email@anda.com                      # akun yang sudah ada dijadikan super admin
sudo -u www-data php artisan admin:make email@anda.com --revoke             # cabut
```

Satu-satunya cara menjadikan super admin adalah perintah ini (tidak ada tombol di aplikasi).

## Isi panel

- **Dasbor**: jumlah agensi, uji coba berjalan dan yang sudah habis, langganan aktif, jatuh tempo/hanya-baca, perkiraan pendapatan per bulan (paket aktif, tanpa add-on), permintaan paket yang menunggu, chat yang belum dibalas, grafik pendaftar 30 hari, dan daftar uji coba yang segera/baru berakhir.
- **Agensi**: semua agensi dengan status paket, pengguna, jumlah lead. Aksi per agensi: aktifkan paket, perpanjang uji coba, tambah add-on, tangguhkan / aktifkan kembali. Agensi yang ditangguhkan tidak bisa masuk dan formulir/halaman publiknya berhenti menerima lead; data tidak dihapus.
- **Permintaan paket**: permintaan dari popup/halaman Langganan. Setelah transfer masuk, klik Setujui; paket langsung aktif.
- **Chat support**: kotak masuk pesan pengguna. Buka, balas, tandai selesai. Pesan baru menandai chat sebagai "baru" sampai dibuka.
- **Kode undangan**: buat dan hapus kode (hanya dipakai bila `REGISTRATION_MODE=invite`).
- **Pengguna**: cari pengguna, tandai email terverifikasi (berguna bila SMTP belum jalan), kirim tautan reset kata sandi.

## Chat support di aplikasi

Tombol "Bantuan" muncul di kanan bawah setiap halaman agensi (juga saat akun hanya-baca). Setiap pengguna punya satu percakapan pribadi dengan tim Anda; rekan satu agensi tidak saling melihat. Maksimal 8 pesan per menit per pengguna, 2000 karakter per pesan, teks selalu di-escape.

Di `.env` (opsional, lalu `php artisan config:cache`):

```
SUPPORT_EMAIL=anda@domain.com      # email pemberitahuan tiap ada pesan baru (butuh MAIL_* yang benar)
SUPPORT_WHATSAPP=6281234567890     # tautan WhatsApp cadangan di jendela chat
```

Tanpa email, cukup buka **Chat support** di panel admin; badge merah menunjukkan jumlah chat yang belum dibalas. Chat memakai polling (bukan WebSocket), jadi balasan muncul dalam beberapa detik.

## Catatan

- Belum diuji di VPS; diuji otomatis (226 tes).
- Menangguhkan agensi mengunci semua penggunanya dari agensi itu; mereka masih bisa membuat agensi baru dengan akun yang sama.
