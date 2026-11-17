# Agensi properti siap jual

What a NEW property agency gets out of the box, how to check it, and where it shows.

## The demo agency

`php artisan db:seed --class=AgensiDemoSeeder` makes "Agensi Demo" (slug `agensi-demo`) through
`ProvisionTenant(..., 'properti')`, exactly like a real new agency. The seeder only fills knowledge
(catalogue, "Isi properti" facts, two knowledge items) and connections (a QR gateway number with Jitu's
label defaults). It never writes `ai_settings`, so the agency runs with `AiSettings::NEW_PROPERTY`.

- The brochure fact that disagreed (land area 95 for 45/90) is already rejected, so there is no conflict
  and **Kesiapan AI** passes as seeded. One brochure fact (rent estimate) still waits in "Tinjau data AI"
  (not required).
- `php artisan ai:evaluate --tenant=agensi-demo` runs the full 20 scenarios plus the ones made from the
  facts. It needs `ANTHROPIC_API_KEY`; nothing is sent to any client.
- `tests/Feature/PropertyReadyToSellTest.php` walks a made-up client (0899000…) through the demo with
  the model faked: after "Naikkan ke Auto" the lead moves Lead baru → Dihubungi → Terkualifikasi →
  Jadwal survei by itself, its tasks follow, Meta receives Lead, Contact, QualifiedLead and Schedule,
  and the Prospek label goes on once.

## AI dan Survei

`App\Filament\Pages\AiSurveyReport` (`/ai-dan-survei`), property agencies only, admins and managers.
Numbers come from `App\Reports\AiSurveyData` (aggregate queries, cached 60 s, the Dashboard's period
picker and visible-lead scope):

| Figure | Definition |
|---|---|
| Percakapan baru | chats whose first message from the client came in the period |
| Membalas pesan kedua | of those we answered, the share where the client wrote again after our first answer (failed and intro messages do not count as an answer) |
| Survei dijadwalkan AI | `ai_survey` activities in the period: "(AI)" = sent by the AI, otherwise an agent sent the AI's suggestion |
| Rata-rata sampai survei | lead created → that `ai_survey` activity |
| Serah-terima ke agen | `ai_handoff` activities, grouped by reason (prefix and detail after ":" removed) |
| Per tahap | leads that entered each open/won stage, the Meta event the stage sends (saved mapping, or the new-agency default before Pelacakan is saved) with sent/queued/failed counts, and the label rule of the first gateway number with put-on/waiting/failed counts. A label shared by several stages is counted on its first stage only. |

## Isolation between industries

`tests/Feature/IndustryIsolationTest.php` keeps a new and an existing shop next to a new and an
existing agency and checks that AI settings, instructions, follow-ups, stage automation, AI tools
(`ShopTools` vs `PropertyTools`), ad tracking and labels never cross, and that a changed default in
`config/industries.php` reaches only agencies created afterwards in that industry.

## Payment model (PR 7)

The agency payment model is required before "Naikkan ke Auto". Four demo profiles
(`AgensiDemoPembayaranSeeder`) each run `ai:evaluate` without AI tuning. See `docs/payment-model.md`.
