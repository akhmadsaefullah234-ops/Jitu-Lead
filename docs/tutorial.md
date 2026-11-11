# Tutorial di dalam aplikasi

Menu **Tutorial** (ikon buku, untuk semua peran) berisi panduan per fitur dengan tangkapan layar langkah demi
langkah. Isinya berkas di repo, bukan database:

- `resources/tutorials/{slug}.php`: satu berkas per bab.
- `public/tutorial/{slug}/{nomor}.webp` (dan `{nomor}-hp.webp` untuk tampilan HP): gambar tiap langkah.
- `app/Tutorials/Chapter.php` dan `app/Tutorials/Tutorials.php`: membaca bab, menyaring per jenis usaha dan peran,
  pencarian, bab sebelumnya dan berikutnya.
- `app/Filament/Pages/Tutorial.php` dan `resources/views/filament/pages/tutorial.blade.php`: halaman daftar dan bab
  (`?bab={slug}`, `?cari=...`).
- Tautan **Lihat tutorial** di header halaman terkait dipasang lewat render hook di `AppPanelProvider`; bab memilih
  halamannya lewat kunci `pages`. Bila beberapa bab yang terlihat memakai halaman yang sama, tautan menuju bab pertama dalam
  urutan baca (grup, lalu `order`, lalu judul). Karena itu bab toko dan bab properti untuk halaman yang sama (mis.
  `pipeline` dan `pipeline-properti`) masing-masing diberi `industries` sendiri.

## Format bab

```php
<?php

use App\Filament\Pages\Inbox;

return [
    'title' => 'Inbox',
    'group' => 'percakapan',      // mulai, lead, percakapan, penjualan, properti, pengiriman, iklan, akun
    'order' => 20,                // urutan di dalam grup
    'industries' => ['retail'],   // kunci config/industries.php; tanpa kunci ini = semua jenis usaha (bab umum)
    'admin' => true,              // hanya admin (lencana "Admin"), atau:
    'leader' => true,             // admin dan team leader (halaman yang tidak bisa dibuka agen)
    'menu' => 'Inbox',            // baris "Menu:" di atas bab
    'intro' => '<p>...</p>',
    'pages' => [Inbox::class],    // halaman atau resource Filament yang menampilkan "Lihat tutorial" ke bab ini
    'steps' => [
        [
            'text' => 'Pilih percakapan di daftar kiri ...',
            'alt' => 'Daftar percakapan Inbox ...',   // teks alt gambar; bawaan = teks langkah
            'shot' => [...],                         // tanpa shot = langkah tanpa gambar
        ],
    ],
    'tips' => ['...'],
    'perhatian' => ['...'],
    'penting' => ['...'],
];
```

Teks boleh memuat HTML sederhana; semuanya dibersihkan lewat `App\LandingPages\RichText` (daftar putih: b, strong,
i, em, p, ul, ol, li, br, a). `{katalog}` dan `{item}` diganti istilah akun (`Industry::term`: Produk/produk atau
Properti/properti), jadi bab umum cocok untuk toko dan properti.

Jangan menulis nama orang, nomor HP, atau alamat sungguhan di berkas bab. Tes `TutorialTest` menolak nomor HP selain
awalan demo 0899000 / +62899000.

### Kunci `shot`

| Kunci | Isi |
| --- | --- |
| `page` | Alamat setelah `/app/{akun}`, mis. `inbox` atau `lead-forms/create`. Kosong = Dasbor. Diawali `/` = alamat lengkap (mis. formulir publik `/f/toko-demo/order-tas-raket`). |
| `steps` | Langkah sebelum memotret, berurutan: `['click' => sel]`, `['fill' => [sel, teks]]`, `['select' => [sel, nilai]]`, `['press' => [sel, tombol]]`, `['hover' => sel]`, `['scroll' => sel]`, `['wait' => ms]`, `['waitFor' => sel]`. |
| `highlight` | Selector Playwright yang diberi kotak merah bernomor 1, 2, ... Elemen pertama menentukan posisi gulir. |
| `mobile` | `true`: ambil juga tampilan HP (390 x 844) sebagai `{nomor}-hp.webp`. |
| `mobile_steps`, `mobile_highlight` | Pengganti `steps` dan `highlight` untuk tampilan HP. |
| `tenant` | `toko-demo` atau `agensi-demo`. Bawaan: `agensi-demo` untuk bab khusus properti, selain itu `toko-demo`. |
| `as` | `admin` (bawaan), `manager`, `agent`, atau `agent2`. |
| `viewport` | Ukuran desktop lain; bawaan 1280 x 800. |

Selector hanya mencocokkan elemen yang terlihat, jadi isian di tab lain atau menu yang tertutup tidak ikut.

## Data demo

Gambar selalu diambil dari dua akun fiktif, tidak pernah dari data produksi:

- **Toko Demo** (`toko-demo`, retail): `Database\Seeders\TokoDemoSeeder`.
- **Agensi Demo** (`agensi-demo`, properti): `Database\Seeders\AgensiDemoSeeder`.

Keduanya turunan `Database\Seeders\Demo\DemoSeeder`, yang menolak jalan di luar `APP_ENV=local` atau `testing`.
Menjalankan ulang seeder menghapus akun demo lama lalu membuatnya lagi. Login: `admin@toko-demo.test`,
`manager@...`, `agent@...`, `agent2@...` (dan sama untuk `@agensi-demo.test`), kata sandi `password`. Nomor HP
memakai awalan 0899000, resi dan alamat fiktif, kunci Everpro dan token Meta berisi nilai palsu.

## Mengambil ulang gambar

Butuh Node dan Playwright dengan Chromium (`npm i -D playwright && npx playwright install chromium`, atau Playwright
global), serta PHP dengan GD WebP.

```sh
php artisan tutorial:capture                 # semua bab: seed ulang dua akun demo, jalankan server, potret
php artisan tutorial:capture inbox pipeline  # hanya bab ini
php artisan tutorial:capture --no-seed       # pakai data demo yang sudah ada
php artisan tutorial:capture --url=http://127.0.0.1:8000   # pakai server yang sudah berjalan
```

Perintah ini hanya jalan di `APP_ENV=local` atau `testing`. Ia menulis manifest, menjalankan server PHP di port 8123
(`--port`) dengan cache array dan `TUTORIAL_CAPTURE=1`, lalu `node scripts/tutorial-capture.mjs` memotret tiap langkah. Setiap gambar diambil
dengan login baru, karena periode dan filter yang dipilih satu gambar tersimpan di sesi. Bila memakai `--url`,
jalankan server itu dengan `CACHE_STORE=array` dan `TUTORIAL_CAPTURE=1` agar login berulang tidak kena batas percobaan login. PNG diubah ke
WebP (kualitas 78) di `public/tutorial/`. Langkah yang gagal ditulis `FAILED slug/nomor: alasan` dan perintah
keluar dengan status gagal.

Dengan `TUTORIAL_CAPTURE=1` (hanya di local/testing) aplikasi tidak menghubungi layanan luar mana pun:
`App\Tutorials\CaptureFakes` menjawab Everpro dengan saldo, lokasi, dan tarif rekaan (agar jendela Buat resi dan
pengaturan Pengiriman tampil seperti akun yang tersambung), dan semua panggilan keluar lain dijawab 503. Bila memakai
`--url`, jalankan server itu dengan variabel yang sama. Halaman Notifikasi diberi kunci VAPID tiruan bila belum ada,
tanpa pernah mendaftarkan perangkat.

Selalu periksa gambar baru sebelum commit: kotak harus jatuh di elemen yang dimaksud dan tidak ada data selain data
demo.
