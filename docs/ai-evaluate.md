# Evaluating a property agency's AI (`ai:evaluate`) and the daily AI summary

## `php artisan ai:evaluate --tenant=<slug> [--only=1,8,20] [--json]`

Runs the 20 scenarios in `App\Ai\Evaluation\Scenarios` (written from the CS playbook of a Bali housing project)
against the agency's real knowledge, AI instructions, handoff words and Arahan AI settings, as the AI would answer
them in Draft mode. Prints a table with LULUS/GAGAL per scenario and the AI's answer for each failure; the exit code is
non-zero when any scenario fails.

Each scenario runs inside a database transaction that is rolled back (`App\Ai\Evaluation\Evaluator`): a made-up client
(`+62899000xxxx`, "Klien Uji"), a temporary Draft-mode channel and the chat exist only during the run. Queued jobs,
mail and notifications are held, and every HTTP request except the model's is answered locally, so nothing reaches
WhatsApp. The model is really called: about one request per scenario, not counted in the plan's AI quota.

### What is checked

| Check | Fails when |
| --- | --- |
| Expected outcome | A scenario that belongs to a person (WNA, foreigner, notary and land dispute) is not handed to the team, or one that must be answered (first greeting, the client's name, a survey request) is not. |
| Numbers | The answer names a rupiah amount, percentage, year, duration or area that is not in what the model was given (system prompt, chat, tool results), using `PropertyGuard`. |
| Promises | "siap huni", "pasti disetujui", the agency's own forbidden phrases, and per scenario "sudah SHM", "bisa KPR", "gratis". A phrase denied right next to it ("belum bisa KPR") passes, and so does one the agency's own knowledge states (a real "gratis biaya KPR" promo), so scenario 10 (promo) and 7 (KPR) follow each agency's facts. Scenario 4 (cicilan) may be answered when the knowledge has the instalment. |
| Style | 😂, 🤣 or ❤️ used, or more than 3 emoji; the first reply does not greet with "Kak"; the name or survey day the client gave is not repeated. |

The handoff scenarios depend on the agency's handoff words: an agency that has not added the property words
(Pengetahuan AI > Arahan AI > **Tambah kata khusus properti**) fails scenarios 8, 9 and 19, which is the point.

## Per agency (PR 5)

`ai:evaluate` now runs the 20 core scenarios plus questions written from the agency's own approved facts ("Isi properti"):
price and areas per type, survey hours, payment, location. `--quick` runs the ten questions of "Uji AI saya", and
`--record=<trigger>` keeps the run for the Kesehatan AI page. See `docs/property-knowledge.md`.

## Daily summary

The existing daily report (`reports:daily`, Laporan harian) gained three lines in **AI & otomatis**, shown only when
not zero: **Chat dijawab AI** (leads with at least one automatic AI reply), **Jawaban AI ditahan pemeriksa**
(`ai_held` activities) and **Survei dijadwalkan AI** (`ai_survey` activities). "Balasan AI" and "Diserahkan ke tim"
were already there.
