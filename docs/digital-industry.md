# Industri "Produk digital" (`digital`)

For agencies that sell e-books, online classes, templates, presets or apps. The buyer pays, a person checks the money,
and the buyer gets an access link. There is no address, courier or shipping cost anywhere in the flow. Everything below
applies only when `tenants.industry = 'digital'`. Shops (`retail`) and property agencies (`properti`) are unchanged.

## Template (`config/industries.php`, key `digital`)

| Part | Value |
| --- | --- |
| Stages | Lead baru (open, 1h, `new`), Menunggu pembayaran (open, 6h, `payment`), Lunas & akses terkirim (won, Booking requirement, 168h, `paid`), Tidak jadi bayar (lost, `unpaid`), Refund (lost, `refund`) |
| Lost reasons | Harga mahal, Ragu fungsi atau manfaat, Tidak ada respons, Pembayaran gagal, Tidak cocok perangkat, Minta refund |
| Lead sources | Iklan Meta, Formulir web, Instagram, TikTok, WhatsApp, Email, Referral |
| Follow-ups (created off) | Sapaan H+1 (Lead baru), Pengingat bayar H+1 (Menunggu pembayaran), Onboarding H+1 (Lunas & akses terkirim) |
| Lead fields | Produk diminati (`property_id`), Jumlah, Perangkat (`device`), Cara bayar, Lunas pada (`paid_at`), Tautan akses (`access_link`) |
| Handoff words | the usual "minta admin" phrases plus refund, komplain, gagal akses, tidak bisa login/unduh, kode error, nego, reseller, penipuan |

Changes from the plan's proposal:

- Follow-up delays are counted in days, so the payment reminder is **H+1**, not hours.
- `email` is not in the industry fields because it is already in the main lead section. Listing it twice would also give the CSV export two email columns.
- `device` and `access_link` are new nullable columns on `leads` (migration `2026_12_07_000100`, additive only).

## Stage automation (`App\Automation\DigitalStageAutomation`)

The settings are under **Pengaturan & Alat > Otomatisasi tahap** and stored in `tenants.stage_automation['digital']`
(`DigitalStageAutomationSettings`). The shop rules keep the top level of the same column, and the property rules keep
`property`. None of them reads another industry's key.

| Rule | What it does |
| --- | --- |
| `purchase_recorded` | A confirmed purchase moves to Menunggu pembayaran (forward only). |
| `payment_proof` | A picture while waiting for payment sets `payment_proof_at`, logs "Bukti transfer masuk, perlu dicek", and sends a push and an email to the admins and the lead owner. |
| `no_payment` (hours, default 48) | `stages:automate` moves an unpaid lead without a proof to Tidak jadi bayar with the reason "Pembayaran gagal". |
| `reopen` | A buyer in Tidak jadi bayar who writes again goes back to Lead baru. |

New digital agencies start with every rule on. An agency that switches to digital while it already has leads starts
with every rule off, and an admin switches them on on the same page. Every move goes through `MoveLeadToStage` and is
logged as "Pindah otomatis ...". A won lead is never moved automatically.

**Pembayaran diterima** (`App\Filament\Support\DigitalPaymentAction`, in the Inbox under "Aksi lain" and on the lead
page) is the only way a purchase becomes paid. It sets `paid_at`, clears `payment_proof_at`, and moves the lead to
Lunas & akses terkirim. The unit is the product name with "xN", and the deal value is the recorded total, or the
catalogue price times the quantity. The access link is optional and is kept on the lead. When the admin asks for it,
the link is sent to the buyer on WhatsApp, but only inside the free chat window. Otherwise the lead's activity says it
was not sent. Later the payment gateway (Prompt C) will call the same `paymentReceived()`.

## AI (`App\Ai\DigitalTools`, `App\Actions\RecordPurchase`)

- The tool is `catat_pembelian`, with these inputs: items (`product_id` or `produk`, plus `jumlah`), `status` (`draft` or `konfirmasi`), `nama`, `email` and `perangkat`. Prices always come from the catalogue, so the model cannot set a total. A product without a price returns no total, and the AI tells the buyer that the admin will confirm it.
- The tool only collects the purchase while the answer is written. `GenerateAiReply::applyActions` (type `purchase`) writes it once the answer is really sent. It fills the product, quantity, `order_items`, email (only when empty and valid), device and name. On `konfirmasi` it also fills `payment_type = transfer` and `deal_value`, moves the lead to Menunggu pembayaran and, when bank accounts are active (Tahap 1), sends the transfer instructions.
- The system prompt says "JANGAN menanyakan alamat, kota, kurir, atau ongkir" and lists the catalogue as `id | nama | harga`. A digital agency never gets the shop tools, because `Shipping::available()` is retail-only.
- The paid-claim guard (`ShopTools::claimsPaymentReceived`) now runs for digital as well as retail. A claim triggers one rewrite with a digital note. If the claim is still there, the buyer gets `SAFE_DIGITAL_PAYMENT_REPLY` and the chat is handed to an agent.

## Forms, pages and data

