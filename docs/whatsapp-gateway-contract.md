# Kontrak gateway WhatsApp

Dokumen ini dipakai proyek gateway agar CRM JITU LEAD bisa memakai nomornya untuk follow-up. Semua alamat memakai HTTPS. CRM menolak alamat yang mengarah ke jaringan privat.

Isi di CRM (menu Koneksi WhatsApp, jenis Gateway): alamat gateway, API key, dan signing secret. CRM lalu menampilkan alamat webhook unik untuk nomor itu.

## CRM memanggil gateway

Semua permintaan membawa header `Authorization: Bearer <API key>`. Gateway tidak boleh mengarahkan ulang (redirect).

### `GET /session`

Cek koneksi. Balasan 200 dengan JSON `{"status": "connected"}`. Status lain (`disconnected`, `qr_required`) dianggap belum terhubung.

### Menyambungkan nomor lewat QR

Nomor gateway disambungkan seperti WhatsApp Web. CRM menampilkan QR yang dibuat gateway dan menunggu sampai sesi terhubung.

`POST /session/start` memulai sesi baru. Body: `{"webhook_url": "<alamat webhook CRM untuk nomor ini>", "signing_secret": "<kunci tanda tangan>"}`. Balasan 200 dengan `{"status": "qr_required"}`.

### Satu gateway untuk banyak agensi

Bila operator memasang `WHATSAPP_GATEWAY_URL`, `WHATSAPP_GATEWAY_API_KEY`, dan `WHATSAPP_GATEWAY_SIGNING_SECRET` di server CRM, agensi cukup membuat nomor dan scan QR tanpa mengisi alamat atau kunci. Setiap permintaan ke gateway membawa header `X-Session-Id: <id unik nomor>`; gateway wajib menyimpan satu sesi WhatsApp per id itu (`/session`, `/session/start`, `/session/qr`, `/session/logout`, `/messages`). Webhook ke CRM ditandatangani dengan signing secret platform.

`GET /session/qr` mengembalikan QR terbaru:

```json
{ "status": "qr_required", "qr": "<teks yang di-render menjadi QR>", "expires_in": 20 }
```

CRM memanggil endpoint ini tiap beberapa detik selama dialog terbuka, dan mengambil `GET /session` sampai `status` menjadi `connected`. Setelah terhubung, `GET /session/qr` membalas `{"status": "connected"}`.

`POST /session/logout` memutus sesi dan mengosongkan kredensial perangkat di gateway.

### `POST /messages`

```json
{ "to": "+6281234567890", "type": "text", "text": "Isi pesan" }
```

Gambar dan file:

```json
{ "to": "+6281234567890", "type": "image", "text": "keterangan (opsional)",
  "media": { "mime_type": "image/jpeg", "filename": "fasad.jpg", "data": "<base64>" } }
```

`type` bisa `image` atau `document` (PDF, Word, Excel, dll). Batas 16 MB per file; isi permintaan hingga 24 MB. File kosong atau `mime_type` tidak valid dijawab 400, file terlalu besar 413.

Nomor tujuan dalam format E.164 dengan awalan +. Balasan 2xx dengan `{"id": "<id pesan di gateway>"}`. Balasan lain: `{"error": "alasan"}`. Kode 401, 403, atau 5xx menandai nomor bermasalah dan CRM mencoba nomor gateway cadangan.

## Gateway memanggil CRM

`POST <alamat webhook>` dengan JSON. Setiap permintaan wajib membawa header

```
X-Gateway-Signature: sha256=<HMAC-SHA256 isi body mentah, kunci = signing secret, heksadesimal>
```

Permintaan tanpa tanda tangan yang benar ditolak (401). CRM menyimpan setiap `id` sekali saja, jadi pengiriman ulang aman.

### Pesan masuk

```json
{ "event": "message", "id": "m123", "from": "6281234567890", "type": "text",
  "text": "Halo", "name": "Budi", "timestamp": 1790000000 }
```

