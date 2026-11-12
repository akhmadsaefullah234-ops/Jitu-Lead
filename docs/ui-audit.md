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
| Area sentuh kecil | tombol, tautan, dan kolom di bawah 44 x 44 px, hanya di lebar HP dan tablet; tautan di tengah kalimat tidak dihitung. Sejak PR 4 sel tabel yang membuka data barisnya (`.fi-ta-col`) diukur sebagai seluruh baris atau kartu: sel-sel itu bersambung dan semuanya membuka data yang sama |
| Teks kecil | elemen yang memuat teks di bawah 14px; ringkasan juga menghitung teks di bawah 12px (batas untuk keterangan dan lencana). Label yang sengaja disembunyikan dengan `font-size: 0` (tetap terbaca pembaca layar) tidak dihitung |
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

### Putaran PR 2

806 layar, 12 November 2026. "Sebelum" = `main` setelah PR 1, "sesudah" = setelah PR 2.

| Ukuran | Sebelum PR 2 | Sesudah PR 2 | Target |
| --- | --- | --- | --- |
| Layar dengan scroll horizontal | 0 | **0** | 0 |
| Elemen meluber dari layar | 0 | **0** | 0 |
| Elemen tertutup | 0 | **0** | 0 |
| Area kosong besar | 0 | **0** | 0 |
| Area sentuh < 44px, HP dan tablet | 14.232 | 13.804 | mendekati 0, PR 4 |
| Teks < 14px | 14.046 | 15.252 | PR 4 |

Area sentuh kecil turun 428, terbanyak di daftar Lead (-362): kolom sekunder pindah ke balik "Lihat detail" dan chip
Pipeline setinggi 44px. Kenaikan teks kecil seluruhnya dari Pipeline di HP (+1.206): sebelumnya hanya kolom tahap yang
aktif yang ditampilkan, sekarang semua kolom ada berdampingan di area geser dan ikut dihitung walau berada di luar
layar. Jumlahnya sama dengan Pipeline di desktop (140 per layar); ukuran huruf kartu ditangani di PR 4.

### Putaran PR 3

806 layar, 12 November 2026. "Sebelum" = `main` setelah PR 2, "sesudah" = setelah PR 3. Scroll horizontal, meluber,
tertutup, dan area kosong tetap 0. Area sentuh kecil 13.808 menjadi 13.772: tombol kembali di header chat Inbox kini
44 x 44 (-12 di tiap halaman Inbox yang membuka chat). Teks kecil tetap 15.270. Baris tombol yang menempel di modal
dan di halaman buat/ubah tidak terukur oleh audit (audit tidak membuka modal panjang dan tidak menggulir formulir);
hasilnya terlihat di tangkapan layar.

### Putaran PR 4

806 layar, 12 November 2026. "Sebelum" = `main` setelah PR 3 dan "sesudah" = setelah PR 4, keduanya diukur dengan
skrip audit versi PR 4 (aturan sel tabel dan hitungan teks di bawah 12px, lihat "Yang diperiksa").

| Ukuran | Sebelum PR 4 | Sesudah PR 4 | Target |
| --- | --- | --- | --- |
| Layar dengan scroll horizontal | 0 | **0** | 0 |
| Elemen meluber dari layar | 0 | **0** | 0 |
| Elemen tertutup | 0 | **0** | 0 |
| Area kosong besar | 0 | **0** | 0 |
| Area sentuh < 44px, HP dan tablet | 11.818 | **1.896** | mendekati 0 |
| Teks < 12px | 5.938 | **1.508** | 0 di panel |
| Teks < 14px | 15.252 | 14.398 | - |

Sisa area sentuh kecil: kotak centang baris dan sakelar Filament, tombol hapus di kotak pilihan, tombol urut kolom dan
breadcrumb di tablet, serta beberapa tautan di dalam teks (lihat "Sisa temuan"). Teks di bawah 12px yang tersisa
seluruhnya ada di gambar contoh aplikasi pada beranda dan halaman harga publik (1.488) dan di navigasi bab Tutorial
(20, sudah diperbaiki setelah putaran ini; diperiksa ulang: 0). Teks 12-13px yang tersisa adalah keterangan dan lencana.

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

### Diperbaiki di PR 2

