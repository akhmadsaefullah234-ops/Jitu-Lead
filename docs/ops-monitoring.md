# Pemantauan kesehatan operasional (heartbeat)

Peringatan untuk **operator platform** saat bagian server yang tidak terlihat dari luar berhenti bekerja. Pemantau
uptime biasa (Better Stack) hanya tahu halaman terbuka atau tidak. Bila antrean, scheduler, atau gateway WhatsApp
mati, follow-up, resi otomatis, laporan, dan balasan AI berhenti diam-diam. Heartbeat ini menutup celah itu.

Cara kerjanya *dead-man's switch*: tiap menit server memeriksa dirinya sendiri dan mengirim ping ke URL heartbeat
**hanya bila semua pemeriksaan lolos**. Bila ping berhenti datang, pemantau mengirim email ke operator. Jadi server
mati, jaringan putus, scheduler berhenti, atau satu pemeriksaan gagal, semuanya berakhir sama: email.

Yang sudah ada dan tidak digantikan:
- `php artisan whatsapp:watch` mengirim email ke admin **agensi** saat nomor scan QR mereka terputus 5 menit
  (`App\WhatsApp\ChannelAlerts`).
- `/up` (bawaan Laravel) untuk pemantau uptime.
- `GET /health` gateway (`gateway/src/server.js`), yang dipakai pemeriksaan di bawah.

## Yang diperiksa

| Pemeriksaan | Sehat bila | Bawaan |
| --- | --- | --- |
| `database` | `select 1` berhasil | - |
| `queue` | job kanari `App\Jobs\QueueCanary` berjalan dalam batas waktu (cache `ops.queue.last_run`) | 5 menit (`jitu.ops.queue_max_age_minutes`) |
| `gateway` | `GET http://127.0.0.1:{port}/health` menjawab 200 dan `ok: true` dalam 3 detik. Port diambil dari `WHATSAPP_GATEWAY_URL`. Dilewati bila `WHATSAPP_GATEWAY_URL` kosong atau belum ada nomor scan QR yang memakai gateway platform | timeout 3 detik |
| `failed_jobs` | job gagal dalam 15 menit terakhir **kurang dari** ambang | 20 (`jitu.ops.failed_jobs_max`) |
| `disk` | sisa ruang di partisi aplikasi **paling sedikit** ambang | 10% (`jitu.ops.disk_min_percent`) |
| scheduler | tidak diperiksa terpisah: `ops:heartbeat` dijalankan oleh scheduler, jadi ping yang berhenti sudah berarti scheduler berhenti | - |

Kanari antrean: scheduler mengantrekan `QueueCanary` tiap menit (`routes/console.php`, nama `ops-queue-canary`, tidak
di APP_ENV local/testing). Worker `jitu-queue` menjalankannya dan job itu hanya menulis waktu saat ini ke cache. Job ini
tidak membawa data apa pun. Bila worker macet atau mati, waktu itu menua dan setelah 5 menit pemeriksaan `queue` gagal.

Bila ada pemeriksaan yang gagal, tidak ada ping. Satu baris log tingkat error ditulis, misalnya
`ops:heartbeat: tidak ada ping, pemeriksaan gagal: queue (kanari antrean terakhir berjalan 7 menit lalu (batas 5 menit))`.
Log tidak memuat data agensi, nomor HP, isi pesan, atau URL heartbeat.

Bila semua lolos, server mengirim `GET` ke URL heartbeat (timeout 5 detik, 1 kali coba ulang). Galat jaringan atau
jawaban bukan 2xx hanya dicatat sebagai peringatan (tanpa URL). Tidak ada pengecualian yang dilempar, dan perintah lain
di scheduler tidak terganggu (`ops:heartbeat` berjalan di latar belakang, `withoutOverlapping`).

Tidak ada endpoint HTTP baru: server yang menghubungi pemantau, bukan sebaliknya.

## Menyalakan

1. Di Better Stack: **Uptime > Heartbeats > Create heartbeat**.
   - Nama: misalnya `JITU LEAD server`.
   - **Expected every**: 3 menit.
   - **Grace period**: 5 menit.
   - Atur siapa yang diberi tahu (email operator) di bagian escalation.

   Ping dikirim tiap menit. Periode 3 menit dengan grace 5 menit membuat satu atau dua menit yang terlewat (deploy,
   restart) tidak langsung memicu email. Email datang kira-kira 8 menit setelah ping terakhir.
2. Salin URL heartbeat (`https://uptime.betterstack.com/api/v1/heartbeat/...`) ke `.env` server:
   ```
   OPS_HEARTBEAT_URL=https://uptime.betterstack.com/api/v1/heartbeat/xxxxxxxx
   ```
   URL ini rahasia: siapa pun yang memegangnya bisa membuat pemantau mengira server sehat. Jangan simpan di repo.
3. `php artisan config:cache` (bila konfigurasi di-cache), lalu `php artisan ops:check`.

`OPS_HEARTBEAT_URL` kosong berarti fitur mati: `ops:heartbeat` selesai tanpa memeriksa atau menghubungi apa pun. Di
APP_ENV local dan testing perintah itu juga tidak melakukan apa pun, kecuali dengan `--force`.

## Diagnosis saat deploy

```sh
php artisan ops:check
```

Mencetak tiap pemeriksaan dengan `LULUS`, `GAGAL`, atau `LEWATI` beserta keterangannya, tanpa mengirim ping. Kode
keluar 1 bila ada yang gagal, jadi bisa dipakai di skrip deploy. Tepat setelah deploy pertama, `queue` bisa gagal
dengan "kanari antrean belum pernah berjalan" sampai scheduler dan worker sempat menjalankan kanari (sekitar 1 menit).

## Mengetes

1. Pastikan `php artisan ops:check` lulus dan heartbeat di Better Stack berstatus **Up**.
2. Matikan worker antrean: `sudo systemctl stop jitu-queue`.
3. Setelah 5 menit `ops:check` menunjukkan `GAGAL Antrean (kanari)` dan log berisi baris `ops:heartbeat: tidak ada ping`.
   Ping berhenti, dan setelah periode plus grace (sekitar 8 menit) Better Stack mengirim email.
4. Nyalakan lagi: `sudo systemctl start jitu-queue`. Dalam satu sampai dua menit ping kembali, dan Better Stack
   menandai heartbeat **Up** lagi.

Cara lain: hentikan gateway (`sudo systemctl stop jitu-gateway`) bila ada nomor scan QR, atau hentikan cron scheduler.
