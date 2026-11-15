# Alat AI untuk agensi properti

Hanya untuk agensi dengan industri `properti`. Kode: `App\Ai\PropertyTools`, `App\FollowUps\SurveyReminder`,
`App\Actions\MoveLeadToStage::reschedule`, `App\Actions\GenerateAiReply`.

## simpan_kebutuhan

Menyimpan kebutuhan yang disebut klien ke lead:

| Input | Kolom lead |
| --- | --- |
| `tujuan` (hunian, investasi, villa, sewa) | `purpose` (kolom baru). `need` ikut: sewa menjadi Sewa, selain itu Beli |
| `domisili` | `domicile` (kolom baru) |
| `budget_min`, `budget_max` (rupiah penuh, 1 juta sampai 1 triliun) | `budget_min`, `budget_max` |
| `cara_bayar` (kpr, tunai_keras, tunai_bertahap) | `payment_method` |
| `tipe` (Rumah, Apartemen, Ruko, Tanah, Kavling, Villa) | `property_type` |
| `lokasi` | `location` |

- Nilai yang sudah terisi tidak ditimpa, kecuali `koreksi = true`, yaitu saat klien mengoreksi datanya sendiri.
  Alat memberi tahu model nilai mana yang tidak ditimpa.
- Isian Tujuan dan Domisili tampil di form lead (bagian Kebutuhan properti) dan ikut di ekspor CSV.

## jadwalkan_survei

Input `tanggal` (YYYY-MM-DD), `jam` (HH:MM), dan `lokasi`, dalam zona waktu agensi (`tenants.timezone`).

**Validasi.** Sama seperti tahap Jadwal survei (`MoveLeadToStage::survey`): jadwal harus setelah sekarang dan lokasi
wajib diisi. Hasil yang ditolak memuat `masalah`, lalu model diminta menjelaskan dengan ramah dan bertanya ulang.

**Ditolak juga bila:**
- tiga pesan klien terakhir tidak menyebut hari atau jam (besok, Sabtu, jam 10, 21/11, dan sejenisnya). Tujuannya
  agar AI tidak menjadwalkan sendiri;
- lead sudah melewati tahap survei (Sudah survei, booking, closing, gugur);
- pipeline tidak punya tahap survei. Dalam kasus ini alatnya bahkan tidak ditawarkan.

**Satu jadwal per lead.**
- Lead yang belum di tahap survei dipindahkan ke tahap survei pertama, dengan aktivitas "Pindah otomatis".
- Lead yang sudah di sana jadwalnya diubah lewat `MoveLeadToStage::reschedule`. Ini mencatat aktivitas
  `survey_changed`, dan tenggat Konfirmasi H-1 mengikuti tanggal baru.

**Konfirmasi ke klien.**
- Hasil alat memuat nama, hari dan tanggal, jam (mis. "10.00 WITA"), dan lokasi.
- Bila jawaban tidak menyebut jam dan tanggal itu, model diminta menulis ulang sekali.
- Bila masih tidak menyebutnya, aplikasi menambahkan baris "Konfirmasi jadwal survei ...".

## Kapan data ditulis

Alat hanya memeriksa input dan memberi tahu model apa yang akan dicatat. Lead baru ditulis saat balasan benar-benar
terkirim, lewat `GenerateAiReply::applyActions`:
- **Mode Auto:** langsung setelah terkirim.
- **Mode Draf:** saat agen mengirim saran itu dari Inbox. Inbox menampilkan "Bila dikirim, survei dijadwalkan ...".

Aktivitas lead yang dicatat: `ai_needs` dan `ai_survey`. Keduanya dipakai ringkasan harian di PR 5.

## Pengingat survei H-1

- **Cara kerja.** Aturan follow-up dengan `kind = survey_reminder` (`SurveyReminder`), dijalankan `RunFollowUps`
  setiap menit seperti follow-up lain, dengan teks yang bisa diubah agensi. Variabel baru di semua template:
  `{tanggal_survei}`, `{jam_survei}`, `{lokasi_survei}`.
- **Pengaturan.** Diatur di Follow-up otomatis, kotak "Pengingat survei H-1" (tombol Atur pengingat).
- **Bawaan nonaktif** untuk semua agensi, seperti follow-up bawaan. Tidak ada pesan baru ke klien sampai agensi
  menyalakannya.
- **Waktu kirim.** Sehari sebelum survei, pada jam kirim di zona waktu agensi. Pengingat yang terlambat masih dikirim
  sampai 24 jam setelahnya, selama survei belum lewat.
- **Sekali per jadwal.** `follow_up_logs.survey_at` mencatat jadwal yang sudah diingatkan, jadi jadwal yang diubah
  mendapat pengingat baru. Indeks unik `follow_up_logs_once` menggantikan unique lama dan menambahkan `survey_at`.
- **Tidak dikirim bila:**
  - survei dibatalkan (tanggalnya kosong);
  - lead keluar dari tahap survei;
  - jadwal dibuat setelah jam pengingat lewat (`leads.survey_scheduled_at`), karena klien baru saja dikonfirmasi.
- **Balasan klien.** Pengingat tetap dikirim walaupun klien sudah membalas, berbeda dari follow-up biasa.
- **Kuota paket.** Pengingat tidak dihitung ke batas jumlah follow-up paket.