- Lead forms: a new template, **Pembelian produk digital** (name, WhatsApp, email, product, device, note), shown only to digital agencies. The generic **Pemesanan produk** template, which has an address field, is hidden for digital. The shop's **Order produk** template was already shop-only. Shops and property agencies see the same templates as before.
- Landing pages: digital agencies get the blank page and **Layanan – konversi tinggi**. The shop and property templates stay with their own industries.
- Meta (Pelacakan iklan) defaults: Menunggu pembayaran = InitiateCheckout, Lunas & akses terkirim = Purchase, Hot = Lead. These are off until the agency turns them on.
- The sample data (`DemoData`) has four digital products and leads in every digital stage.
- Tutorial: two new chapters for digital agencies only, `pipeline-digital` and `otomatisasi-tahap-digital`.

## Switching industry

- Switching to digital without leads applies the template in full, with the rules on. With leads, it only adds stages, reasons, sources and follow-ups, and every lead keeps its stage.
- Switching away from digital turns every digital feature off: the tool, the automation, the payment button and the fields. All the data stays: `paid_at`, `access_link`, `device`, the payment instructions and `stage_automation['digital']`. Switching back to digital brings the rules back as they were saved.

## Pembayaran otomatis (Tahap 3, iPaymu)

The agency connects its **own** iPaymu account under **Pengaturan & Alat > Pembayaran otomatis** (`PaymentGatewayPage`, digital
agencies and admins only). The VA and API key go into `payment_gateways`, encrypted with `APP_KEY` and hidden from
arrays. They are never shown back: an empty box keeps the saved key. JITU's own subscription payments (`IpaymuPayments`,
`/billing/ipaymu/notify`, `IPAYMU_*` in `.env`) are untouched. `IpaymuClient::using()` gives a copy that signs with the
agency's account, and the default client still uses JITU's.

Decisions used (chosen 23 Nov 2026):

- Prices come from the catalogue. A product without a price gets no invoice, and an admin confirms the price.
- Refunds are manual: an admin moves the lead to Refund. Nothing is refunded through iPaymu.
- The invoice expires after 24 hours by default (1–168). One reminder goes out 3 hours before it expires, and the `no_payment` rule still moves the lead to Tidak jadi bayar afterwards.
- The access link is the buyer's own access page (see [digital-access.md](digital-access.md)): 90 days and 10 downloads by default, set per product. The gateway's old "Tautan akses berlaku" setting is no longer used.
- No licence keys for now.

Flow (`App\Payments\DigitalCheckout`):

1. Invoices are created at three points:
   - a confirmed purchase (`RecordPurchase`, from the AI or from a form);
   - a form whose after-submit action is **Arahkan ke halaman bayar**;
   - the **Kirim tautan bayar** button in the Inbox or on the lead page.
2. Each one creates an iPaymu redirect session (QRIS or virtual account, chosen on iPaymu's page), stored in `digital_payments`. An invoice that is still open is reused. A lead can start at most 5 invoices in 24 hours.
3. The buyer gets the payment link on WhatsApp (origin `payment_link`), and `leads.payment_amount` / `payment_due_at` are set. The AI is told that the system sends the link and never writes a link, account or VA number itself.
4. iPaymu calls `POST /digital/ipaymu/notify/{tenant}`, which is separate from the subscription callback. The checks run in this order:
   - the callback's signature must match the agency's VA;
   - iPaymu is asked again for the transaction with the agency's key;
   - the reference, the amount (SubTotal) and the session must all match.

   Only status 1, 6 or 7 marks the invoice paid, and it happens once, under a row lock. A repeated callback changes nothing.
5. `DigitalStageAutomation::paidByGateway()` runs next:
   - sets `paid_at`, logs "Pembayaran ... diterima lewat iPaymu (channel)", and moves the lead to Lunas & akses terkirim with the paid amount as the deal value;
   - sends the buyer their access page `/akses/{token}?signature=…` (App\Digital\DigitalAccesses) on WhatsApp (origin `digital_access`) and by email (`DigitalAccessReady`). The page has a download button for the product's private file, or sends on to `leads.access_link` / **Tautan akses produk** (`properties.access_url`, never given to the AI). It opens only for a paid lead;
   - when there is no access link at all, the buyer gets a "paid" message, and the staff get a push "Lunas lewat iPaymu, kirim akses".
6. iPaymu's return page is `/bayar/{token}`. It shows the status. On a paid invoice, the Meta Pixel fires `Purchase` with the same `event_id` the pipeline sends through CAPI (`lead-{id}-Purchase-{purchase_recorded activity}`), so Meta counts it once.

Logs contain only ids, references and statuses, never keys or buyer data. The manual **Pembayaran diterima** button still
works, for a transfer outside iPaymu.

The product form for digital agencies no longer shows weight, size or shipping promos (these were left over from the
shop form). It shows **Tautan akses produk** instead.

## Deploy

1. Take a database backup and check it with `gzip -t`.
2. `git pull`, then `composer install --no-dev`.
3. `php artisan migrate --force`, run as the `jitu` user, so the new tables `payment_gateways` and `digital_payments` are owned by `jitu`. The migrations only add tables and nullable columns.
4. `php artisan optimize`, then restart the queue workers.

No maintenance mode is needed. No existing tenant changes until an agency chooses "Produk digital" and switches on Pembayaran otomatis. In the iPaymu dashboard, the notification URL shown on the settings page is only a fallback, because every invoice sends its own `notifyUrl`.
