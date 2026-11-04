# Periode laporan dan Dasbor

Dasbor, menu Lead, Pipeline, dan Laporan memakai satu pemilih periode yang sama (`App\Support\Period::fields()`):
satu dropdown **Hari ini, Kemarin, 7 hari terakhir, 30 hari terakhir, Bulan ini, Bulan lalu, Kustom…** (penuh di HP,
±220px di desktop) dengan satu baris kecil abu-abu berisi rentang tanggalnya; di Dasbor ditambah pembandingnya, mis.
"4 Nov 2026 · dibanding 3 Nov". Tanggal Dari/Sampai (paling panjang 366 hari) muncul hanya saat Kustom dipilih. Lead
dan Pipeline juga punya **Semua waktu**.

Di Dasbor, pesan "Lengkapi nomor WhatsApp" dan banner tampil paling atas (header widget), di atas pemilih periode.

## Aturan periode

- Hari dihitung di zona waktu agensi (`tenants.timezone`, bawaan Asia/Jakarta): 00:00 sampai 23:59:59 waktu setempat.
  Lead yang masuk 23:30 WIB termasuk "Hari ini", bukan besok.
- "7 hari terakhir" = hari ini dan 6 hari sebelumnya; "30 hari terakhir" sama dengan 29 hari sebelumnya.
- Tanggal kustom yang terbalik ditukar. Lebih dari 366 hari atau tanggal tidak valid: kembali ke bawaan halaman.
- Pembanding (persentase naik/turun di Dasbor): periode sebelumnya dengan panjang yang sama. Bulan dibanding bulan:
  "Bulan lalu" dibanding bulan sebelumnya, "Bulan ini" (1 sampai hari ini) dibanding tanggal yang sama bulan lalu.
- Pilihan terakhir diingat per pengguna per halaman (session). Tautan menang atas pilihan yang diingat.

| Halaman | Bawaan | Tautan |
| --- | --- | --- |
| Dasbor | 7 hari terakhir | `?periode=30d` atau `?dari=2026-11-01&sampai=2026-11-04` |
| Lead | Semua waktu | `?filters[masuk][periode]=7d`, `?filters[produk][value]=5`, `?filters[sumber][value]=wa_ads` |
| Pipeline | Semua waktu | `?periode=7d&dasar=tahap&produk=5` |
| Laporan | 30 hari terakhir | `?periode=this_month` |

Kunci periode: `today`, `yesterday`, `7d`, `30d`, `this_month`, `last_month`, `custom`.

## Dasbor

Semua angka hanya untuk agensi yang sedang dibuka dan mengikuti hak lihat: agen yang hanya melihat lead miliknya
juga hanya melihat angka lead miliknya. Kode: `App\Reports\DashboardData` (query agregat, cache 60 detik per agensi,
pengguna, dan periode), widget di `App\Filament\Widgets\Dashboard`.

| Bagian | Isi |
| --- | --- |
| Kartu ringkas | Lead baru, Lead dari iklan (Click to WhatsApp: `ad_id` atau chat dibuka dari iklan; formulir dengan UTM kampanye), Lead Hot, rata-rata waktu balas pertama (pesan masuk pertama sampai balasan keluar pertama, agen atau AI). |
| Toko (retail) | Order masuk, Dibayar / dikirim (masuk tahap Dikemas & dikirim), Selesai, Batal, Nilai penjualan dan rata-rata nilai order, Konversi lead → order → dibayar. |
| Properti | Survei terjadwal (`survey_at` di periode) dan terlaksana (masuk tahap setelah survei), Booking, Akad / closing dan nilainya. |
| Corong | Lead yang masuk pada periode, menurut tahap terjauh yang pernah dicapai. |
| Per produk | Lead, Hot, order, dibayar, selesai, nilai, konversi (dibayar ÷ lead). Klik baris membuka daftar Lead dengan filter produk dan periode yang sama. |
| Per iklan | 10 iklan teratas (`ad_title`, atau `ad_id`), plus satu baris "Tanpa data iklan / organik". |
| Sumber lead | WhatsApp dari iklan, WhatsApp organik, Formulir / landing, Impor / manual / lainnya (`App\Reports\LeadOrigin`). |
| AI & otomatis | Balasan AI terkirim, draf menunggu, diserahkan ke admin, voice note belum dibalas, follow-up terkirim/dilewati, sambutan terkirim/dilewati/gagal, "Tanya produk" terkirim dan dijawab, event konversi ke Meta terkirim/gagal. |
| Per agen | Hanya untuk yang melihat seluruh tim dan bila pengguna aktif lebih dari satu: lead, order (properti: booking), closing, waktu balas. |
| Status saat ini | Tidak mengikuti periode: koneksi tiap nomor WhatsApp, tugas terlambat, lead belum ditangani. |

Definisi:

- **Order / dibayar / closing pada periode** = lead yang *masuk* tahap itu pada periode (aktivitas `stage_changed`,
  atau `stage_entered_at` untuk tahap sekarang), apa pun tanggal lead-nya masuk.
- **Nilai penjualan** = nilai lead yang dibayar pada periode, tanpa ongkir: `deal_value` bila diisi, kalau tidak
  jumlah baris pesanan (`order_items` harga x jumlah), kalau tidak harga produk x jumlah.
- **Voice note belum dibalas** = voice note masuk pada periode yang belum diikuti pesan keluar.

## Menu Lead

Filter: **Tanggal masuk** (periode), **Produk** (termasuk "Tanpa produk"), **Asal lead** (empat kelompok di atas),
**Iklan**, dan **Masuk tahap** (tahap + periode, memakai riwayat pindah tahap). Filter aktif tampil sebagai indikator
dengan tombol reset, tersimpan di URL, dan Ekspor CSV mengikuti filter.

## Pipeline

Pemilih periode di atas papan, berdasarkan **Tanggal lead masuk** (bawaan) atau **Tanggal masuk tahap**, plus filter
produk. Jumlah kartu dan nilai per kolom ikut periode; nilai toko memakai nilai order, properti memakai `deal_value`.
