# Rincian harga dan ongkir di formulir

Formulir toko bisa menampilkan kotak ringkasan di atas tombol kirim: harga x jumlah, ongkir (dengan promo ongkir
produk), dan total untuk alamat yang sedang dipilih pembeli. Kotaknya digambar oleh
`resources/views/public/partials/built-form.blade.php` (`<div class="lf-sum" data-sum>`), angkanya diambil browser
dari `GET /f/{akun}/{formulir}/ringkasan` (`form.quote`, `Public\LeadFormController::quote`), dan dihitung oleh
`App\LeadForms\FormQuote::for()` lewat `Shipping::quote`, sama seperti cek ongkir AI. Hasilnya disimpan 10 menit di
cache sebagai data biasa (kunci `form-quote.v2.{tenant}.{form}.{produk}.{form updated_at}.{alamat}`).

## Kapan kotak tampil

`FormQuote::available($tenant, $form, $pageId)` bernilai benar bila semua ini terpenuhi:

1. `FormQuote::eligible()`: toko menghitung ongkir (`Shipping::available`), formulir punya kolom Alamat yang disimpan
   ke `shipping_address`, dan ada produk berharga (produk formulir, atau produk halaman landing bila formulir tampil
   di dalamnya atau di iframe dengan `?page=`).
2. Saklar **Tampilkan rincian harga dan ongkir** tidak dimatikan: `LeadForm::showsSummary()`.

`FormView::block()` memakai `available()` untuk `quoteUrl`, jadi halaman formulir `/f/{akun}/{formulir}`, blok
formulir di halaman landing, iframe, dan pratinjau di editor ikut aturan yang sama. Tanpa `quoteUrl` kotak tidak
dirender dan skrip formulir tidak memanggil `/ringkasan`.

## Saklar per formulir

- Disimpan di kolom JSON `lead_forms.design` sebagai `design.show_summary` (boolean). Tanpa migrasi.
- Kunci yang tidak ada berarti **hidup**, jadi formulir lama tidak berubah. Hanya `false` (atau `0`/`"0"`) yang
  mematikan.
- `LeadForm::showsSummary()` adalah satu-satunya tempat yang membaca nilai ini. Jangan membaca
  `design.show_summary` langsung di tempat lain.
- Toggle ada di editor formulir, bagian **Kolom formulir**, di bawah **Formulir dua langkah**. Ia hanya tampil bila
  formulir bisa menampilkan rincian (`FormQuote::eligibleAnywhere`, dihitung dari isian editor yang belum disimpan
  juga). Bila belum, editor menampilkan "Rincian tampil otomatis setelah formulir punya produk berharga dan kolom
  Alamat pengiriman." Nilainya tetap tersimpan walau toggle tersembunyi.
- Saat mati, `/ringkasan` menjawab `{"ok": false}` tanpa menghitung ongkir, jadi saklar ini bukan sekadar CSS.
- Menyimpan saklar mengubah `design`, sehingga `updated_at` formulir ikut berubah dan kunci cache `form-quote.v2`
  berganti. Setelah dinyalakan lagi ongkir dihitung ulang, bukan dibaca dari entri lama.

## Yang tidak terpengaruh

Saklar ini hanya soal tampilan di formulir. Pengiriman formulir (`SubmitLeadForm`) tidak pernah memakai
`FormQuote`, jadi alamat, produk, jumlah, nilai pesanan lead, tahap "Order masuk", dan data order/resi tetap sama.

Ringkasan pesanan AI di WhatsApp (draf pesanan dan balasan pertama setelah formulir, `GenerateAiReply` dengan
`OrderConfirmation::fromForm`) dihitung dari data lead lewat `App\Ai\LeadQuote::forLead`, bukan dari `FormQuote`,
dan tidak membaca `showsSummary()`. Pembeli tetap mendapat ringkasan pesanan di chat walau kotak di formulir mati.

Tes: `tests/Feature/FormSummarySwitchTest.php`, `AiRetailRepliesTest::test_the_forms_summary_switch_off_changes_nothing_the_ai_sends`.
