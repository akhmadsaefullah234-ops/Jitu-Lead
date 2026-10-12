# Gateway WhatsApp (scan QR)

Layanan kecil yang menyambungkan nomor WhatsApp agensi ke CRM lewat scan QR (seperti WhatsApp Web). Satu gateway melayani banyak nomor: tiap nomor di CRM punya sesi sendiri (header `X-Session-Id`). Kontrak API ada di `../docs/whatsapp-gateway-contract.md`.

## Pasang di VPS (satu server dengan CRM)

```
sudo bash gateway/install.sh
```

Skrip memasang gateway sebagai layanan `jitu-gateway`, membuat API key, dan mengisi `WHATSAPP_GATEWAY_URL`, `WHATSAPP_GATEWAY_API_KEY`, `WHATSAPP_GATEWAY_SIGNING_SECRET` di `.env` CRM. Gateway hanya mendengarkan `127.0.0.1`, jadi tidak terbuka ke internet. Syarat: Node.js 20 atau lebih baru.

Lihat log: `journalctl -u jitu-gateway -f`. Data login WhatsApp ada di `/opt/jitu-gateway/data` (cadangkan folder ini; menghapusnya memutus semua nomor).

## Variabel

| Nama | Arti |
|---|---|
| `GATEWAY_API_KEY` | wajib, minimal 24 karakter, sama dengan `WHATSAPP_GATEWAY_API_KEY` di CRM |
| `HOST`, `PORT` | alamat dengar, bawaan `127.0.0.1:3100` |
| `DATA_DIR` | folder login WhatsApp |
| `MAX_SESSIONS` | batas jumlah nomor, bawaan 50 |
| `RELINK_AFTER_FAILS` | jumlah pesan berturut-turut yang tidak bisa dibuka sebelum sesi ditandai perlu scan ulang, bawaan 3 |
| `LOG_LEVEL` | bawaan `info`; `debug` juga mencatat pesan grup/pesan sendiri yang dilewati |

## Baileys 7, LID, dan kesehatan sesi

Gateway memakai Baileys 7 (mendukung alamat `@lid`). Yang perlu diketahui:

- **Setelah memperbarui dari versi lama, semua nomor harus scan QR ulang.** Gateway menandai login buatan versi lama sebagai `needs_relink` saat start, dan CRM menampilkan "Perlu scan ulang" serta mengirim email ke admin agensi.
- Nomor HP pengirim diambil dari `remoteJidAlt`/`senderPn`, atau dari pemetaan LID di folder login. Pesan yang nomornya tetap tidak diketahui dicatat (level warn) dan tidak diteruskan.
- Pesan yang belum bisa dibuka (CIPHERTEXT) ditunggu sampai WhatsApp mengirim ulang (45 detik); kalau tetap gagal, dicatat (warn). Tiga kegagalan berturut-turut tanpa satu pun pesan berhasil membuat sesi `needs_relink`.
- Setiap pesan pribadi yang tidak diteruskan tercatat di log dengan alasannya (`journalctl -u jitu-gateway | grep "tidak diteruskan"`). Hanya 4 digit terakhir nomor yang dicatat.
- Berkas login ditulis atomik (berkas sementara lalu rename), dan `creds.json` punya cadangan `creds.json.bak`.

Pasang versi baru: `git pull` lalu `sudo bash gateway/install.sh` (menjalankan `npm ci` dan restart layanan).

## Pengembangan

```
cd gateway && npm ci && npm test
```

## Catatan

Gateway ini memakai pustaka tidak resmi (Baileys) yang meniru WhatsApp Web. Penggunaan di luar WhatsApp Business API resmi bisa membuat nomor diblokir, terutama untuk pesan massal. Pakai nomor khusus dan jangan kirim pesan ke orang yang tidak pernah menghubungi atau memberi izin.
