# Gateway WhatsApp (scan QR)

Layanan kecil yang menyambungkan nomor WhatsApp agensi ke CRM lewat scan QR (seperti WhatsApp Web). Satu gateway melayani banyak nomor: tiap nomor di CRM punya sesi sendiri (header `X-Session-Id`). Kontrak API ada di `../docs/whatsapp-gateway-contract.md`.

## Pasang di VPS (satu server dengan CRM)

```
sudo bash gateway/install.sh
```

Skrip memasang gateway sebagai layanan `jitu-gateway`, membuat API key, dan mengisi `WHATSAPP_GATEWAY_URL`, `WHATSAPP_GATEWAY_API_KEY`, `WHATSAPP_GATEWAY_SIGNING_SECRET` di `.env` CRM. Gateway hanya mendengarkan `127.0.0.1`, jadi tidak terbuka ke internet. Syarat: Node.js 20 atau lebih baru.

Lihat log: `journalctl -u jitu-gateway -f`. Data login WhatsApp ada di `/opt/jitu-gateway/data` (cadangkan folder ini; menghapusnya memutus semua nomor). Backup CRM membacanya lewat grup `jitu-gw` (folder `2750`, berkas `0640`); `install.sh` mengaturnya, termasuk di server yang sudah terpasang.

## Variabel

| Nama | Arti |
|---|---|
| `GATEWAY_API_KEY` | wajib, minimal 24 karakter, sama dengan `WHATSAPP_GATEWAY_API_KEY` di CRM |
| `HOST`, `PORT` | alamat dengar, bawaan `127.0.0.1:3100` |
| `DATA_DIR` | folder login WhatsApp |
| `MAX_SESSIONS` | batas jumlah nomor, bawaan 50 |
| `RELINK_AFTER_FAILS` | jumlah pesan berturut-turut yang tidak bisa dibuka sebelum sesi ditandai perlu scan ulang, bawaan 3 |
| `LABEL_RESYNC_EVERY_MS` | jeda minimum antar sinkron ulang label per sesi, bawaan 600000 (10 menit) |
| `LABEL_RESYNC_DELAY_MS` | jeda setelah tersambung sebelum sinkron ulang label otomatis, bawaan 15000 |
| `LOG_LEVEL` | bawaan `info`; `debug` juga mencatat pesan grup/status yang dilewati |

## Baileys 7, LID, dan kesehatan sesi

Gateway memakai Baileys 7 (mendukung alamat `@lid`). Yang perlu diketahui:

- **Setelah memperbarui dari versi lama, semua nomor harus scan QR ulang.** Gateway menandai login buatan versi lama sebagai `needs_relink` saat start, dan CRM menampilkan "Perlu scan ulang" serta mengirim email ke admin agensi.
- Nomor HP pengirim diambil dari `remoteJidAlt`/`senderPn`, atau dari pemetaan LID di folder login. Pesan yang nomornya tetap tidak diketahui dicatat (level warn) dan tidak diteruskan.
- **Perangkat tertaut dilepas dari HP** (WhatsApp > Perangkat tertaut > keluar, Baileys `conflict device_removed` atau logout 401) membuat sesi `needs_relink` dengan `reason: "perangkat tertaut dilepas dari HP"`, bukan lagi `disconnected`. Login lama dibiarkan sampai scan ulang dan penandanya bertahan walau gateway restart. CRM mengirim email dan notifikasi ke admin agensi sekali per kejadian. Logout yang diminta CRM (`POST /session/logout`) tidak dilaporkan.
- Pesan yang belum bisa dibuka (CIPHERTEXT) ditunggu sampai WhatsApp mengirim ulang (45 detik); kalau tetap gagal, dicatat (warn). Tiga kegagalan berturut-turut tanpa satu pun pesan berhasil membuat sesi `needs_relink`.
- Setiap pesan pribadi yang tidak diteruskan tercatat di log dengan alasannya (`journalctl -u jitu-gateway | grep "tidak diteruskan"`). Hanya 4 digit terakhir nomor yang dicatat.
- Chat yang dimulai dari iklan Click-to-WhatsApp membawa `referral` (`source_id` = ID iklan, `headline`, `body`, `source_url`, `ctwa_clid`, `thumbnail_url`) dari `contextInfo.externalAdReply` pesan pertama, dengan bentuk yang sama seperti Cloud API. CRM memakainya untuk mencatat iklan dan menentukan produk lead.
- Balasan yang diketik di aplikasi WhatsApp di HP (nomor yang di-scan) diteruskan ke CRM dengan `from_me: true` dan tampil di Inbox sebagai pesan keluar "dari WhatsApp HP". Pesan yang dikirim gateway atas perintah CRM tidak diteruskan ulang, dan CRM juga mengabaikan id pesan yang sudah tersimpan. Pesan dari HP yang terkirim saat gateway mati tidak disusulkan.
- Berkas login ditulis atomik (berkas sementara lalu rename), dan `creds.json` punya cadangan `creds.json.bak`.

