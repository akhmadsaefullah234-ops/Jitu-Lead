# Audit UI di HP dan desktop

Tujuan: seluruh CRM nyaman dipakai di HP. Tidak ada scroll ke samping, tidak ada elemen yang terpotong atau tertutup,
tidak ada area kosong tanpa isi, dan area sentuh cukup besar. Perbaikan hanya pada tampilan dan tata letak, tidak
mengubah perilaku atau data.

Pekerjaan dipecah menjadi empat PR:

| PR | Isi |
| --- | --- |
| 1 | Hapus tautan "Lihat tutorial" dari header, skrip audit, dokumen ini, konten tertutup atau terpotong (2a), area kosong (2b) |
| 2 | Tabel di HP (mode kartu, filter ringkas) dan Pipeline di HP (2c, 2d) |
| 3 | Inbox dan modal atau formulir (2e, 2f) |
| 4 | Dasbor, konsistensi visual, halaman Tutorial, halaman publik, indikator memuat (2g, 2h, 2i) |

## Cara menjalankan audit

```sh
php artisan db:seed --class=TokoDemoSeeder --force
php artisan db:seed --class=AgensiDemoSeeder --force
cd public && CACHE_STORE=array php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
npm run ui:audit                                   # semua halaman, 320 375 414 768 1280, terang dan gelap
npm run ui:audit -- --only=inbox,pipeline --widths=375 --schemes=light --out=storage/app/ui-audit/cek
```

`CACHE_STORE=array` supaya login berulang tidak kena batas percobaan. Pilihan lain: `--base=` (alamat aplikasi),
`--tenants=toko-demo,agensi-demo`, `--concurrency=4`, `--shots=false` (tanpa tangkapan layar). Hasilnya di
`storage/app/ui-audit/<out>/`: `report.json` (semua temuan per layar), `summary.md` (tabel per halaman), dan
`shots/` (tangkapan layar satu halaman penuh per halaman, akun, lebar, dan mode).

Untuk membandingkan dengan `main`, jalankan salinan `main` (mis. `git worktree add ../jl-main origin/main`, salin
`.env`, `vendor`, dan `public/{css,js,fonts}/filament`) di port lain lalu `npm run ui:audit -- --base=http://127.0.0.1:8001`.

### Yang diperiksa

Halaman (masing-masing di Toko Demo dan Agensi Demo, kecuali yang hanya ada di toko): Dasbor, Pipeline, Lead (daftar,
detail, buat), Inbox (daftar, percakapan terbuka, menu ⋯, detail lead), Tugas, Kiriman, Follow-up otomatis, Halaman
landing (daftar dan builder), Produk/Properti (daftar dan edit), Formulir (daftar dan edit), Impor lead, Impor resi,
Laporan, Laporan harian, Pengaturan (WhatsApp, Mode AI, Pengetahuan AI, Usulan Q&A, Pelacakan iklan, Tim, Langganan,
Alasan gugur, Tahap, Sumber lead, Otomatisasi tahap, Notifikasi, Data dan privasi, Pengiriman), Pustaka media, Tutorial
(daftar dan bab), menu samping di HP, serta halaman publik (beranda, harga, masuk, daftar, halaman landing, formulir).
Otomatisasi tahap dan menu Pengiriman hanya ada di toko.

Ukuran yang diukur di tiap layar:

| Ukuran | Cara mengukur |
| --- | --- |
| Scroll horizontal | `scrollWidth > innerWidth`, diukur dengan `overflow-x: hidden` milik tema dimatikan sebentar, karena aturan itu menyembunyikan scroll tetapi memotong isi yang meluber |
| Meluber | elemen yang keluar dari tepi layar dan tidak berada di dalam wadah yang memang bisa digeser |
| Tertutup | titik tengah elemen (tombol, tautan, kolom, teks) ditimpa elemen lain menurut `elementFromPoint`; diperiksa di puncak halaman (header sticky) dan di akhir halaman (bar bawah, tombol melayang, gelembung Bantuan), setelah widget lazy selesai dimuat |
| Area sentuh kecil | tombol, tautan, dan kolom di bawah 44 x 44 px, hanya di lebar HP dan tablet; tautan di tengah kalimat tidak dihitung |
| Teks kecil | elemen yang memuat teks di bawah 14px |
| Area kosong | panel (berlatar, bergaris, atau berbayang) setinggi minimal 250px yang kosong, atau yang isinya berakhir jauh di atas tepi bawahnya |

