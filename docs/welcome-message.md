# Pesan sambutan WhatsApp untuk lead formulir

Pembeli yang mengisi formulir (langsung di `/f/{agensi}/{formulir}` atau di landing page) langsung disapa di WhatsApp
oleh JITU LEAD, walaupun pembeli tidak lanjut ke chat atau menghapus pesannya. Balasan pembeli masuk ke percakapan
lead itu, lalu AI atau agen melanjutkan seperti biasa.

Kode: `App\Welcome` (`WelcomeSettings`, `WelcomeText`, `WelcomeSender`, `WelcomeFields`), job
`App\Jobs\SendWelcomeMessage`, perintah `welcome:send`, tabel `welcome_messages`.

## Pengaturan

| Tempat | Isi |
| --- | --- |
| Formulir > edit > **Pesan sambutan WhatsApp** (`lead_forms.welcome`) | Aktif, jeda kirim (1 / 2 / 5 / 10 menit, bawaan 2), teks, lampiran dari pustaka media. Pratinjau menunjukkan contoh yang diterima pembeli. |
| Landing page > edit > tab **Pesan sambutan** (`landing_pages.welcome`) | Ikuti pengaturan formulir (bawaan), pakai teks khusus halaman ini (dengan jeda dan lampiran sendiri; aktif walaupun formulirnya mati), atau matikan di halaman ini. |
| Produk > tab **Iklan & promo** > Teks sambutan WhatsApp formulir (`properties.welcome_text`) | Teks bawaan per produk, dipakai formulir yang tidak punya teks sendiri. |

Urutan teks: teks landing page (mode khusus), teks formulir, teks produk yang ditanyakan lead, teks bawaan industri.

Bawaan:

- Formulir lama (dibuat sebelum fitur ini): **mati** (`welcome` kosong).
- Formulir baru di toko (retail): **nyala**, jeda 2 menit, teks kosong sehingga teks produk atau teks bawaan dipakai.
- Formulir baru agensi properti: mati.

Teks bawaan toko: "Halo kak {nama} 😊 Terima kasih sudah mengisi pesanan {produk} di {agensi}. Ini ringkasannya ya
kak: {ringkasan}. Mohon dicek, kalau sudah sesuai balas *OK* ya kak, pesanan langsung kami proses 🙏"

Teks bawaan properti: sapaan, lalu tawaran jadwal survei lokasi atau kirim brosur dan daftar harga.

### Variabel

| Variabel | Isi |
| --- | --- |
| `{nama}` | Nama depan pembeli. |
| `{produk}` | Produk lead (dari jawaban formulir atau produk formulir). |
| `{agensi}` | Nama toko / agensi. |
| `{agen}` | Nama pemilik lead. |
| `{ringkasan}` | Satu baris dari jawaban yang relevan: produk, varian, jumlah, alamat ringkas (kecamatan, kota), mis. "Tas Badminton (Hitam) x2, dikirim ke Coblong, Kota Bandung". Tanpa data pesanan: semua jawaban formulir dipisah koma. |
| `{total}` | Harga produk (harga promo) x jumlah. Ditambah ongkir kurir termurah bila pengiriman toko aktif dan bisa menghitung dari alamat; selain itu "Rp698.000 (ongkir kami hitungkan ya kak)". Produk tanpa harga: "ongkir kami hitungkan ya kak". |

## Pengiriman

Saat formulir terkirim, `SubmitLeadForm` memesan satu sambutan (`welcome_messages`, status `pending`, `due_at` = sekarang
+ jeda) dan mengantrekan job tertunda sampai `due_at`. Perintah `welcome:send` (tiap menit) mengirim yang sudah
waktunya bila job-nya hilang; keduanya mengklaim baris dulu sehingga tidak pernah terkirim dua kali.

Saat waktunya, berurutan:

1. **Sekali per lead per formulir** (unik `lead_id` + `lead_form_id`). Nomor yang sama mengisi formulir yang sama lagi
   tidak mendapat sambutan kedua.
2. **Pembeli sudah chat**: ada pesan masuk di percakapan lead sejak formulir dikirim, sambutan dilewati.
3. **Lead lama** (nomor sudah ada) dari formulir lain: dilewati bila sudah ada pesan keluar ke nomor itu dalam 24 jam
   terakhir.
4. **Nomor tidak valid** (bukan nomor HP Indonesia 628..., atau nomor luar negeri di luar 8 sampai 15 digit): gagal,
   tidak dicoba ulang.
5. **Jam tenang** 21.00 sampai 05.00 menurut zona waktu agensi (bawaan WIB; belum ada pengaturan jam layanan per
   agensi): ditunda sampai 05.00, dan diperiksa ulang dari langkah 2 saat itu.
6. **Rute** sama dengan pesan lain ke lead (`RouteSelector`): jendela gratis atau balasan 24 jam lewat nomor resmi,
   selain itu nomor gateway. Gateway yang dipilih: yang terakhir menulis ke pembeli, lalu nomor gateway milik pemilik
   lead (nomor `users.whatsapp` agen sama dengan nomor gateway, sejalan dengan rotasi WhatsApp), lalu urutan pertama.
   Nomor gateway yang belum pernah menyapa pembeli mengirim pesan perkenalan (`intro_template`) dulu. Hanya nomor resmi
   dengan jendela tertutup: dilewati, karena hanya template berbayar yang bisa dikirim.
7. **Batas laju**: maksimal 20 sambutan per menit per nomor; selebihnya menunggu satu menit.
8. Kirim teks (asal pesan `wa_messages.origin = 'welcome'`, di Inbox tampil "· Sambutan otomatis"), lalu lampiran bila
   ada.

Gagal kirim: nomor ditolak gateway atau WhatsApp langsung `failed` dengan alasannya. Hanya bila nomor pengirim
terputus, dicoba lagi tiap 5 menit, paling banyak 3 kali.

## Aktivitas lead

- "Pesan sambutan WhatsApp terkirim lewat gateway" (atau "nomor resmi").
- "Pesan sambutan WhatsApp dilewati: pembeli sudah chat" (dan alasan lewat lainnya).
- "Pesan sambutan WhatsApp gagal terkirim: <alasan>" / "Pesan sambutan WhatsApp gagal: <alasan>".
- "Pesan sambutan WhatsApp ditunda sampai 05:00 (di luar jam layanan)".

Aktivitas sambutan tidak memakai pemilik lead sebagai pelaku, sehingga tidak dihitung sebagai agen sudah menangani
lead (pengalihan lead yang tidak ditangani tetap berjalan).

## Follow-up

Sambutan adalah pesan keluar, jadi tidak dihitung sebagai balasan pembeli: rangkaian follow-up tetap berjalan sesuai
jadwal (H+1 dan seterusnya). Follow-up yang jatuh dalam 2 jam setelah sambutan terkirim ditunda sampai 2 jam itu lewat
(`RunFollowUps::AFTER_WELCOME_HOURS`), agar pembeli tidak menerima dua pesan beruntun.
