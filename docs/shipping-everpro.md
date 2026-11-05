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
4. Isi **Pengirim**: nama toko, HP, alamat lengkap. Simpan dulu, lalu cari **Lokasi asal** (ketik kode pos, paling cepat,
   atau nama kelurahan/kecamatan) dan pilih. Id lokasi Everpro disimpan.
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

- Menu ⋯ di Inbox dan tombol **Pengiriman** di halaman lead: **Buat resi**, **Hubungkan resi**, **Lacak paket**, **Cetak label**,
  **Batalkan kiriman** (hanya sebelum paket diambil kurir).
- Daftar lead: aksi massal **Buat resi** (hanya lead di tahap Dikemas & dikirim tanpa kiriman aktif; lead yang datanya
  belum lengkap dilewati dan disebutkan) dan **Cetak label**: label tiap lead terpilih diunduh di server (maks. 100
  sekali cetak, batas waktu 15 detik per label) lalu digabung jadi satu PDF, satu label per halaman dengan ukuran asli
  penyedia, urut seperti tabel. PDF disimpan privat (`storage/app/private/label-sheets/{tenant}/`, nama acak), dibuka
  lewat tautan bertanda tangan 1 jam yang hanya bisa dibuka anggota toko itu, dan dihapus setelah 24 jam oleh
  `shipping:labels-prune` (terjadwal tiap jam). Ringkasan menyebut lead yang dilewati beserta alasannya (belum ada resi
  / label gagal diambil). Catatan: label PDF yang memakai kompresi xref (PDF 1.5+) tidak terbaca oleh FPDI versi gratis
  dan akan dilewati dengan alasan "label gagal diambil".
- Alamat penerima dipetakan dari rincian alamat lead ke lokasi Everpro. Endpoint `/location/v1/postalcode` hanya menerima
  kode pos 5 digit, jadi: kode pos dipakai langsung bila ada; kalau tidak, nama kelurahan/kecamatan/kota dicari dulu kode
  posnya (dari data wilayah yang diimpor, atau daftar `/location/v1/sub-districts` dan `/location/v1/districts` Everpro),
  lalu kode pos itu dicari di `/postalcode` dan hanya kelurahan yang namanya cocok yang dipakai. Hanya jawaban yang
  berhasil yang di-cache (30 hari). Bila tidak pasti, agen memilih kelurahan dari daftar.
- Divalidasi: nama, HP, alamat lengkap, produk dan jumlah, nilai barang > 0, kode pos asal (dari lokasi asal di
  Pengaturan > Pengiriman) dan kode pos kelurahan tujuan. Berat dari katalog (dibulatkan ke atas per kg).
- Body `/shipment/v2/rates` dan `/shipment/v2/orders` datar sesuai dokumentasi prepaid: kode pos asal/tujuan (bukan
  id lokasi), `package_type_id` 1, `weight` dalam kg (1000 g = 1, 1500 g = 1,5), `item_price`/`package_price` = nilai
  barang, nama kelurahan pengirim/penerima.
- Jawaban `/shipment/v2/rates` (di dalam `data`) dikelompokkan per jenis layanan: `regular`, `express`, `same_day`,
  `instant`, `cargo`, `vehicle`; semua grup dibaca dan nama grup disimpan sebagai kategori. `logistic_code` selalu
  kosong, jadi kurir dikenali dari `logistic_name` + `rate_name` (rate_name "Regular" ditampilkan "REG"). Layanan
  pengaturan "JNE REG" = logistic "JNE" dengan rate_name "Regular" di grup regular, apa pun kodenya (REG23);
  "SiCepat REG" = "SiCepat" + "Regular". Ongkir = `shipment_price_after_discount` (atau `price`) + `surcharge`; angka
  berupa teks dibaca sebagai angka. Rates tidak punya biaya COD.
- Pilihan kurir di Buat resi: grup regular dan express, urut termurah, dengan ongkir, cashback dan estimasi; grup cargo
  hanya untuk paket di atas 5 kg (atau bila sudah terpilih). Bawaan: kurir lead, lalu layanan di pengaturan.
- Tarif yang dipilih menyimpan `rate_code`, `signed_key`, `shipment_price` (= after discount), `cashback`,
  `is_flat_rate`, `insurance_price`, durasi; semuanya dikirim lagi saat order. Nama grup dan field jawaban (tanpa
  nilai) dicatat sebagai warning `Everpro rates answer keys` sekali per 30 hari per agensi.
- Bila alamat lead belum lengkap, form **Buat resi** menampilkan isian alamat (nama, alamat, RT/RW, patokan,
  kelurahan, kecamatan, kota, kode pos) dan tombol **Ambil dari chat**. Alamat yang diubah disimpan ke lead saat resi
  dibuat.
- Promo ongkir: `shipment_price` ke Everpro selalu ongkir asli tarif terpilih; `cod_price` = harga x jumlah + ongkir yang
  ditagih ke pembeli setelah promo ongkir produk (ongkir yang tercatat di lead, atau ongkir Everpro dikurangi promo bila
  belum pernah dihitung). Pratinjau menampilkan ongkir asli, potongan/subsidi penjual, cashback Everpro dan total COD.

