# Kajian: bisakah gateway membuat label WhatsApp Business sendiri?

Status: **kajian saja, tidak dibangun.** Desain sekarang tetap "gateway tidak pernah membuat label baru". Perubahan
hanya dengan persetujuan eksplisit pemilik.

## Masalahnya

Label standar WhatsApp Business (Prospek, Order baru, Lunas) ada di bagian *Saran* dan baru masuk ke daftar akun
setelah dipakai sekali di HP. Sebelum itu gateway tidak melihatnya, jadi label otomatis menunggu (`waiting_label`).
Sekarang admin diminta memakai ketiganya sekali di satu chat uji (`BusinessLabels::GUIDE`).

## Yang bisa dilakukan Baileys 7.0.0-rc14

- `sock.addLabel(jid, { id, name, color, deleted, predefinedId })` mengirim mutasi app state `labelEditAction`.
  Ada kolom `predefinedId` (tipe `LabelActionBody`), dan Baileys membaca `predefinedId` dari `labels.edit`.
- `sock.addChatLabel(jid, labelId)` sudah dipakai gateway untuk memasang label yang sudah ada.
- `gateway/src/labels.js` hanya mencocokkan nama (Prospek = Lead, dst.) dan membuang `predefinedId` di `clean()`.

Jadi secara teknis gateway bisa mengirim "buat label" dengan `predefinedId`.

## Opsi

| Opsi | Cara | Manfaat | Risiko |
| --- | --- | --- | --- |
| A. Tetap seperti sekarang (disarankan) | Admin memakai label sekali di HP (1 menit, sekali per nomor) | Label pasti label standar yang dibaca Meta; tidak menyentuh app state | Satu langkah manual |
| B. Catat `predefinedId` saja | `clean()` menyimpan `predefinedId` dari `labels.edit`, hanya dibaca | Data nyata untuk tahu nomor id tiap label standar, dan memastikan label yang dipakai benar label standar, bukan label buatan dengan nama sama | Hampir nol (hanya membaca) |
| C. Buat label standar dengan `predefinedId` | `addLabel` dengan id baru + `predefinedId` yang diketahui dari opsi B, di balik saklar per nomor, mati bawaan | Tanpa langkah manual | Lihat di bawah |
| D. Buat label biasa dengan nama sama | `addLabel` tanpa `predefinedId` | Mudah | **Bukan sinyal**: Meta membaca label standar, bukan nama. Ini justru menyesatkan laporan. Jangan. |

## Risiko opsi C

1. **Nomor `predefinedId` tidak didokumentasikan.** Meta tidak menerbitkan daftar resminya; salah nomor membuat
   label yang tampak benar tetapi bukan sinyal, atau label standar yang salah.
2. **Tidak pasti Meta menghitungnya.** Bahkan label yang dipasang dari perangkat tertaut masih uji coba
   (lihat `docs/whatsapp-labels.md`). Label yang *dibuat* dari perangkat tertaut lebih tidak pasti lagi.
3. **App state bisa rusak.** Mutasi app state yang ditolak server atau bentrok id bisa membuat sinkron app state
   gagal (`app state sync`), yang memengaruhi semua label, arsip, dan pin akun itu sampai perangkat ditautkan ulang.
4. **Bentrok dengan HP.** Bila admin kemudian memakai label dari *Saran* di HP, akun bisa punya dua label bernama
   sama; `findLabel` memilih yang pertama.
5. **API tidak resmi.** Perubahan protokol WhatsApp bisa mematahkan fitur ini tanpa pemberitahuan; risiko
   pemblokiran nomor kecil tetapi tidak nol untuk aksi yang tidak biasa dilakukan perangkat tertaut.

## Rekomendasi

1. Tetap A untuk semua agensi.
2. Bila pemilik setuju, kerjakan B (hanya baca) di PR terpisah: catat `predefinedId` dan tampilkan di "Label di
   WhatsApp" apakah label yang dimuat benar label standar.
3. Pertimbangkan C hanya setelah B memberi nomor id dari beberapa akun nyata, diuji dulu di nomor demo
   (0899000xxxx), di balik saklar per nomor yang mati bawaan, dan dengan persetujuan eksplisit pemilik.
