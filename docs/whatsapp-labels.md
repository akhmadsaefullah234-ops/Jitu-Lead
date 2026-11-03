# Label WhatsApp Business otomatis (uji coba)

Meta memakai label bawaan WhatsApp Business sebagai sinyal iklan Klik ke WhatsApp (lewat fitur berbagi data
WhatsApp Business ke Meta): **Prospek** (lead), **Order baru** (pesanan/pembelian), **Lunas** (dibayar). Label lain
(Pelanggan baru, Menunggu pembayaran, Penting, Tindak lanjut, Favorit) bukan sinyal.

Fitur ini memasang label tersebut di chat lead secara otomatis dari pipeline, lewat gateway scan QR (perangkat
tertaut), supaya admin tidak perlu memberi label manual di HP.

> **Uji coba.** Belum pasti Meta menghitung label yang dipasang dari perangkat tertaut sama seperti dari HP.
> Fitur ini mati bawaan, dinyalakan per nomor, dan setiap pemasangan dicatat supaya bisa dicocokkan dengan
> Ads Manager. Bila angka di Ads Manager tidak ikut bergerak, matikan fitur ini.

## Pengaturan (Koneksi WhatsApp → edit nomor gateway → "Label WhatsApp Business (uji coba)")

- **Aktifkan label otomatis** — bawaan mati.
- **Hanya chat dari iklan** — bawaan nyala: hanya lead yang punya ID iklan, `ctwa_clid`, atau data iklan.
  Lead lain dicatat "dilewati".
- Tiga label, masing-masing: nama label di WhatsApp, "saat lead ditandai Hot", dan tahap pemicu.
  **Muat label dari WhatsApp** meminta gateway menyinkron ulang semua label akun (`POST /sessions/:id/labels/resync`,
  paling sering 1x per 10 menit; tanpa scan ulang), lalu menampilkan tanda centang untuk ketiga label (✓ Prospek,
  ✓ Order baru, ✗ Lunas, termasuk padanannya Lead/New order/Paid). Label yang belum ada diberi petunjuk: pasang
  sekali di HP, lalu klik tombol lagi. Hasilnya juga tampil tetap di bagian "Label di WhatsApp".

Bawaan per industri:

| Label | Toko (retail) | Properti |
| --- | --- | --- |
| Prospek | lead jadi Hot | Hot, atau tahap survei |
| Order baru | Order masuk | tahap booking/UTJ |
| Lunas | Dikemas & dikirim atau Selesai bila transfer / belum diketahui; Selesai bila COD | tahap menang (akad/closing) |

## Cara kerja (`App\WhatsApp\BusinessLabels`)

- Dipicu di titik yang sama dengan event konversi (`Lead::saved`): pindah tahap oleh agen, otomasi tahap, AI, dan
  lead menjadi Hot. Setelah commit, lewat antrean (`ApplyWhatsAppLabels`, 3 percobaan). Tidak pernah
  menggagalkan perpindahan tahap.
- Hanya untuk chat yang berlangsung di nomor gateway (kanal masuk terakhir percakapan lead). Cloud API dilewati.
- Sekali per lead per label (`wa_label_events`, unik tenant + lead + label). Bila lead lompat ke tahap "Lunas",
  label sebelumnya yang belum terpasang (Prospek, Order baru) dipasang lebih dulu, berurutan. Bila gateway tidak
  bisa dihubungi, label berikutnya tidak dipasang dulu agar urutan tetap benar.
- Label tidak pernah dilepas. Gateway tidak pernah membuat label baru.
- Bila akun belum punya label itu, statusnya **menunggu label** (`waiting_label`), bukan gagal: aktivitas lead
  mencatat petunjuknya sekali, lalu label dipasang otomatis begitu akun memilikinya (`whatsapp:labels-retry` tiap
  10 menit, atau langsung setelah "Muat label dari WhatsApp"), berurutan. Setelah 7 hari tetap tidak ada, statusnya
  gagal dengan catatan "setelah 7 hari menunggu". Akun pribadi (bukan WhatsApp Business) langsung gagal.
- Aktivitas lead mencatat hasilnya, mis. "Label WhatsApp 'Order baru' dipasang (uji coba)", atau alasan gagal /
  dilewati (label belum ada, bukan akun bisnis, bukan dari iklan, gateway tidak bisa dihubungi). Log tanpa nomor HP.

## Menilai hasil

1. Halaman nomor menampilkan ringkasan 30 hari per label (terpasang / menunggu label / gagal / dilewati).
2. Cocokkan di HP: chat lead yang baru pindah tahap harus menampilkan label yang sama.
3. Pastikan berbagi data WhatsApp Business ke Meta aktif.
4. Ads Manager → kampanye Klik ke WhatsApp → tambah kolom hasil percakapan / pembelian / lead dari WhatsApp.
   Bandingkan dengan ringkasan selama beberapa hari.

## Pemasangan

Setelah merge: `php artisan migrate`, lalu pasang ulang gateway (`gateway/install.sh`) supaya endpoint label dan
sinkron ulang tersedia. Nomor yang sudah tertaut tidak perlu scan ulang: gateway menyinkron ulang label otomatis
sekali saat tersambung bila belum punya daftar label, dan tombol "Muat label dari WhatsApp" melakukannya kapan saja.
Scheduler Laravel (`php artisan schedule:run` tiap menit) harus berjalan untuk `whatsapp:labels-retry`.

# Kabar saat nomor gateway terputus (`App\WhatsApp\ChannelAlerts`)

Admin agensi (peran Admin, aktif) mendapat email dan notifikasi lonceng di aplikasi:

- **Perlu scan ulang**, langsung, sekali per kejadian: login rusak, atau **perangkat tertaut dilepas dari HP**
  (alasan tertulis di email dan di kolom Catatan nomor).
- **Terputus**: `whatsapp:watch` (tiap 5 menit) menanyakan status setiap nomor gateway yang pernah tersambung ke
  gateway. Nomor yang tidak tersambung lebih dari 5 menit diberi kabar sekali.
- **Tersambung lagi**: sekali, setelah kabar terputus/perlu scan ulang.
- Tidak ada kabar untuk putus singkat (< 5 menit), nomor yang diputuskan sendiri dari CRM, atau status "Bermasalah"
  karena satu kiriman gagal sementara gateway tetap tersambung (status dipulihkan otomatis).
- `wa_channels.down_since` = awal gangguan, `down_alerted_at` = waktu kabar terakhir (terisi: pemulihan belum
  dikabarkan).
