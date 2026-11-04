# Pengaturan per produk

Semua yang hanya berlaku untuk satu produk (atau properti) diatur di halaman produk itu sendiri: menu Produk, klik
produknya. Menu umum (AI Asisten > Pengetahuan AI, dan Follow-up otomatis) hanya berisi yang berlaku untuk semua produk.

## Halaman produk

Empat tab, tab yang aktif ada di URL (`?relation=knowledge`, `followups`, `promo`) sehingga tautan bisa membuka tab
langsung.

| Tab | Isi |
| --- | --- |
| Detail produk | Nama, kategori, harga, stok, berat dan ukuran (toko), rincian tambahan. |
| Pengetahuan AI | Catatan AI khusus produk ini (`ai_knowledge_items.property_id`). Tulis, ubah, nonaktifkan, hapus, dan Unggah dokumen; hasil unggahan otomatis milik produk ini. Badge = jumlah catatan aktif. |
| Follow-up | Rangkaian follow-up khusus produk ini (`follow_up_rules.property_id`), dikelompokkan per tahap dengan nomor langkah (mis. "Order masuk 1/4"), jadwal, aktif, dan terkirim 30 hari. Form sama dengan follow-up umum, dengan pratinjau pesan yang sudah terisi nama produk. "Salin rangkaian" dari follow-up umum atau dari produk lain; salinan selalu NONAKTIF. |
| Iklan & promo | Harga coret, teks promo, pengenal iklan. |

Detail dan Iklan & promo adalah satu form: Simpan di salah satu tab menyimpan keduanya. Daftar produk menampilkan
jumlah Pengetahuan dan jumlah Follow-up aktif per produk, keduanya tautan ke tab masing-masing.

## Rangkaian follow-up mana yang dikirim

Per lead, per tahap (`App\Actions\RunFollowUps`):

- Lead punya produk, dan produk itu punya aturan **aktif** untuk tahap lead (atau untuk semua tahap terbuka): hanya
  rangkaian produk itu.
- Selain itu: rangkaian umum (aturan tanpa produk).
- Tidak pernah keduanya untuk tahap yang sama. Produk dengan aturan hanya di tahap lain tetap memakai rangkaian umum di
  tahap ini.

Produk lead berganti di tengah rangkaian: langkah berikutnya mengikuti rangkaian produk baru, dihitung dari waktu masuk
tahap. Langkah yang jadwalnya (hari, lalu jam) sama atau lebih awal dari langkah yang sudah tercatat di
`follow_up_logs` untuk lead dan tahap itu tidak dikirim, jadi tidak ada kirim mundur dan tidak ada kirim ganda.

## Lainnya

- Hasil belajar AI (AI Asisten > Usulan): saat disetujui, pemilik memilih "Simpan ke" produk mana; bawaannya produk lead
  percakapan itu, kosong = pengetahuan umum.
- Menu Pengetahuan AI umum punya filter "Tampilkan juga pengetahuan produk": item produk tampil baca saja dengan tautan
  "Buka di produk". Menu Follow-up umum punya tombol "Follow-up per produk" berisi tautan ke produk yang punya rangkaian
  sendiri.
- Batas paket follow-up dihitung total (umum dan semua produk), termasuk saat menyalin rangkaian. Pengetahuan AI tidak
  dibatasi paket.
- Ekspor data tenant menyertakan kolom `property_id` di `aturan-follow-up.csv` dan `pengetahuan-ai.csv`.
