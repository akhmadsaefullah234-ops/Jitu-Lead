# AI reply style, property guard, pause and quiet hours

Settings live in `tenants.ai_settings` (JSON), read through `App\Ai\AiSettings` and edited in
**Pengetahuan AI > Arahan AI (lanjutan)**. Every unset key keeps the behaviour agencies had before.

| Key | Default | Meaning |
| --- | --- | --- |
| `format` | `short` | `short`: 1 to 3 sentences without markdown (the old prompt sentence, word for word). `whatsapp`: *bold*, _italic_, numbered lists and emoji. |
| `max_lines` | 8 | Line limit for the `whatsapp` format; longer answers are cut at a line break. |
| `guard` | off | Property agencies: run `PropertyGuard` before an automatic reply. |
| `forbidden_phrases` | none | Phrases the guard refuses ("siap huni", "pasti disetujui"). `AiSettings::SUGGESTED_PHRASES` are offered, not applied. |
| `delay_min` / `delay_max` | 0 / 0 | Random pause (seconds, max 60) before an automatic reply. |
| `typing` | on | Show "typing" during the pause (gateway channels only, `POST /presence`). |
| `quiet_from` / `quiet_until` | none | Quiet hours in the agency time zone; may run past midnight. |
| `quiet_mode` | `greet` | `greet`: one short greeting per night, full answer in the morning. `defer`: nothing until morning. |
| `quiet_message` | `DEFAULT_QUIET_MESSAGE` | The night greeting. |

## Format

`AiSettings::styleInstruction()` writes the first prompt sentence. `AiSettings::shape()` turns habitual markdown
into WhatsApp's own (`**x**` to `*x*`, `# Judul` to `*Judul*`, `- a` to `• a`) and applies the line limit.
`ReplySignals::internal` does not treat WhatsApp formatting as internal text.

## Property guard (`App\Ai\PropertyGuard`)

Only in Auto mode, only for the `properti` industry, only when `guard` is on. An answer is held when it names:

- a rupiah amount (with "Rp" or a unit: ribu, juta, jutaan, M, miliar) whose value is not in what the model was given;
- a percentage, a year (19xx/20xx), a duration in years, or an area (m², meter, are, hektar) not in what it was given;
- a forbidden phrase, unless denied right next to it ("belum SHM", "SHM belum tersedia", "tidak siap huni").

"What the model was given" is the system prompt (knowledge, client data, memory), the chat and tool results.
A held answer becomes a pending draft in the Inbox with the reason, and an `ai_held` lead activity.

## Pause, typing, quiet hours

`App\Ai\ReplyTiming::queue()` delays `GenerateAiReplyJob` by `AiSettings::pause()` in Auto mode and dispatches
`ShowTyping`. Draft mode is never delayed. A gateway without `/presence` (older build) answers 404; `typing()`
returns false and nothing fails.

During quiet hours in Auto mode `GenerateAiReply::quiet()` sends the greeting once per night (origin
`ai_quiet`, labelled "Sambutan jam malam AI") and queues `GenerateAiReplyJob(afterHours: true)` for the end of the
quiet hours plus 0 to 5 minutes. The greeting does not count as an answer, so in the morning the newest client
message is answered.

## Property handoff words

New property agencies get `AiSettings::PROPERTY_HANDOFF` (WNA, orang asing, notaris, sengketa, KPR ditolak, BI
checking, SLIK, gugatan, pembatalan booking) on top of the defaults. Existing agencies keep their list and can add the
words with **Tambah kata khusus properti**.

## Behaviour for existing agencies

Unchanged by default: short format, guard off, no pause, no quiet hours, their own handoff list.