| Halaman | Lebar | Masalah | Keparahan | Perbaikan | Tangkapan layar |
| --- | --- | --- | --- | --- | --- |
| Pipeline | 320-414 | Saringan cepat, pilihan agen, periode, dan produk mengisi setengah layar sebelum chip tahap dan kartu | sedang | Satu baris: kotak Cari dan tombol "Filter" (dengan jumlah saringan aktif) yang membuka panel saringan | [sebelum](screenshots/ui-audit/pr2/pipeline-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr2/pipeline-hp-sesudah.png) |
| Pipeline | 320-414 | Chip tahap memenuhi empat baris; pindah tahap hanya lewat chip | sedang | Chip tahap satu baris yang bisa digeser; satu kolom tahap per layar, bisa digeser ke kiri dan kanan, chip mengikuti kolom yang tampil | [sebelum](screenshots/ui-audit/pr2/pipeline-geser-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr2/pipeline-geser-hp-sesudah.png) |
| Lead | 320-414 | Kartu memuat semua kolom (agen, aksi berikutnya, sumber, resi, waktu masuk) | sedang | Kartu menampilkan nama, nomor, tahap, minat, tenggat; sisanya di balik "Lihat detail" / "Tutup detail" | [sebelum](screenshots/ui-audit/pr2/lead-kartu-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr2/lead-detail-hp-sesudah.png) |
| Kiriman | 320-414 | Kartu sampai 14 baris (kurir, biaya, lokasi, waktu lacak, penjemputan, waktu cetak) | sedang | Kolom tersebut di balik "Lihat detail" | [sebelum](screenshots/ui-audit/pr2/kiriman-kartu-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr2/kiriman-kartu-hp-sesudah.png) |
| Semua tabel | HP | Panel filter membuka ke bawah di tengah halaman dan tertutup tombol + | sedang | Tombol bertulisan "Filter"; di HP panel filter tampil sebagai lembar dari bawah layar dengan latar gelap, isinya digulir di dalam lembar | [sebelum](screenshots/ui-audit/pr2/filter-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr2/filter-hp-sesudah.png) |
| Tugas | 320-414 | Diperiksa: kartu hanya tiga baris | - | Tidak perlu "Lihat detail" | - |

### Diperbaiki di PR 3

| Halaman | Lebar | Masalah | Keparahan | Perbaikan | Tangkapan layar |
| --- | --- | --- | --- | --- | --- |
| Inbox, header chat | 320-414 | Nama, chip tahap, dan chip produk berebut satu baris: terpotong menjadi "Budi C…", "Tanya …", "Tas Rak…" | sedang | Di HP baris pertama berisi kembali, nama, dan ⋯; chip tahap dan produk di baris kedua dengan teks 12px. Tombol kembali 44 x 44 | [sebelum](screenshots/ui-audit/pr3/inbox-header-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr3/inbox-header-hp-sesudah.png) |
| Inbox, tombol ⋯ (dan tombol ikon lain) | HP | Tooltip "Aksi lain" ikut muncul saat tombol disentuh dan tetap tampil menutupi avatar | rendah | Di layar sentuh tooltip tombol tidak dibuka oleh sentuhan; label tetap terbaca pembaca layar (`aria-label`). Skrip di `theme.blade.php` | - |
| Modal panjang (mis. Buat resi di Inbox) | HP | Tombol Batal dan aksi utama ada di ujung formulir; formulir Buat resi setinggi 2.700px, tombol baru terlihat setelah digulir sampai habis. Tombol bertumpuk tiga baris | sedang | Baris tombol menempel di bawah jendela modal; tombol berdampingan (aksi utama di kanan) dan turun baris bila tidak muat | [sebelum](screenshots/ui-audit/pr3/modal-resi-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr3/modal-resi-hp-sesudah.png) |
| Halaman buat dan ubah (Lead, Properti/Produk, Formulir, pengaturan) | semua, utamanya HP | Tombol Simpan/Buat dan Batal hanya ada di bawah formulir panjang | sedang | `BasePage::stickyFormActions()`: selama tombol asli di luar layar, tombol yang sama melayang di bawah. Di bawah 1024px bar itu berada di atas tab bar dan tombol + disembunyikan | [sebelum](screenshots/ui-audit/pr3/lead-buat-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr3/lead-buat-hp-sesudah.png) |

### Diperbaiki di PR 4