Nomor baru otomatis menjadi lead dan dibagi ke agen.

Gambar: `type` bernilai `image`, `text` berisi keterangan gambar, dan gambarnya dikirim di dalam webhook:

```json
{ "event": "message", "id": "m124", "from": "6281234567890", "type": "image", "text": "keterangan",
  "media": { "mime_type": "image/jpeg", "data": "<base64>" } }
```

Batas 5 MB. Bila gambar gagal diunduh atau terlalu besar, kirim `media` tanpa `data` tetapi dengan `"error": "download_failed"` atau `"too_large"`; pesannya tetap masuk. CRM memeriksa isi file sebenarnya (hanya JPEG, PNG, WebP, GIF) dan menyimpannya di disk privat, bukan di database.

Voice note, audio, stiker, video: `type` bernilai `audio`, `sticker` atau `video`, dengan `media` yang sama seperti gambar ditambah `bytes`, `seconds` (audio/video), `ptt` (audio: `true` untuk voice note), `animated` (stiker):

```json
{ "event": "message", "id": "m125", "from": "6281234567890", "type": "audio", "text": null,
  "media": { "mime_type": "audio/ogg; codecs=opus", "bytes": 9120, "seconds": 23, "ptt": true, "data": "<base64>" } }
```

Batas: audio 16 MB, stiker 1 MB, video 16 MB (lebih besar: `"error": "too_large"`). File sampai 5 MB ikut di webhook (`data`). File lebih besar disimpan sementara di gateway (24 jam) dan webhook membawa `"fetch": true` tanpa `data`; CRM mengambilnya lewat `GET /media/<id pesan>` (header `Authorization` dan `X-Session-Id` sama seperti permintaan lain, jawaban `application/octet-stream`, 404 bila sudah tidak ada). CRM memeriksa isi file (audio OGG/MP3/M4A/AMR/AAC/WAV/WebM, stiker WebP, video MP4/3GP/MOV/WebM).

Lokasi, kontak, reaksi (tanpa `media`):

```json
{ "event": "message", "id": "m126", "from": "6281234567890", "type": "location",
  "location": { "latitude": -6.2, "longitude": 106.81, "name": "Rumah Budi", "address": "Jl. Melati 3", "live": true } }
{ "event": "message", "id": "m127", "from": "6281234567890", "type": "contacts",
  "contacts": [{ "name": "Siti", "phones": ["6281234567890"] }] }
{ "event": "message", "id": "m128", "from": "6281234567890", "type": "reaction",
  "reaction": { "message_id": "<id pesan yang direaksi>", "emoji": "👍" } }
```

Reaksi tidak menjadi pesan baru: CRM menempelkannya ke pesan yang direaksi (emoji kosong berarti reaksi ditarik); reaksi ke pesan yang tidak dikenal CRM diabaikan. Stiker dan reaksi tidak memicu balasan AI.

### Status pesan keluar

```json
{ "event": "status", "id": "<id dari POST /messages>", "status": "sent|delivered|read|failed", "error": "opsional" }
```

### Status sesi

```json
{ "event": "session", "status": "connected|disconnected|qr_required|needs_relink", "reason": "opsional" }
```

`needs_relink`: login WhatsApp tersimpan rusak (kunci enkripsi tidak cocok lagi, pesan berturut-turut tidak bisa dibuka, atau WhatsApp menolak sesi). Gateway berhenti memakai login itu dan mengingatnya sampai restart. Pesan pelanggan tidak masuk selama status ini. Agensi harus scan QR ulang: `POST /session/start` membuang login lama dan memulai QR baru. CRM menandai nomor "Perlu scan ulang" dan mengirim email ke admin agensi (sekali per kejadian). `GET /session` dan `GET /session/qr` membalas `{"status": "needs_relink"}`; `POST /messages` membalas 503.
