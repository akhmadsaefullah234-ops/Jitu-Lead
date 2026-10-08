# Kontrak gateway WhatsApp

Dokumen ini dipakai proyek gateway agar CRM JITU LEAD bisa memakai nomornya untuk follow-up. Semua alamat memakai HTTPS. CRM menolak alamat yang mengarah ke jaringan privat.

Isi di CRM (menu Koneksi WhatsApp, jenis Gateway): alamat gateway, API key, dan signing secret. CRM lalu menampilkan alamat webhook unik untuk nomor itu.

## CRM memanggil gateway

Semua permintaan membawa header `Authorization: Bearer <API key>`. Gateway tidak boleh mengarahkan ulang (redirect).

### `GET /session`

Cek koneksi. Balasan 200 dengan JSON `{"status": "connected"}`. Status lain (`disconnected`, `qr_required`) dianggap belum terhubung.

### Menyambungkan nomor lewat QR

Nomor gateway disambungkan seperti WhatsApp Web. CRM menampilkan QR yang dibuat gateway dan menunggu sampai sesi terhubung.

`POST /session/start` memulai sesi baru. Balasan 200 dengan `{"status": "qr_required"}`.

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

### Status pesan keluar

```json
{ "event": "status", "id": "<id dari POST /messages>", "status": "sent|delivered|read|failed", "error": "opsional" }
```

### Status sesi

```json
{ "event": "session", "status": "connected|disconnected|qr_required" }
```
