# Pengiriman otomatis lewat Everpro

Toko (industri retail) bisa membuat resi, label, jemput paket, dan melacak paket langsung dari JITU LEAD.
Pembeli mendapat WhatsApp sesuai status paketnya, lead pindah ke **Selesai** saat paket diterima, dan AI bisa
menjawab "paketnya sampai mana?" dari status yang tersimpan.

Kode: `App\Shipping\Carriers` (antarmuka `CarrierProvider`, `EverproCarrier`, `ShipmentStatus`, `Carriers`),
`App\Shipping\Shipments` (`Shipments`, `StatusNotices`), job `App\Jobs\CreateShipment` dan `App\Jobs\TrackShipments`,
perintah `shipping:notices` (tiap menit) dan `shipping:track` (tiap 2 jam), callback `POST /webhooks/shipping/{penyedia}/{token}`,
tabel `carrier_accounts`, `shipments`, `shipment_notices`, konfigurasi `config/carriers.php`.

Everpro adalah penyedia pertama. Bagian lain (tombol, pesan status, garis waktu, tahap otomatis, AI) hanya memakai
`CarrierProvider`, jadi KiriminAja atau Biteship cukup ditambah sebagai kelas baru di `config/carriers.php`.

## 1. Mengisi kredensial (Pengaturan > Pengiriman)

1. Masuk sebagai admin toko, buka **Pengaturan > Pengiriman**, bagian **Pengiriman otomatis**.
2. Pilih penyedia **Everpro**, biarkan **Mode sandbox** menyala untuk uji coba.
3. Isi **Client key** dan **Client secret** dari dashboard Everpro, lalu **Callback key** (nilai header `x-everpro-key`
   yang juga Anda isi di dashboard Everpro). Ketiganya disimpan terenkripsi dan tidak pernah ditampilkan lagi; kolom
   menampilkan "Tersimpan". Kosongkan kolom saat menyimpan untuk tetap memakai yang tersimpan, ketik untuk mengganti.
4. Isi **Pengirim**: nama toko, HP, alamat lengkap. Simpan dulu, lalu cari **Lokasi asal** (ketik kode pos atau nama
   kelurahan) dan pilih. Id lokasi Everpro disimpan.
5. **Kurir bawaan** (mis. `JNE REG`) dipakai bila pembeli belum memilih kurir. Pilih PICKUP (dijemput, bawaan) atau DROP,
   dan asuransi bila perlu.
6. Tekan **Uji koneksi**: JITU LEAD mengambil token dan saldo Everpro. Saldo tampil di bawah URL callback.

Tanpa penyedia yang terisi lengkap, semua menu resi otomatis disembunyikan dan JITU LEAD tidak memanggil Everpro.

## 2. Menyalin URL callback ke dashboard Everpro

Di bawah kolom kredensial tampil **URL callback**, unik per agensi, misalnya
`https://<domain JITU LEAD>/webhooks/shipping/everpro/<token>`. Salin ke dashboard Everpro > **Pengaturan > Konfigurasi**
untuk callback AWB dan callback status pengiriman, dengan key yang sama dengan **Callback key** di atas.

Callback tanpa key yang benar dibalas 401. Callback yang benar selalu dibalas 200 (Everpro mengulang sampai 6 kali bila
tidak), juga untuk resi yang tidak dikenal. URL satu agensi tidak bisa menyentuh paket agensi lain.

## 3. Uji di sandbox, lalu pindah ke production

1. Dengan mode sandbox, buat resi untuk lead contoh (Inbox ⋯ > **Buat resi**, atau halaman lead > **Pengiriman**).
2. Periksa pratinjau: kurir, ongkir Everpro vs ongkir yang ditagih ke pembeli, total COD, jam jemput, isi paket.
3. Pastikan resi terbit, label bisa dicetak, dan status dari callback sandbox muncul di **Perjalanan paket** di halaman lead.
4. Isi `EVERPRO_BASE_URL` di `.env` server dengan base URL production dari dokumentasi/dashboard Everpro
   (sandbox: `https://client-api-sandbox.everpro.id`). Lalu matikan **Mode sandbox**, ganti kredensial dengan yang
   production bila berbeda, simpan, dan tekan **Uji koneksi** lagi.

## Buat resi

- Menu ⋯ di Inbox dan tombol **Pengiriman** di halaman lead: **Buat resi**, **Lacak paket**, **Cetak label**,
  **Batalkan kiriman** (hanya sebelum paket diambil kurir).
