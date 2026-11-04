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

## Follow-up umum: dipakai atau tidak

Pengaturan per tenant di bagian atas menu Follow-up otomatis (`tenants.general_follow_ups`,
`App\FollowUps\GeneralFollowUps`):

| Pilihan | Follow-up umum dikirim? |
| --- | --- |
| Otomatis (bawaan) | Ya untuk agensi properti dan toko retail dengan paling banyak 1 produk aktif; tidak untuk toko retail dengan lebih dari 1 produk aktif. Produk aktif = stok bukan "Habis". |
| Selalu dipakai | Ya. |
| Tidak dipakai | Tidak. |

Menu Follow-up menampilkan status yang berlaku dan alasannya, mis. "Tidak dipakai: toko retail dengan 2 produk aktif.
Atur follow-up di halaman tiap produk." Saat tidak dipakai, aturan umum tidak dihapus atau diubah: `RunFollowUps`
hanya melewatinya, dan tabelnya tetap bisa disunting di bawah banner abu-abu. Halaman produk, tab Follow-up,
memberi peringatan "Lead produk ini belum akan menerima follow-up" bila produk aktif itu belum punya langkah aktif.

## Pesan cadangan "Tanya produk"

Berlaku saat follow-up umum tidak dipakai (`App\FollowUps\AskProduct`, `follow_up_rules.kind = ask_product`):

- Satu aturan per tenant, dibuat otomatis: tahap pertama pipeline (retail: Tanya produk), H+1 jam 10:00, aktif. Teks,
  jadwal, dan aktif/nonaktif diatur lewat "Atur pesan" di menu Follow-up; ada tombol "Pakai teks bawaan". Tidak
  dihitung dalam batas paket follow-up.
- Hanya untuk lead tanpa produk, dikirim sekali per lead.
- Variabel `{daftar_produk}`: daftar produk aktif bernomor (maks. 10, urut nama) dengan harga, dan harga coret bila
  ada, mis. `1. CAPRA Pro Raket Rp349.000 (dari ~Rp499.000~)`. Urutannya disimpan di `follow_up_logs.product_ids`.
- Balasan pembeli "2", "no 2", "yang 2 kak" dalam 7 hari memberi lead produk nomor 2 itu (sumber produk: balasan
  pesan tanya produk). Pesan berikutnya yang menyebut nama atau kata kunci produk juga mengisi produk lead yang masih
  kosong.
- Setelah lead punya produk (dari balasan, AI, agen, atau pesan berikutnya), rangkaian produk itu berjalan dari waktu
  masuk tahap tanpa kirim mundur: langkah yang jadwalnya sama atau lebih awal dari pesan tanya produk dilewati.
  Balasan angka itu sendiri tidak dihitung sebagai "lead sudah membalas".

## Produk di formulir terpisah

Formulir (menu Formulir, bagian Pengaturan) punya pilihan "Produk formulir ini" (`lead_forms.property_id`). Lead dari
formulir itu mendapat produk tersebut dengan sumber "formulir". Kolom formulir yang disimpan ke "Produk yang diminati"
didahulukan bila jawabannya cocok dengan nama atau kata kunci produk. Produk yang dipilih agen tidak diganti.

## Lainnya

- Hasil belajar AI (AI Asisten > Usulan): saat disetujui, pemilik memilih "Simpan ke" produk mana; bawaannya produk lead
  percakapan itu, kosong = pengetahuan umum.
- Menu Pengetahuan AI umum punya filter "Tampilkan juga pengetahuan produk": item produk tampil baca saja dengan tautan
  "Buka di produk". Menu Follow-up umum punya tombol "Follow-up per produk" berisi tautan ke produk yang punya rangkaian
  sendiri.
- Batas paket follow-up dihitung total (umum dan semua produk), termasuk saat menyalin rangkaian. Pengetahuan AI tidak
  dibatasi paket.
- Ekspor data tenant menyertakan kolom `property_id` di `aturan-follow-up.csv`, `pengetahuan-ai.csv`, serta
  `kind` dan `product_ids` di file follow-up.