Pasang versi baru: `git pull` lalu `sudo bash gateway/install.sh` (menjalankan `npm ci` dan restart layanan).

## Label WhatsApp Business (uji coba)

Untuk fitur CRM "Label WhatsApp Business (uji coba)" di Koneksi WhatsApp (lihat `docs/whatsapp-labels.md`).

- Gateway mengikuti label akun bisnis dari app state Baileys (`labels.edit`, `labels.association`, termasuk sinkron
  awal setelah scan) dan menyimpannya per sesi di `sessions/<id>/labels.json` (id, nama, warna, terhapus).
  WhatsApp hanya mengirim label yang berubah, jadi nomor yang ditautkan sebelum gateway menyimpan label tidak punya
  daftar label sampai disinkron ulang (lihat di bawah).
- `GET /sessions/:id/labels` → `{ labels: [{ id, name, color }] }` (label terhapus tidak ikut).
- `POST /sessions/:id/labels/resync` → `{ labels, resynced: true }`: meminta app state akun dari awal
  (`regular`, `regular_low`, `regular_high`, `critical_block`, `critical_unblock_low`, versi tersimpan dikosongkan
  dulu agar Baileys mengulang semua mutasi) sehingga semua label dilaporkan lagi. Hanya label yang diambil; pesan
  dan kontak tidak berubah. Label yang tidak dilaporkan lagi ditandai terhapus. Paling sering 1x per 10 menit per
  sesi; panggilan lebih cepat menjawab label yang sudah dikenal dengan `resynced: false, retry_after` (detik).
  Gagal: 502 `{ error: "resync_failed" }`. Dijalankan juga otomatis sekali setelah sesi tersambung bila belum ada
  label tersimpan (kecuali akun pribadi).
- `POST /sessions/:id/chat-labels` `{ phone: "+62…", label: "Prospek" }` → `{ label: { id, name }, already }`.
  Label dicari tanpa beda huruf besar/kecil, dengan padanan Prospek/Lead, Order baru/New order, Lunas/Paid.
  Error 422 `{ error: "label_not_found" }` bila akun belum punya label itu (gateway tidak pernah membuat label
  baru), `{ error: "not_business" }` bila HP utama bukan WhatsApp Business. Label tidak pernah dilepas.
- Auth sama dengan endpoint lain (Bearer API key); `X-Session-Id` boleh dikosongkan karena id ada di path, tapi
  bila diisi harus sama.
- Log hanya memuat 4 digit terakhir nomor dan nama label.

## Pengembangan

```
cd gateway && npm ci && npm test
```

## Catatan

Gateway ini memakai pustaka tidak resmi (Baileys) yang meniru WhatsApp Web. Penggunaan di luar WhatsApp Business API resmi bisa membuat nomor diblokir, terutama untuk pesan massal. Pakai nomor khusus dan jangan kirim pesan ke orang yang tidak pernah menghubungi atau memberi izin.
