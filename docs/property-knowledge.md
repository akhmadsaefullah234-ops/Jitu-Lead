# Structured property knowledge, AI readiness and AI health (PR 5)

Property agencies only (the pages are hidden from shops). Admins only.

## "Isi properti" (`App\Filament\Pages\PropertySetup`, `App\Ai\Facts\Facts`)

A wizard in four steps:

1. **Proyek**: project, developer, location, legality, handover.
2. **Tipe unit**: one row per type with price, building and land area, bedrooms, bathrooms, availability and bonus.
3. **Pembayaran**: the agency payment model (ways to pay, down payment, tanda jadi; see `docs/payment-model.md`) and an optional rent estimate.
4. **Survei**: survey hours, survey place, contact.

Each answer is stored as a fact (`ai_facts`). A fact records:

- its subject: the type name, or null for the project;
- its key (`App\Ai\Facts\FactKeys`);
- its value, stored canonically: digits for money, a plain number for areas;
- its source (`wizard` or `dokumen` plus the file name);
- its status (`pending`, `approved`, `rejected`).

Saving the wizard does three things:

- **Catalogue:** each type becomes or updates a catalogue `Property`. The name is "<project> - Tipe <type>", and `property_type` holds the type. Price, location and developer are filled, the status follows the availability, and the areas and rooms go into `details`. A type keeps its property through a hidden `property_id`; if that is missing, the type name is matched against `property_type`.
- **Approved facts:** every approved fact is replaced by what the wizard shows. A value that did not change keeps its fact, and so keeps its source.
- **Knowledge entries:** the standard entries (`source = fakta`) are rewritten by `Facts::rebuild()`:
  - "Proyek …";
  - "Model pembayaran" (or "Cara bayar" from the old free-text fields while the model is empty);
  - "Jam dan lokasi survei";
  - "Kontak";
  - "Perkiraan sewa", which always says it is an estimate and not a promise;
  - one "Tipe …" per type, linked to its property (`property_id`), so it is only read when that property is discussed.

`App\Ai\Facts\FactFormat` writes values one way only. Areas are "38 m2". Money is "Rp642.850.000 (sekitar Rp642,85 juta)": the word "sekitar" is dropped only for a whole number such as "Rp500 juta". Input such as "642,85 juta", "Rp 642.850.000" and "1,25 M" is understood.

Free-text knowledge entries are untouched. Only entries with `source = fakta` are rewritten.

## Documents (`App\Ai\Facts\FactExtractor`)

**Baca dari dokumen** on the wizard page accepts:

- PDF with a text layer;
- Word (.docx);
- Excel (.xlsx), now read by `DocumentReader`;
- TXT, MD, CSV;
- JPG, PNG, WEBP.

Text is sent to the model (up to 30,000 characters). An image is sent as an image block. The request uses `AnthropicClient::structured()`: a forced tool choice `catat_data_properti` without thinking.

Every value becomes a **pending** fact carrying the file name. A value the agency already has (approved or waiting) is not added again. Nothing reaches the AI until the owner approves it.

## Review and conflicts (`App\Filament\Pages\FactReview`, "Tinjau data AI")

The page lists pending facts and every fact in a conflict. A conflict is two approved or pending facts about the same subject and key with different values, for example a land area of 72 against 75. Values are compared by number for money, areas and counts, and by words otherwise.

The actions are:

- **Setujui:** the fact is approved and linked to the matching catalogue type.
- **Tolak:** the fact is rejected.
- **Pakai nilai ini:** this value is approved and the others are rejected.
- A bulk approve for pending facts that are not in a conflict.

While a conflict is undecided, both values are left out of the knowledge entries, so the AI hands that question to an agent. The menu badge counts pending facts plus conflicts.

## Kesiapan AI (`App\Ai\AiReadiness`)

A score out of 100, built from these items:

| Item | Points | Required |
| --- | --- | --- |
| Property available in the catalogue | 15 | yes |
| A price on every available property | 15 | yes |
| Building and land area for every type | 10 | no |
| Availability for every type | 5 | no |
| Project location | 10 | yes |
| Payment model (ways to pay and down payment, PR 7) | 10 | yes |
| Survey hours | 10 | yes |
| Contact | 5 | no |
| Legality and handover | 5 | no |
| No document facts waiting for review | 5 | no |
| No conflicts | 5 | yes |
| Latest evaluation at least 80% | 5 | yes |

