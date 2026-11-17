# Playbook properti dan bawaan agensi properti baru

## Playbook (`App\Ai\PropertyPlaybook`)

Bagian prompt dari kode, dipasang tepat setelah kalimat gaya dan sebelum "Arahan dari agensi". Arahan agensi
("Gaya dan aturan khusus") hanya menambah gaya bicara dan tidak bisa menghapus aturan ini. Isinya:

- **Identitas**: nama asisten (isian *Nama asisten*, bawaan "Asisten {nama agensi}"). AI tidak menyebut dirinya bot,
  AI, atau asisten virtual kecuali klien menanyakannya; bila ditanya, AI menjawab jujur.
- **Nada**: santai (bawaan, "Kak") atau formal ("Bapak/Ibu" bila jelas), mengikuti bahasa klien.
- **Waktu agensi**: hari, tanggal, dan jam lokal agensi sebagai data; salam harus cocok dengan jam itu atau netral.
- **Sambutan**: balasan pertama paling banyak 2 kalimat pendek, sapaan hangat dan satu pertanyaan ringan; tanpa
  daftar tipe, harga, lampiran, penjelasan panjang, atau banyak pertanyaan.
- **Kedekatan**: tanggapi cerita klien dulu, satu pertanyaan per pesan, tidak menekan.
- **Kualifikasi bertahap** lewat `simpan_kebutuhan`; tidak menanyakan ulang yang sudah diketahui.
- **Ajak survei lebih cepat**: begitu ada minat, tawarkan dua pilihan jadwal konkret sesuai jam survei di
  pengetahuan; bila ragu, video show unit atau video call. Setelah klien memilih, `jadwalkan_survei` dan konfirmasi.
- **Pagar**: tidak mengarang angka atau janji, tidak bilang siap huni bila inden, tidak menjanjikan KPR atau hasil
  sewa, tidak mengubah perkiraan menjadi tahun kalender, tidak menulis kata internal, brosur/price list hanya bila
  diminta jelas.

Berlaku bila `tenants.ai_settings.playbook = true`. Agensi properti baru mendapatkannya otomatis. Agensi lama membaca
isinya di **Pengetahuan AI > Playbook properti** dan menyalakannya sendiri; setelah menyala, tidak bisa dimatikan dari
formulir (menyimpan Arahan AI selalu mempertahankan `playbook` dan `auto_gate`, lihat `AiSettings::KEPT`).

## Bawaan agensi properti BARU (`AiSettings::NEW_PROPERTY`)

Diisi `IndustryTemplate` saat agensi properti dibuat (atau beralih ke properti sebelum punya lead), hanya bila
`ai_settings` masih kosong: format WhatsApp, maksimal 10 baris, pemeriksa angka hidup dengan frasa terlarang bawaan,
jeda 5 sampai 25 detik dengan "mengetik...", jam malam mati, AI diam 60 menit setelah menyerahkan chat,
"Tanyakan tipe lewat daftar" mati, nada santai, playbook hidup, dan `auto_gate` hidup.

Di **Arahan AI** agensi properti melihat Nama asisten dan Nada di atas; pengaturan lain dilipat di bagian **Lanjutan**.

## Mulai dari Draft (`App\Ai\AutoGate`)

Selama `auto_gate` hidup, nomor baru dimulai dengan "AI menyiapkan, agen yang kirim", pilihan "AI membalas otomatis"
tidak ditawarkan, dan nomor yang tetap disetel Auto diperlakukan sebagai Draft (tidak ada yang terkirim sendiri).
Tombol **Naikkan ke Auto** di *Mode AI* aktif setelah skor Kesiapan AI lulus (`App\Ai\AiReadiness`, sejak PR 5; lihat
`docs/property-knowledge.md`). Menekannya membuka gerbang dan menaikkan semua nomor Draft
ke Auto. Agensi lama dan toko tidak pernah punya gerbang ini.

## Follow-up bawaan properti

`config/industries.php`: Lead baru tiap 2 hari (H+2 sampai H+14), Dihubungi dan Terkualifikasi setiap hari (H+1
sampai H+7), 7 pesan per tahap, dibuat mati untuk agensi baru. Rangkaian berhenti begitu klien membalas atau agen
mengambil alih; pengingat survei H-1 tetap. **Nyalakan semua** menampilkan pratinjau: aturan yang dibuat, yang
dinyalakan dengan teks agensi, dan follow-up lain yang tetap aktif. Agensi lama tidak berubah sampai tombol itu diklik;
aturan lamanya (mis. Sapaan H+1) tidak dihapus.

## Dampak ke agensi yang sudah ada dan toko

- Agensi properti lama: prompt, pengaturan AI, mode nomor, dan follow-up tidak berubah. Perubahan hanya lewat tombol
  *Playbook properti* dan *Nyalakan semua* (keduanya dengan pratinjau).
- Toko: tidak ada perubahan perilaku. Formulir Arahan AI sama, hanya dibungkus satu bagian tanpa judul.