## Promo ongkir per produk

- Produk > tab **Iklan & promo** > **Promo ongkir** (toko saja): jenis (potongan Rp, gratis ongkir, ongkir maksimal Rp),
  tujuan (semua, Pulau Jawa & Bali, luar Jawa & Bali, provinsi tertentu), minimal jumlah, nama promo opsional.
- Dipotong di satu tempat (`Shipping::quote` lewat `ShippingPromos`), jadi jawaban AI (`cek_ongkir`), pesanan yang
  dicatat (`shipping_cost`, total) dan Buat resi memakai angka yang sama. Satu promo per pesanan: yang paling hemat,
  tidak pernah di bawah nol. Hasil tool memuat `ongkir_normal`, `potongan_ongkir` dan `promo_ongkir`; catatan pesanan
  menyebut ongkir normal dan promonya.
- Pesanan yang sudah tercatat sebelum promo diisi tidak berubah sendiri: catat ulang ongkirnya.
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

## Resi yang dibuat di luar JITU LEAD

Resi yang dibuat manual (mis. di aplikasi atau dashboard Everpro) bisa dihubungkan ke lead:

- **Hubungkan resi** (menu ⋯ Inbox dan tombol **Pengiriman** di halaman lead): nomor resi dan kurir (bawaan JNE). Dibuat
  kiriman dengan sumber `manual` tanpa order Everpro (tidak bisa dibatalkan dari JITU LEAD), nomor resi dan kurir diisi ke
  lead, lalu resi langsung dilacak sekali.
- **Impor resi** (daftar Lead): unggah ekspor CSV/XLSX. Kolom ditebak dari judulnya dan bisa diganti. Baris dicocokkan
  lewat nomor HP penerima (dinormalisasi). Bila ada beberapa lead dengan nomor itu, yang di tahap Dikemas & dikirim tanpa
  resi diutamakan. Baris yang ragu atau tidak cocok dipilih manual atau dilewati, dan hanya baris yang dipilih yang disimpan.
- **Pesan ke pembeli**: opsi "Kirim nomor resi ke pembeli sekarang" bawaannya mati. Status yang sudah lewat saat resi
  dihubungkan hanya dicatat sebagai riwayat; pembeli hanya mendapat pesan untuk perubahan status sesudahnya. Bila paket
  sudah diterima saat dihubungkan, lead dipindah ke Selesai tanpa pesan (opsi, bawaan aktif, lewat aturan "paket
  diterima" di Otomatisasi tahap). Tanggal masuk tahapnya disamakan dengan tanggal diterima, sehingga follow-up ulasan
  yang waktunya sudah lewat tidak terkirim.
- **Pelacakan**: `shipping:track` melacak resi manual satu per satu. Bila Everpro tidak mengenali resinya (404/400, atau
  tanpa status maupun riwayat), percobaan dihitung. Setelah 3 kali, resi ditandai "tidak bisa dilacak" di lead dan
  pelacakan otomatis berhenti.
- **Validasi**: satu resi hanya untuk satu lead per agensi, dan formatnya 8–30 huruf/angka dengan minimal 4 angka.

## Alamat dari chat

Data pesanan yang ditulis pembeli di chat masuk ke lead, baik saat AI aktif maupun saat chat ditangani CS
(`App\Leads\ChatShippingDetails`, tanpa AI):

- Format order (`Nama:`, `Alamat:`, `Warna:`, `Jumlah:`, `Pembayaran:`, `Produk:` …), alamat yang diketik utuh, dan
  tambahan sesudahnya (RT/RW, patokan setelah ditanya patokan, nomor rumah, kelurahan, kecamatan, kota, kode pos)
  memperbarui nama, alamat, `shipping_address_parts`, provinsi, varian, jumlah, cara bayar, dan produk (bila belum ada).
- Tambahan melengkapi alamat yang sudah ada; alamat lengkap yang berbeda menggantikan yang lama, dan yang lama dicatat
  di aktivitas lead ("Alamat diperbarui dari chat … Alamat sebelumnya: …"). Isi alamat tidak ditulis ke log server.
- Bagian alamat dicocokkan ke data wilayah yang diimpor (`regions:import`) untuk melengkapi kecamatan, kota, provinsi
  dan kode pos. Alamat yang dicatat AI (`catat_pesanan`) juga dipecah ke bagian-bagiannya.
- Lead yang masuk Dikemas & dikirim dengan alamat belum lengkap: seluruh chat-nya dibaca sekali.
- Setelah resi terbit, chat tidak lagi mengubah data lead.
- Data lama: `php artisan leads:extract-shipping --tenant=quicker-sport` (pratinjau: tidak menyimpan apa pun; hanya
  menampilkan nomor lead, 4 digit akhir HP, dan bagian yang terisi). Tambahkan `--apply` untuk menyimpan. Bawaan
  tahap Order masuk, Menunggu pembayaran dan Dikemas & dikirim; `--stage=shipping` (atau nama tahap) untuk memilih,
  `--all` juga lead yang alamatnya sudah lengkap.

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
