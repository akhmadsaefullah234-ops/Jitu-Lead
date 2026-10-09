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

## Pengembangan

```
cd gateway && npm ci && npm test
```

## Catatan

Gateway ini memakai pustaka tidak resmi (Baileys) yang meniru WhatsApp Web. Penggunaan di luar WhatsApp Business API resmi bisa membuat nomor diblokir, terutama untuk pesan massal. Pakai nomor khusus dan jangan kirim pesan ke orang yang tidak pernah menghubungi atau memberi izin.