The agency passes at **80** points with every required item met. `AutoGate::missing()` now returns `AiReadiness::missing()`, so **Naikkan ke Auto** (Mode AI) waits for this score. Before PR 5 it needed only one property and 3 active knowledge entries. Only new property agencies have the gate.

## Evaluation per agency

`Scenarios::for($tenant)` is the 20 core scenarios plus questions written from the agency's approved facts that are not in a conflict:

- "Harga tipe X": the answer must carry the price. It may be written in full, as "642,85 juta", "642 jutaan", or "1,25 M".
- "Luas tipe X": the answer must carry the building and land area.
- Survey hours: the answer must carry the first hour.
- Payment: from the payment model when it is filled (see `docs/payment-model.md`), otherwise the answer must carry the first method.
- Location: the answer must carry the last part of the address.

`Scenarios::quick()` is **Uji AI saya**: up to five of the agency's own questions, then core scenarios 1, 5, 8, 17 and 20, then more core ones, ten in all.

`ai:evaluate` takes these options:

- `--quick` runs the ten quick questions.
- `--record=manual|quick|knowledge|nightly` keeps the run in `ai_evaluations` (`App\Ai\Evaluation\AiHealth::record`).

When a recorded run scores lower than the run before, the admins get `App\Mail\AiHealthDropped`.

## Kesehatan AI (`App\Filament\Pages\AiHealthPage`)

The page shows the readiness list and score, the latest run with the failed answers, and the last 10 runs. It has two actions:

- **Uji AI saya** dispatches `App\Jobs\RunAiCheck`. The job runs `ai:evaluate --quick --record=quick` in its own process, because the evaluator fakes queue, mail and HTTP while it runs. The page polls until the job is done.
- **Nyalakan/Matikan uji otomatis** switches `ai_settings.auto_eval`.

`ai:health` runs every 15 minutes. It evaluates a property agency only when all of these hold:

- `auto_eval` is on;
- at least one number has AI not Off;
- the AI service is configured.

It runs the agency when the knowledge changed, at least 10 minutes after the last change and compared with the last run's `knowledge_version`. It also runs the agency once a night, between 01:00 and 05:00 agency time. Each run is its own `ai:evaluate --record=…` process. Every run calls the model, so it costs AI tokens.

## Behaviour for existing agencies

- New property agencies get `auto_eval => true` in `AiSettings::NEW_PROPERTY`. Existing agencies have it off, so nothing runs by itself and no token is spent until an admin turns it on.
- The new pages are there for every property agency, but nothing changes until an admin uses them. Existing knowledge entries and catalogue properties are untouched until the wizard is saved.
- Only new property agencies have the Auto gate, so the stricter readiness score does not affect existing agencies.
- Shops do not see the pages. The only shared change is that the knowledge upload's `DocumentReader` now also reads .xlsx; the shop's upload button does not offer it.

## Unit types in a chat (PR 8)

`App\Ai\PropertyTypes`, property agencies only (shops keep `ProductMatcher` as it was):

- **A type the client names.** `ProductContext::asked($text)` looks for a catalogue type in the client's message:
  the size ("36/72", "36 / 72", "36-72", "45x90", "tipe 45"), the type or listing name with one typo allowed
  ("melatti"), or a size in a listing's details ("tipe: 36/72 dan 45/90"). One clear match becomes the product the
  chat is about, before the lead's own: its knowledge (entries linked to that property) and catalogue data are read,
  and once the answer is sent the lead gets it (`product_source = ai`), as if the AI had written "PRODUK: <id>".
  Two types named equally ("bedanya 36/72 dan 45/90?") pick none.
- **Ringkasan tipe.** An agency with 2 to `PropertyTypes::MAX` (10) catalogue products gets "RINGKASAN TIPE" at the
  top of PENGETAHUAN, built at reply time from the catalogue: name, price (`FactFormat::money`), building and land
  area ("36 m2"), bedrooms, bathrooms and availability. It is not stored, so an agency's own entries (a manual
  "Ringkasan lima tipe") are never touched. With "Tanyakan tipe lewat daftar" off (new agencies, Rumahkudotcom),
  the AI is told to answer a price question that names no type from it; with the list on, it still asks first.