| Halaman | Lebar | Masalah | Keparahan | Perbaikan | Tangkapan layar |
| --- | --- | --- | --- | --- | --- |
| Dasbor | 320-414 | Angka besar pecah ke beberapa baris ("1.5 / menit", "Rp / 1.074.000", "16.7% → / 250%") | sedang | Angka satu baris dengan ukuran yang menyesuaikan lebar kartu; Nilai penjualan dan Konversi mengisi satu baris penuh (`.jl-stat-wide`) | [sebelum](screenshots/ui-audit/pr4/dasbor-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr4/dasbor-hp-sesudah.png) |
| Lead (dan halaman lain dengan tiga tombol header atau lebih) | 320-640 | Tombol header turun ke dua baris; tiga tombol abu-abu sejajar dengan "Buat lead" | sedang | Satu baris: tombol utama berlabel mengisi baris, tombol abu-abu hanya ikon (label tetap ada untuk pembaca layar). Dua tombol tetap berlabel | [sebelum](screenshots/ui-audit/pr4/lead-header-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr4/lead-header-hp-sesudah.png) |
| Semua tabel | semua | Lencana tombol Filter menampilkan "0" saat tidak ada saringan | rendah | Lencana disembunyikan saat 0 (`data-active-filters`) | sama dengan Lead |
| Semua halaman panel | HP dan tablet | Area sentuh di bawah 44px: tombol ikon aksi baris, tautan aksi tabel ("Ubah", "Hapus"), tombol menu dan lonceng, kotak isian dan pilihan, tab, item dropdown dan sidebar, tautan kartu Pipeline, pil status Dasbor, kotak cari dan daftar isi Tutorial | tinggi | Minimal 44 x 44 px di bawah 1024px (`responsive.css` dan CSS halaman masing-masing); kotak centang baris 22px | [sebelum](screenshots/ui-audit/pr4/tim-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr4/tim-hp-sesudah.png) |
| Semua halaman panel | semua | Teks di bawah 12px: label tab bar (10.9px), lencana "Admin" Tutorial (9.9px), produk di daftar Inbox (10.9px), tag kurir Pipeline (11px), persen corong Dasbor (10.4px), label grup sidebar (11px) | sedang | Minimal 12px untuk keterangan dan lencana; tautan dan chip yang disentuh 14px | - |
| Top bar dan menu agensi | semua | Avatar dari ui-avatars.com; tanpa jaringan ke layanan itu tampil gambar rusak "Ava" | rendah | Inisial digambar sendiri sebagai SVG dengan warna utama panel (`InitialsAvatarProvider`) | semua tangkapan layar sesudah |
| Tutorial | semua | Gambar bab masih menampilkan tautan "Lihat tutorial" dan tata letak lama | sedang | 160 gambar diambil ulang (`php artisan tutorial:capture`) | [sebelum](screenshots/ui-audit/pr4/tutorial-bab-hp-sebelum.png), [sesudah](screenshots/ui-audit/pr4/tutorial-bab-hp-sesudah.png) |
| Halaman publik (beranda, harga, daftar, masuk, formulir, landing) | 320-1280 | Diperiksa: tidak ada scroll samping, elemen tertutup, atau area kosong | - | Tidak diubah | - |

Konsistensi: aturan baru memakai token Filament (`--gray-*`, `--primary-*`, `--color-white`) dan satuan rem. Tidak
ada warna hex baru selain putih teks pada avatar SVG.

### Sisa temuan setelah PR 4

| Halaman | Lebar | Masalah | Keparahan | Catatan |
| --- | --- | --- | --- | --- |
| Tabel | HP dan tablet | Kotak centang baris 22px, tombol hapus pilihan di kotak pilihan (16px), sakelar (44 x 24) | rendah | Kontrol bawaan Filament; ukurannya bagian dari bentuknya. Untuk kotak centang, seluruh kartu tetap bisa disentuh untuk membuka data |
| Tabel di tablet | 768 | Tombol urut di judul kolom dan breadcrumb di bawah 44px tingginya | rendah | Hanya di tablet, saat tabel tampil sebagai tabel (bukan kartu) |
| Lead, Kiriman, Tugas | 320-414 | Kotak Cari dan "Urutkan menurut" tetap dua baris di atas kartu | rendah | Disengaja: tanpa judul kolom, "Urutkan menurut" satu-satunya cara mengurutkan di HP |
| Halaman publik beranda dan harga | semua | Teks di bawah 12px di dalam gambar contoh aplikasi (tiruan layar Pipeline) | - | Ilustrasi, bukan teks untuk dibaca; dibiarkan |
| Semua halaman | semua | Lencana dan keterangan 12px masih dihitung di "Teks < 14px" | - | 12px adalah ukuran keterangan Filament; teks isi dan semua yang disentuh 14px atau lebih |
