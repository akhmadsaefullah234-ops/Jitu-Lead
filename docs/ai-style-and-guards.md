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
| `product_list` | on | "Tanyakan tipe lewat daftar": while the lead's product is unknown and there are several, the AI asks which one with the names and names no price. Off: no list is offered, the general price from the knowledge may be named, and a type the client names is still recorded with `PRODUK: <id>` (the id list stays in the prompt, marked as not for the client). |

## Format

`AiSettings::styleInstruction()` writes the first prompt sentence. `AiSettings::shape()` turns habitual markdown
into WhatsApp's own (`**x**` to `*x*`, `# Judul` to `*Judul*`, `- a` to `• a`) and applies the line limit.
`ReplySignals::internal` does not treat WhatsApp formatting as internal text.

## Property guard (`App\Ai\PropertyGuard`)

Only in Auto mode, only for the `properti` industry, only when `guard` is on. An answer is held when it names:

- a rupiah amount (with "Rp" or a unit: ribu, juta, jutaan, M, miliar) whose value is not in what the model was given;
- a percentage, a year (19xx/20xx), a duration in years, or an area (m², meter, are, hektar) not in what it was given;
- a range with money or a unit ("36-45 m2", "Rp600 sampai 700 juta", "5 s/d 10 tahun") that is not written as the
  same range in the knowledge or what it was given;
- a forbidden phrase, unless denied right next to it ("belum SHM", "SHM belum tersedia", "tidak siap huni").

Since PR 4 (2026-11-17) the agency's active knowledge (`PropertyGuard::sources()`) is passed in too:

- a percentage, duration or area counts as grounded when the same number is anywhere in the knowledge, whatever unit
  is written there ("38 m2" against "Bangunan 38"); a year must still be written as a year;
- a forbidden phrase the knowledge itself states ("Promo: gratis biaya KPR") is a fact of the agency, not a promise,
  and is not held.

Since PR 7 a forbidden phrase in a clause of doubt ("saya belum punya info soal siap huni", "tidak bisa memastikan
siap huni") is not a promise either, and payment claims are checked against the agency payment model: held only when
they contradict it. See `docs/payment-model.md`.

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

### The morning answer

The night greeting (origin `ai_quiet`) is left out of the history the AI reads in the morning
(`ConversationHistory::load`), and `AnthropicClient::normalize` makes every chat start and end with a client turn,
joining two turns of the same role. Until 2026-11-16 the greeting was the last turn, and the API refused the request
with a 400 because the model does not take a prefilled answer; five chats stopped at the greeting.

## When the AI fails

`AnthropicClient` throws `App\Ai\AiException` with the API's own reason ("AI error 400: invalid_request_error: ...",
digits masked, no chat text). `GenerateAiReply` tries once more after 2 seconds; if that fails too, the chat is handed
to a person: an `ai_handoff` activity "AI gagal menjawab: <alasan>", a handoff draft (the Inbox box), the log line
"AI reply failed", and an e-mail to the agency's admins (`App\Mail\AiReplyFailed`, at most one per agency per hour,
`App\Ai\AiFailureAlert`).

`php artisan ai:retry-stuck --tenant=<slug> [--minutes=30] [--hours=48] [--limit=20] [--dry-run]` runs the AI again
for chats whose newest message is the night greeting, or a client message older than `--minutes` with no answer. A
client message that already has an AI result (sent, suggestion or handoff) is skipped, and `GenerateAiReply` itself
refuses a message that has a draft or a newer message, so nothing is answered twice. It does nothing during the
agency's quiet hours.

### Every failure ends with a person

`GenerateAiReplyJob::failed()` covers what happens outside the AI call (a job timeout, a crash): unless the message
already has a draft, `GenerateAiReply::failed()` writes the same handoff, activity and e-mail as above.

## Another business's bot (`App\Ai\OtherBot`)

Only in Auto mode. A message that reads like another business's automatic reply ("pesan otomatis", "auto reply",
"terima kasih telah menghubungi", "ketik 1 untuk", away/closed messages), or the third instant reply in a row (within
8 seconds) to our own automatic messages, is not answered: the AI is not called, the chat is paused for 24 hours
(`pauseAiFor`), and it is handed to an agent with the reason "Tampaknya balasan otomatis dari bisnis lain ...".
AI replies sent by themselves are stored with origin `ai` (`GenerateAiReply::AI_ORIGIN`) so the ping-pong can be seen;
welcomes, follow-ups and the night greeting count too.

## Consistent answers (`App\Ai\AnswerMemory`)

Property agencies only. Every answer that passes is remembered per agency for 14 days under the normalised question
and the knowledge version (count and last change of active items; any edit starts afresh). When the same question
comes again and the model wants to hand it over, it is asked once more with its earlier answer in view
(`GenerateAiReply::CONSISTENT_NOTE`); a question never answered before is handed over as before. The API's
`temperature` cannot be set on the current model (400 for non-default values), so consistency comes from this memory
instead of a lower temperature.

## Internal words

`ReplySignals::internal()` also catches the prompt's own section names in capitals: "PENGETAHUAN", "DATA KLIEN:",
"PLAYBOOK PROPERTI", "RINGKASAN PERCAKAPAN:", "MEMORI PERCAKAPAN:", a "LAMPIRAN:" line left inside the text,
"PERBAIKI:", next to the tool names it already caught. Such an answer is rewritten once, then handed to an agent.

## Property handoff words

New property agencies get `AiSettings::PROPERTY_HANDOFF` (WNA, orang asing, notaris, sengketa, KPR ditolak, BI
checking, SLIK, gugatan, pembatalan booking) on top of the defaults. Existing agencies keep their list and can add the
words with **Tambah kata khusus properti**.

## Behaviour for existing agencies

Unchanged by default: short format, guard off, no pause, no quiet hours, their own handoff list, product list on.
Since 2026-11-16 every agency's failed AI answers are handed to a person (before, they were only logged).

PR 4 (2026-11-17), for every agency including shops: another business's bot is no longer answered in Auto mode
(paused 24 hours, handed to an agent); AI replies sent by themselves carry origin `ai` (before: none); a failed job
hands the chat to an agent; the capitalised section names above are treated as internal text. For property agencies
with the guard on: a number grounded only by a different unit in the knowledge, or a forbidden phrase the knowledge
states, is no longer held, and a range not in the knowledge now is. The consistency memory is property-only.

## Playbook properti

Untuk agensi properti yang memakai playbook Jitu, aturan inti percakapan (sambutan, kualifikasi, ajakan survei,
pagar, identitas asisten) datang dari kode: lihat `docs/property-playbook.md`.