Catatan: panel `/app` memakai `darkMode(false)`, jadi tangkapan layar mode gelap di panel sama dengan mode terang.
Halaman publik juga tidak punya tampilan gelap. Audit tetap mengambil keduanya supaya bisa dibandingkan bila mode gelap
diaktifkan nanti.

## Hasil

806 layar per putaran (48 halaman, 2 akun, 5 lebar, terang dan gelap), 12 November 2026. "Sebelum" = `main`
(sebelum PR 1), "sesudah" = setelah PR 1.

| Ukuran | Sebelum | Sesudah PR 1 | Target |
| --- | --- | --- | --- |
| Layar dengan scroll horizontal | 2 (halaman landing publik, 320px, terang dan gelap) | **0** | 0 |
| Elemen meluber dari layar | 4 | **0** | 0 |
| Elemen tertutup | 2 ("Bab berikutnya" di bawah gelembung Bantuan, 1280px) | **0** | 0 |
| Area kosong besar | 34 (Inbox: chat pendek dan panel tanpa chat) | **0** | 0 |
| Area sentuh < 44px, HP dan tablet | 14.132 (rata-rata 21,8 per layar) | 14.132 | mendekati 0, PR 4 |
| Teks < 14px | 14.062 (rata-rata 17,4 per layar) | 14.063 | PR 4 |

Dua temuan PR 1 tidak tertangkap angka di atas dan ditemukan dari tangkapan layar: tab Kiriman yang terpotong (wadah
tab memang bisa digeser, jadi tidak dihitung meluber) dan baris toolbar tabel yang kosong (lebih pendek dari batas
250px). Keduanya diperbaiki.

Area sentuh kecil terbanyak di lebar 375px (satu putaran, terang): tombol ikon aksi baris tabel 32 x 44 px, tautan
kolom tabel setinggi 21-24px, kotak Cari di top bar setinggi 36px, tombol lonceng 36 x 44 dan tombol menu 28 x 44,
aksi tautan tabel ("Ubah", "Hapus") setinggi 20px, kotak centang baris 16 x 16.

## Temuan dan status

Tingkat keparahan: **tinggi** = isi tidak bisa dilihat atau dipakai; **sedang** = bisa dipakai tetapi sulit atau
terlihat rusak; **rendah** = kerapian.

### Diperbaiki di PR 1

