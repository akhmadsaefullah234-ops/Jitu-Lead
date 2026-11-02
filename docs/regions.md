# Data wilayah Indonesia (alamat bertingkat)

Formulir dengan kolom **Alamat lengkap** dalam mode **Dropdown wilayah** memakai data wilayah yang disimpan di
database aplikasi sendiri (tabel `regions`), jadi tidak bergantung pada API luar saat dipakai pengunjung.

## Isi data

| Tingkat | Contoh kode | Jumlah (impor Kepmendagri 2025) |
|---|---|---|
| Provinsi | `32` | 38 |
| Kota / kabupaten | `32.04` | 514 |
| Kecamatan | `32.04.05` | 7.285 |
| Kelurahan / desa (+ kode pos) | `32.04.05.2001` | 83.762 |

Kode mengikuti kode wilayah Kementerian Dalam Negeri. Digit pertama empat angka terakhir membedakan kelurahan (`1xxx`)
dan desa (`2xxx`).

## Sumber dan lisensi

- **Kode dan nama wilayah**: [cahyadsn/wilayah](https://github.com/cahyadsn/wilayah), berkas `db/wilayah.sql`,
  sesuai Keputusan Mendagri tentang Pemberian dan Pemutakhiran Kode, Data Wilayah Administrasi Pemerintahan
  (Kepmendagri No. 300.2.2-2138 Tahun 2025 dan pemutakhirannya). Lisensi **MIT**, © Cahya DSN.
- **Kode pos per kelurahan/desa**: [cahyadsn/wilayah_kodepos](https://github.com/cahyadsn/wilayah_kodepos), berkas
  `db/wilayah_kodepos.sql`. Lisensi **MIT**, © Cahya DSN.

Data aslinya diterbitkan pemerintah (Kemendagri); kedua repositori di atas menyusunnya dalam bentuk SQL terbuka. Kode
pos bisa saja tidak cocok untuk sebagian kecil desa, jadi pembeli tetap bisa mengubahnya di formulir.

## Impor dan pembaruan

```sh
php artisan regions:import
```

Perintah ini mengunduh kedua berkas dari alamat di `config/regions.php` (bisa diganti lewat `REGIONS_WILAYAH_URL` dan
`REGIONS_KODEPOS_URL`), lalu mengganti seluruh isi tabel `regions` dalam satu transaksi. Jalankan lagi kapan saja untuk
memperbarui ke data terbaru; semua cache wilayah ikut dibersihkan.

Server tanpa akses internet bisa memakai berkas lokal:

```sh
php artisan regions:import --wilayah=/path/wilayah.sql --kodepos=/path/wilayah_kodepos.sql
php artisan regions:import --wilayah=/path/wilayah.sql --no-kodepos
```

Formulir dan lead menyimpan **kode dan nama** wilayah, bukan id baris, sehingga impor ulang tidak merusak data lama.

## Di mana data ini dipakai

- **Formulir** (`Alamat lengkap`, mode Dropdown wilayah, bawaan template *Order produk*): Provinsi → Kota/Kabupaten →
  Kecamatan → Kelurahan/Desa, masing-masing bisa dicari dengan mengetik. Daftar dimuat bertahap dari
  `GET /wilayah/{kode}` (JSON kecil, di-cache di server dan browser). Server memeriksa bahwa setiap kode ada dan berada
  di bawah pilihan di atasnya. Hasilnya tersimpan di lead: `shipping_address` (alamat lengkap tergabung),
  `shipping_province`, dan `shipping_address_parts` (rincian + kode wilayah).
- **Cek ongkir zona**: kota yang diketik ("Jaksel", "Kab Bdg", nama kecamatan) dicocokkan ke nama resmi dan provinsinya.
  Pengaturan zona dan tombol *Hitung ongkir* di Inbox memberi saran nama kota resmi.
- **Asisten AI toko**: hasil `cek_ongkir` memakai nama resmi tujuan, dan AI diminta menyebut nama resmi itu bila pembeli
  menulis singkatan.
- **Otomatisasi tahap**: provinsi alamat lama yang hanya menyebut kota atau kecamatan ikut dikenali.

Selama data belum diimpor, kolom alamat mode Dropdown wilayah otomatis tampil sebagai isian ketik bebas, dan cek ongkir
memakai daftar kota bawaan seperti sebelumnya.
