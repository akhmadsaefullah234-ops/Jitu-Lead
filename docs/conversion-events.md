# Event konversi ke Meta dan TikTok

Jitu Lead mengirim event konversi lewat server (Meta Conversions API, TikTok Events API) dari dua sumber:

1. **Formulir web dan halaman landing** — event yang sama dengan pixel browser (bawaan `Lead` / `SubmitForm`),
   `action_source: website`, dengan `event_id` yang sama dengan browser agar tidak terhitung dua kali.
2. **Aktivitas lead di CRM** (menu *Pelacakan iklan → Event dari pipeline*) — termasuk lead dari WhatsApp
   (gateway scan QR maupun Cloud API) yang tidak pernah melewati formulir.

## Event dari pipeline

Per tahap pipeline agensi dipilih satu event Meta: tidak ada, `Lead`, `QualifiedLead`, `InitiateCheckout`,
`AddToCart`, `Purchase`, `Schedule`, `CompleteRegistration`, `Contact`. Juga "Lead ditandai Hot → event".

| Industri | Bawaan (mati sampai agensi menyalakan) |
| --- | --- |
| Toko (retail) | Hot = `Lead`, Order masuk = `InitiateCheckout`, Dikemas & dikirim = `Purchase` |
| Properti | Jadwal survei = `Schedule`, Akad/Closing = `Purchase` |

Cara kerja (`App\Tracking\PipelineConversions`):

- Dipicu oleh setiap penyimpanan lead (`Lead::saved`) yang mengubah tahap atau menjadikan lead Hot, jadi berlaku
  untuk perpindahan manual, otomasi tahap (`StageAutomation`), dan AI. Dikirim setelah transaksi selesai, lewat
  antrean (`SendConversionEvents`, 3 percobaan); kegagalan tidak pernah membatalkan perpindahan tahap.
- `action_source: system_generated`, `event_time` = waktu masuk tahap.
- `event_id` stabil: `lead-{id}-{event}`. Satu lead mengirim satu event sekali saja (tabel `conversion_events`,
  unik per agensi). `Purchase` memakai id pesanan terakhir (`lead-{id}-Purchase-{aktivitas order_recorded}`),
  jadi pesanan baru boleh terhitung lagi.
- Lead baru dari formulir web tidak dilaporkan lagi oleh tahap awalnya (formulir sudah mengirim event sendiri).
- TikTok menerima nama event TikTok yang setara (`QualifiedLead` tidak punya padanan, jadi tidak dikirim).

### Data yang dikirim

`user_data` (semua di-hash SHA-256 sesuai aturan Meta, tidak pernah dalam bentuk asli):

- `ph`: nomor format `62…` tanpa `+`; `em`: huruf kecil, tanpa spasi.
- `fn` / `ln`: kata pertama / sisa nama, huruf kecil tanpa tanda baca.
- `ct`, `st`, `zp`: kota (tanpa "Kota"/"Kab."), provinsi, kode pos dari alamat lead bila ada.
- `country`: `id`; `external_id`: hash id lead.
- `fbc` / `fbp` (tidak di-hash, sesuai aturan Meta) bila lead datang dari formulir yang menyimpannya.

`custom_data`: `currency: IDR`, `value` = jumlah harga produk × jumlah di pesanan (tanpa ongkir), atau harga produk
lead; `content_ids`, `content_name`, `num_items`; `ctwa_clid` dan `ad_id` lead hanya sebagai catatan.

Setiap event yang terkirim dicatat di aktivitas lead ("Event Purchase dikirim ke Meta"). Log kegagalan hanya
berisi platform, nama event, dan status HTTP, tanpa token atau data pelanggan.

## Conversions API for Business Messaging (beta)

Mati bawaan. Bila dinyalakan, chat yang masuk lewat **nomor WhatsApp Business API resmi** (Cloud API) dan
berasal dari iklan Klik ke WhatsApp (punya `ctwa_clid`) juga dikirim ke dataset WhatsApp Business:

- `action_source: business_messaging`, `messaging_channel: whatsapp`,
  `user_data: { whatsapp_business_account_id, ctwa_clid }`.
- Nama event: `Lead` → `LeadSubmitted` (Hot → `QualifiedLead`), `QualifiedLead`, `InitiateCheckout`, `AddToCart`,
  `Purchase`. Event lain tidak dikirim ke jalur ini.
- Dataset diambil (atau dibuat) lewat Dataset API `/{WABA_ID}/dataset` dengan access token saluran, lalu disimpan di
  saluran. WhatsApp Business Account ID diisi di *Saluran WhatsApp* atau terisi sendiri dari webhook pertama.
- Nomor scan QR (gateway Baileys) tidak pernah memakai jalur ini.

## Mengisi Pixel ID dan token di Events Manager

**Meta**

1. Buka [Events Manager](https://business.facebook.com/events_manager2), pilih (atau buat) dataset/pixel.
2. Salin **Pixel ID** (angka di bawah nama dataset) ke *Pelacakan iklan → Atur pelacakan → Meta → Pixel ID*.
3. Tab **Settings → Conversions API → Set up direct integration → Generate access token**. Salin token ke
   *Token Conversions API*. Token disimpan terenkripsi dan tidak pernah ditampilkan lagi.
4. Untuk menguji: tab **Test events**, salin kode `TEST…` ke *Kode uji event*, lalu tekan **Kirim event uji** di
   halaman Pelacakan iklan. Event muncul di tab Test events dan tidak dihitung sebagai konversi. Kosongkan kode uji
   setelah selesai.

**TikTok**

1. TikTok Ads Manager → **Tools → Events → Web events**, pilih pixel, salin **Pixel ID**.
2. **Settings → Events API → Generate access token**, salin ke *Token Events API*.
3. Kode uji dari tab **Test events** ke *Kode uji event*.

Kartu tiap platform di halaman Pelacakan iklan menampilkan kiriman server terakhir (berhasil/gagal, waktu, event,
status HTTP).
