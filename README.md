# JITU LEAD

CRM multi tenant untuk agensi properti: menangkap lead, membaginya ke agen, dan menindaklanjutinya lewat pipeline sampai booking dan closing. Spesifikasi lengkap ada di PRD project CRM JITU LEAD.

Stack: Laravel 13, Filament 5 (Livewire 4), PostgreSQL 16.

## Yang sudah ada

- Pendaftaran user dan agensi (tenant) sendiri, dengan verifikasi email dan reset password.
- Isolasi data per tenant: setiap model milik tenant punya global scope `tenant_id`, tenant diisi otomatis saat membuat data, dan relasi ke tenant lain ditolak di level model (`app/Models/Concerns/BelongsToTenant.php`, `app/Models/Lead.php`).
- Peran Admin, Team leader, dan Agen. Agen hanya melihat dan mengubah lead miliknya (`app/Policies/LeadPolicy.php`).
- Pipeline properti default: Lead baru, Dihubungi, Terkualifikasi, Jadwal survei, Sudah survei, Negosiasi, Booking, Closing, Gugur.
- Kanban pipeline dengan drag and drop, filter Hot, aksi hari ini, terlambat, dan per agen (`app/Filament/Pages/Pipeline.php`).
- Aturan pindah tahap: aksi berikutnya dan tenggat diisi otomatis; Jadwal survei wajib tanggal dan lokasi, Booking wajib unit dan nilai, Gugur wajib alasan (`app/Actions/MoveLeadToStage.php`).
- Daftar lead dengan pencarian, filter, nomor telepon dinormalisasi ke +62, peringatan lead ganda, dan pembagian bergiliran (round-robin) ke agen aktif.

- WhatsApp dua jalur (`app/WhatsApp`, `app/Actions/SendWhatsAppMessage.php`): nomor resmi (WhatsApp Business API) untuk chat dari iklan Meta dengan jendela gratis 72 jam, dan nomor gateway untuk follow-up setelah jendela gratis tutup. Jalur dipilih otomatis saat kirim (`RouteSelector`), pesan perkenalan dikirim dulu dari nomor gateway yang belum pernah menulis ke klien, nomor gateway cadangan dipakai bila yang pertama gagal, dan template berbayar butuh konfirmasi agen. Webhook masuk memverifikasi tanda tangan, mencegah pesan ganda, dan membuat lead baru dari chat (termasuk data iklan). Kontrak gateway ada di `docs/whatsapp-gateway-contract.md`.
- Inbox WhatsApp (agen hanya melihat chat leadnya) dan pengaturan koneksi nomor (khusus Admin; kredensial dienkripsi dan tidak pernah dikirim balik ke browser).

Belum ada: impor dan ekspor, formulir web, dashboard laporan, dan halaman pengaturan tahap/sumber/properti.

## Menjalankan di komputer sendiri

Butuh PHP 8.3+, Composer, dan PostgreSQL.

```sh
composer install
cp .env.example .env
php artisan key:generate
# isi DB_* di .env, lalu:
php artisan migrate --seed
php artisan serve
```

Buka http://localhost:8000/app. Data contoh memakai password `password`:

| Email | Peran |
| --- | --- |
| admin@griyaprima.test | Admin Griya Prima Realty |
| rina@griyaprima.test | Agen Griya Prima Realty |
| admin@nusaproperti.test | Admin Nusa Properti (tenant lain) |

## Pengujian

Uji memakai database PostgreSQL `jitu_lead_test` (lihat `phpunit.xml`).

```sh
php artisan test
```

Uji isolasi tenant ada di `tests/Feature/TenantIsolationTest.php` dan wajib lulus sebelum setiap rilis.