- Daftar lead: aksi massal **Buat resi** (hanya lead di tahap Dikemas & dikirim tanpa kiriman aktif; lead yang datanya
  belum lengkap dilewati dan disebutkan) dan **Cetak label** (daftar tautan label).
- Alamat penerima dipetakan dari rincian alamat lead (kode pos, kelurahan, kecamatan, kota) ke lokasi Everpro. Bila tidak
  pasti, agen memilih kelurahan dari daftar.
- Divalidasi: nama, HP, alamat lengkap, produk dan jumlah. Berat dari katalog (dibulatkan ke atas per kg).
- COD: `cod_price` = harga x jumlah + ongkir yang ditagih ke pembeli (setelah promo) + biaya COD Everpro bila ada.
- Nomor pesanan `JL-{agensi}-{lead}-{n}` tetap per pesanan: klik dobel atau coba ulang tidak membuat pesanan dua kali.
- Order lalu generate AWB berjalan lewat antrean (coba ulang terbatas bila Everpro sibuk; saldo kurang atau alamat
  ditolak langsung diberitahukan ke agen). Resi yang terbit (dari order atau callback AWB) menjalankan alur resi yang
  sudah ada: resi dikirim ke pembeli lewat WhatsApp sekali, lead pindah ke Dikemas & dikirim.

## Status dan pesan ke pembeli

Status Everpro dinormalkan di `config/carriers.php` (`everpro.status_map`) menjadi: `awb_created`, `picked_up`,
`in_transit`, `out_for_delivery`, `delivery_failed`, `delivered`, `returning`, `returned` (serta `cancelled`). Kode yang
tidak dikenal hanya dicatat di riwayat paket. Status yang lebih awal yang datang terlambat diabaikan.

Teks per status diatur di **Pengaturan > Pengiriman > Pesan status kiriman** (aktif/nonaktif + teks; variabel `{nama}`,
`{produk}`, `{resi}`, `{kurir}`, `{link_lacak}`, `{alasan}`, `{cod_total}`, `{agensi}`). Variabel tak dikenal tidak
pernah terkirim mentah: baris "Label: {variabel}" dihapus, selain itu diganti "-".

| Status | Bawaan |
| --- | --- |
| Diambil kurir | Aktif |
| Dalam perjalanan | Nonaktif |
| Sedang diantar | Aktif; teks COD menyebut uang yang perlu disiapkan |
| Gagal diantar | Aktif; agen pemilik lead diberi notifikasi lonceng dan email, chat diberi chip merah "Perlu tindakan" |
| Diterima | Aktif; lead pindah ke Selesai lewat Otomatisasi tahap (aturan "Setelah resi dikirim → Selesai") |
| Dikembalikan / sudah kembali | Tidak pernah ke pembeli; catatan di lead dan notifikasi agen. Lead tidak dibatalkan otomatis. |
| Resi terbit | Memakai pesan resi yang sudah ada (tidak dobel) |

Aturan kirim: sekali per status per resi; jam 21.00 sampai 05.00 (zona waktu agensi) pesan menunggu sampai 05.00;
"sedang diantar" yang harinya sudah lewat tidak dikirim. Pesan lewat nomor WhatsApp pemilik lead (RouteSelector),
tampil di Inbox sebagai "· Status kiriman", tanpa pesan perkenalan nomor.

Cadangan: `shipping:track` tiap 2 jam melacak massal resi yang belum selesai dan memicu pesan yang sama bila callback terlewat.

## AI

Pertanyaan posisi paket ("sampai mana", "kapan sampai", "resinya") dijawab dari status tersimpan (dilacak ulang dulu bila
lebih dari 2 jam). AI tidak boleh menyebut perkiraan tiba; jawaban yang menyebut estimasi yang tidak ada di data kurir
diserahkan ke agen. Tanpa resi atau data pengiriman, chat langsung diserahkan ke agen.

## Keamanan

Kredensial dan token tidak pernah ditulis di kode, tes, atau log. Token Everpro disimpan terenkripsi di cache per agensi
dan diperbarui sebelum kedaluwarsa (401 → token baru sekali). Log hanya memuat id agensi, jalur API tanpa nomor resi,
kode status, dan pesan galat Everpro, tanpa data pembeli.

## Catatan untuk pengembang

Dokumentasi Everpro tidak mencantumkan semua bentuk jawaban (ongkir v2, pelacakan massal, daftar `tracking_code`).
`EverproCarrier` membaca nama field yang terdokumentasi lebih dulu, lalu alternatif umum. Bila di sandbox ada field
atau kode status yang berbeda, sesuaikan `EverproCarrier` dan `everpro.status_map`; bagian lain tidak perlu diubah.
