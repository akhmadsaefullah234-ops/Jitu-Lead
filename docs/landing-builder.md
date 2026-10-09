# Landing page builder (tahap A)

Halaman landing dibuat dari **template**, disusun dari **bagian (section)**, dan diedit di Filament
(menu Halaman landing). Kode ada di `app/LandingPages/`.

## Cara pakai singkat

1. Halaman landing > Buat halaman > pilih template, isi judul > Buat. Halaman baru selalu **Draf**.
2. Ganti isi contoh. Bagian bisa ditambah, dihapus, digandakan, diurutkan (seret atau tombol naik/turun),
   dan disembunyikan. Tiap bagian punya latar terang / gelap / warna utama.
3. Pengaturan halaman: judul, alamat (slug), status, jenis huruf (3 pilihan, semua font sistem), warna utama,
   logo, nomor WhatsApp tujuan (kosong = nomor WhatsApp agensi pertama), properti terkait.
4. SEO dan berbagi: judul meta, deskripsi, gambar OG (dasar: foto hero, lalu logo).
5. Pratinjau samping (HP / Tablet / Desktop) memuat perubahan yang belum disimpan. Tombol **Pratinjau di tab baru**
   memakai tautan bertanda tangan (berlaku 30 menit, `noindex`).
6. Hanya halaman berstatus **Terbit** yang tampil di `/p/{agensi}/{halaman}`.

Template: Rumah KPR / subsidi, Cluster / perumahan premium, Villa / properti investasi (tanpa janji angka),
Kavling / tanah, Listing rumah second, dan Halaman kosong. Template dan semua bagian tersedia di semua paket;
yang dibatasi paket hanya **jumlah halaman** (`config/plans.php`) dan pixel iklan (terkunci di paket mandiri).

## Format data (`landing_pages.blocks`)

```json
[
  {"type": "hero", "version": 1, "props": {"headline": "...", "subheadline": "...", "cta_label": "...", "image": "landing/12/uuid.webp", "hidden": false}},
  {"type": "price", "version": 1, "props": {"label": "Harga mulai dari", "price": "Rp 450 juta", "bg": "brand"}}
]
```

- `type`: salah satu kunci di `Sections::TYPES` (hero, price, gallery, amenities, unit_types, location, kpr,
  testimonials, faq, legal, video, cta, form, countdown, highlights, details, text). Type tak dikenal dibuang saat dibaca.
- `version`: 1. Naikkan bila bentuk `props` suatu bagian berubah, lalu tambahkan konversi di `BlockFormat::normalize()`.
- `props`: isi bagian. Dua saklar umum: `hidden` (bool) dan `bg` (`auto|light|dark|brand`).
- Hanya `BlockFormat` yang mengubah bentuk Builder `{type, data}` menjadi `{type, version, props}` dan sebaliknya.
  Format lama `{type, data}` tetap terbaca, dan migrasi `2026_10_18_000100_*` mengubah halaman lama sekali
  (`whatsapp` menjadi `cta`, `text.body` menjadi `text.html`).

## Keamanan

- Tidak ada HTML/JS mentah dari pengguna. Semua teks di-escape oleh Blade.
- Satu-satunya teks berformat: bagian **Teks bebas**. `RichText::clean()` membangun ulang HTML dari daftar putih
  (tebal, miring, paragraf, daftar, tautan http/https/mailto/tel); atribut lain dibuang; tautan diberi
  `rel="noopener nofollow"`.
- Embed hanya dari domain yang diizinkan: peta `https://www.google.com/maps/embed?`, video YouTube
  (dimuat sebagai gambar dulu, iframe `youtube-nocookie.com` baru dibuat saat diklik). Lihat `Embeds`.
- Gambar hanya dari folder agensi sendiri (`landing/{tenant_id}/...`); jenis file jpeg/png/webp (tanpa SVG).
- Pratinjau samping memakai iframe `sandbox` tanpa skrip.

## Gambar

Unggahan (maks. 8 MB, di bawah batas nginx 10M) diproses di server oleh `ImageOptimizer`: sisi terpanjang
maks. 1600 px (logo 600 px), orientasi EXIF dirapikan, dikonversi ke WebP bila PHP-GD mendukung. Bila GD tidak
ada atau gambar tak bisa dibaca, berkas asli disimpan. Di halaman publik gambar `loading="lazy"`.
`deploy/install.sh` memasang `php8.3-gd` dan menaikkan `upload_max_filesize` PHP ke 10M (bawaan PHP hanya 2M).

## Halaman publik

CSS satu berkas kecil di dalam halaman, tanpa pustaka JS. Skrip kecil (kalkulator KPR, hitung mundur, putar video)
hanya dimuat bila halaman memakai bagiannya. Formulir lead dan pixel pelacakan memakai partial yang sama untuk semua template.
Bila tidak ada bagian formulir yang tampil, formulir bawaan ditambahkan di bawah supaya lead tidak hilang.

## Tahap B (belum dikerjakan)

Editor seret-dan-lepas ala Elementor untuk paket agensi belum dibuat. Desain tahap A tidak menghalanginya:
editor baru cukup membaca dan menulis format `blocks` di atas (satu `type` baru per widget, atau `version` baru),
dan renderer publik, sanitizer, dan aturan embed tetap dipakai. Calon editor sumber terbuka (mis. GrapesJS atau Puck)
perlu dicek lisensinya dan keluarannya harus tetap berupa JSON `props`, bukan HTML mentah.