| Halaman | Lebar | Masalah | Keparahan | Perbaikan | Tangkapan layar |
| --- | --- | --- | --- | --- | --- |
| Semua halaman fitur | semua | Tautan "Lihat tutorial" menambah isi header di samping tombol aksi | sedang | Dihapus; tutorial lewat menu Tutorial di sidebar | [sebelum](screenshots/ui-audit/pr1/header-inbox-sebelum.png), [sesudah](screenshots/ui-audit/pr1/header-inbox-sesudah.png) |
| Halaman landing dan formulir publik (pilihan RT/RW atau Dusun/kampung) | 320 | Kotak pilihan meluber 16px ke kanan dan terpotong | tinggi | Grid `minmax(0, 1fr)`; di bawah 360px kedua pilihan bertumpuk | [sebelum](screenshots/ui-audit/pr1/formulir-320-sebelum.png), [sesudah](screenshots/ui-audit/pr1/formulir-320-sesudah.png) |
| Kiriman | 320-414 | Tab status (Menunggu resi ... Dibatalkan) terpotong di tepi kanan, tab terakhir tidak terlihat | tinggi | Tab dibungkus ke baris berikutnya di HP | [sebelum](screenshots/ui-audit/pr1/kiriman-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr1/kiriman-hp-sesudah.png) |
| Lead, Kiriman, Tugas, dan semua tabel | semua | Baris toolbar tabel kosong setinggi tombol di atas kotak Cari (tempat tombol aksi massal yang baru muncul setelah baris dicentang) | sedang | Baris disembunyikan selama isinya tersembunyi | sama dengan Kiriman |
| Bab Tutorial dan halaman panjang lain | 1280 | Gelembung "Bantuan" menutupi baris terakhir halaman ("Bab berikutnya") | tinggi | Ruang 5rem di bawah konten di desktop | [sebelum](screenshots/ui-audit/pr1/tutorial-bawah-sebelum.png), [sesudah](screenshots/ui-audit/pr1/tutorial-bawah-sesudah.png) |
| Inbox, percakapan terbuka | 375-768 | Chat pendek menempel di atas, menyisakan area kosong 300-600px di atas kolom ketik | sedang | Pesan diletakkan tepat di atas kolom ketik, seperti WhatsApp | [sebelum](screenshots/ui-audit/pr1/inbox-chat-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr1/inbox-chat-hp-sesudah.png) |
| Inbox, belum ada chat dipilih | 1280 | Panel kanan setinggi layar hanya berisi satu baris di atas | sedang | Keadaan kosong di tengah panel: ikon, judul, satu kalimat | [sebelum](screenshots/ui-audit/pr1/inbox-kosong-sebelum.png), [sesudah](screenshots/ui-audit/pr1/inbox-kosong-sesudah.png) |
| Inbox, kolom ketik saat keyboard muncul | HP | Tinggi chat memakai `100dvh`; di iOS Safari tinggi itu tidak menyusut saat keyboard muncul, kolom ketik bisa tertutup keyboard | tinggi | Tinggi chat memakai `--jl-vvh` (tinggi `visualViewport`); kolom yang sedang diisi digulir ke dalam layar saat keyboard muncul | - |
| Modal (sheet bawah di HP), chat Bantuan, popup paket | HP | Tinggi maksimum memakai `vh`, yang tidak memperhitungkan bar browser dan keyboard | sedang | `dvh` / `--jl-vvh`; isi modal yang lebih tinggi digulir di dalam jendela modal | - |
| Sidebar di HP | HP | Diperiksa: sidebar menutup sendiri setelah menu dipilih (bawaan Filament, `$store.sidebar.close()` pada lebar 1024px ke bawah) | - | Tidak perlu diubah | - |

### Belum dikerjakan (PR berikutnya)

| Halaman | Lebar | Masalah | Keparahan | PR |
| --- | --- | --- | --- | --- |
| Lead, Kiriman, Tugas | 320-414 | Kartu tabel memuat semua kolom (Kiriman sampai 14 baris per kartu), belum ada "Lihat detail"; pencarian, filter, dan "Urutkan menurut" memakan tiga baris | sedang | 2 |
| Lead | 320-414 | Empat tombol header (Impor resi, Ekspor CSV, Pengaturan agensi, Buat lead) turun ke dua baris | sedang | 2 |
| Pipeline | 320-414 | Saringan cepat, pilihan agen, periode, dan produk mengisi setengah layar sebelum chip tahap dan kartu | sedang | 2 |
| Inbox, header chat | 320-414 | Chip tahap dan produk terpotong menjadi "Tanya pr…", "Tas Rak…"; tooltip "Aksi lain" tetap tampil menutupi avatar setelah ⋯ disentuh | sedang | 3 |
| Modal di HP | HP | Tombol aksi modal belum menempel di bawah (sticky footer) untuk formulir panjang | sedang | 3 |
| Semua halaman panel | HP dan tablet | Area sentuh di bawah 44px: tombol ikon aksi baris (32 x 44), aksi tautan tabel (tinggi 20px), tombol menu dan lonceng di top bar (28-36px), kotak centang (16px), kotak Cari (tinggi 36px) | tinggi | 4 |
| Semua halaman | semua | Teks di bawah 14px: lencana (12px), keterangan kartu angka Dasbor (10.9px), lencana "Admin" di Tutorial (9.9px), baris "Menu:" di Tutorial (12px) | sedang | 4 |
| Dasbor | 320-414 | Angka besar ("Rp 1.074.000", "16.7% → 250%") pecah ke beberapa baris di kartu dua kolom | sedang | 4 |
| Tutorial | semua | Gambar bab masih menampilkan tautan "Lihat tutorial" yang sudah dihapus; perlu `php artisan tutorial:capture` setelah perubahan tata letak PR 2-4 selesai | sedang | 4 |
| Top bar | semua | Avatar memakai ui-avatars.com; bila layanan itu tidak bisa dijangkau tampil gambar rusak dengan teks "Ava" | rendah | 4 |
