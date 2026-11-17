# Agency payment model (PR 7)

Property agencies only. How an agency is paid is stored as data, not free text, and the AI, the guard, the
evaluation, the follow-ups and the stage actions all read it. Nothing is assumed while it is empty.

## Fields ("Isi properti" > Pembayaran, `App\Ai\Facts\PaymentModel`)

All are project facts (`FactKeys::PAYMENT`), filled by an admin in the wizard only. `FactExtractor` never reads
them from a document (`FactKeys::fromDocuments()`), so a brochure cannot switch on KPR.

| Key | Meaning |
| --- | --- |
| `pay_options` | ways to pay offered, stored "cash,installment,developer,kpr,other" |
| `installment_tenor`, `installment_interest` | cash bertahap: maximum months, "ada"/"tidak" interest |
| `developer_tenor` | cicilan developer: maximum months |
| `kpr_banks` | optional partner banks |
| `pay_other` | the "Lainnya" way, in words |
| `dp_model` | `none`, `zero`, `free` (promo), `light`, `standard` |
| `dp_amount`, `dp_promo` | percent or minimum; the promo's period |
| `booking_fee`, `booking_fee_reduces` | tanda jadi and whether it reduces the price ("ya"/"tidak") |
| `payment_notes` | anything else |

The model is **filled** when at least one way to pay and a down payment are chosen. The old free-text facts
(`payment_methods`, `down_payment`, `tenor`) stay as "(catatan)" and are shown in the wizard only while they hold
text.

## What the AI gets

- Knowledge: `Facts::rebuild()` writes the standard entry **Model pembayaran** ("Cara bayar yang tersedia",
  "Tidak tersedia", "Uang muka (DP)", "Tanda jadi", "Catatan pembayaran") in place of "Cara bayar".
- Prompt: `PaymentModel::prompt()` adds "MODEL PEMBAYARAN AGENSI" after the playbook: ask or offer only what is
  available, say honestly that anything else is "belum tersedia" and offer what exists, never "tanpa DP"/"DP 0"/
  "gratis" unless it is in the model, never promise KPR approval. `ReplySignals` keeps that heading out of replies.
- `Defaults::INSTRUCTIONS` (new agencies) no longer lists "tunai, cicilan developer, atau KPR".

## Guard (fact-based)

`PaymentModel::claims()` holds an answer only when it **contradicts** the model:

- it offers KPR, cicilan developer or cash bertahap that is not ticked;
- it says no DP / DP 0 / DP gratis when the model has another down payment;
- it says "gratis"/"free" when neither the model (free DP) nor the agency's knowledge says so.

`PaymentModel::granted()` lists phrases the model makes true ("bisa pakai KPR" when KPR is ticked); a forbidden
phrase among them is not held. `PropertyGuard` also lets a phrase pass when its clause is a denial or doubt
("KPR belum tersedia", "saya belum punya info soal siap huni", "tidak bisa memastikan siap huni"). The guard runs
the same way in replies (`GenerateAiReply`) and in `ai:evaluate`.

"gratis" was removed from `AiSettings::SUGGESTED_PHRASES` (and so from new agencies' forbidden phrases). Stored
forbidden phrases of existing agencies are untouched.

## Follow-ups, stage actions, lost reason (`App\Ai\Facts\PaymentDefaults`)

Only agencies created from PR 7 on (`pipeline_settings.payment_defaults = jitu`), each time the wizard is saved:

- follow-up **Dihubungi H+2** asks about the ticked ways ("…mau bayar cash bertahap atau KPR?");
- the stage actions of "Sudah survei" and "Booking" say "simulasi/proses pembayaran" when KPR is not ticked, and
  "simulasi/proses KPR" when it is;
- the lost reason **KPR ditolak** exists only when KPR is ticked; it is never deleted while a lead uses it.

A text the agency changed itself is never rewritten.

## Readiness and evaluation

- Kesiapan AI: "Model pembayaran (cara bayar dan uang muka)" replaces "Payment methods" and is required, so
  **Naikkan ke Auto** waits for it.
- `Scenarios::fromFacts()` writes: "Cara bayar", "KPR tersedia" or "KPR tidak tersedia", "Tanpa DP" or "Tanpa DP
  tidak tersedia", and "Cicilan developer tidak tersedia" when it is not ticked.

## Demo profiles

`php artisan db:seed --class=AgensiDemoPembayaranSeeder` seeds four made-up agencies (numbers 0899000…):

| Tenant | Profile |
| --- | --- |
| `agensi-demo` | cash keras, cash bertahap 12 bulan, KPR; DP ringan 5%; tanda jadi Rp5 juta mengurangi harga |
| `agensi-demo-bertahap` | cash bertahap only, 24 bulan tanpa bunga; DP 30%; tanda jadi Rp5 juta di luar harga |
| `agensi-demo-dp-nol` | cash keras, KPR (Bank Contoh); DP gratis promo sampai 31 Desember 2026 |
| `agensi-demo-tanda-jadi` | cash keras, cicilan developer 60 bulan; tanpa DP; tanda jadi Rp2 juta |

Run `php artisan ai:evaluate --tenant=<tenant>` for each (needs `ANTHROPIC_API_KEY`).

Tests: `tests/Feature/PaymentModelTest.php`.
