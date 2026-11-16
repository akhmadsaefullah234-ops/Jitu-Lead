# Otomatisasi tahap untuk agensi properti

`App\Automation\PropertyStageAutomation` is the property counterpart of the shops' `StageAutomation`. It moves a
property agency's leads through its pipeline by itself and keeps the agents' task list (`leads.next_action`) to what
needs a person. Settings are under **Pengaturan > Otomatisasi tahap** and are stored in
`tenants.stage_automation['property']` (`PropertyStageAutomationSettings`). The shop rules keep the top-level keys
of the same column and never read `property`.

## Who has it on

| Agency | State |
| --- | --- |
| New property agency (`ProvisionTenant`, or switched to property before it had leads) | Every rule on, except the thank-you message after a survey |
| Property agency made before this change (nothing stored) | Everything off. The page shows **Nyalakan**. Nothing changes until an admin presses it |
| Shop (retail) | Unchanged: its own eight rules, page as before |
| Other industries | Nothing |

Turning the rules on never touches existing leads. **Terapkan ke lead yang ada** (or
`php artisan stages:catch-up --tenant=<slug> [--apply]`) lists every change first: the lead, the stage it moves to
or the task that is done, and why. It applies only when confirmed.

## Rules

| # | Key | What happens |
| --- | --- | --- |
| 1 | `contacted` | Lead baru → Dihubungi when the client writes after our first message (two-way). A first message alone, or a client nobody answered, stays in Lead baru. |
| 2 | `qualified` | Lead baru/Dihubungi → Terkualifikasi when a purpose plus a domicile, budget or way of paying is recorded (the AI's `simpan_kebutuhan` or an agent editing the lead, via `Lead::saved`) and the client answered at least twice after our first message. |
| 3 | `survey_change` | Adds the AI tool `batalkan_survei` for a lead waiting for its survey. It is refused unless the client's own message says *batal / tidak jadi / tunda / undur / cancel*. Once the answer is sent, the lead goes back to Terkualifikasi (Dihubungi without it), `survey_at` and `survey_location` are cleared so no H-1 reminder goes out, and the task "Klien membatalkan survei: tawarkan jadwal baru" is set. A new date is still `jadwalkan_survei` (reschedule). |
| 4 | `surveyed` | Jadwal survei → Sudah survei N hours (default 3) after `survey_at` (`stages:automate`, hourly). The stage's own task "Kirim penawaran dan simulasi KPR" follows. An agent moves the lead back if the survey did not happen. |
| 5 | `thanks` | Off by default. With rule 4, a thank-you message (editable, `{nama}` `{agensi}` `{properti}`) goes out on WhatsApp, only inside the free chat window. Origin `stage_auto`. |
| 6 | `silent` | Lead baru (14 days) or Dihubungi/Terkualifikasi (10 days) → Gugur, reason "Tidak ada respons". Counted from the client's last message or the stage entry, and only 2 days after the last follow-up sent. Never for a lead we never wrote to, or whose last message is the client's. |
| 7 | `reopen` | A Gugur client who writes again → Dihubungi. Closing (won) never moves. |
| 8 | `booking_ready` | "mau booking", "siap bayar tanda jadi", "jadi ambil unitnya" and similar: the AI does not answer, the chat is handed to an agent with the reason "Klien menyatakan siap booking" and the task "Proses booking …". The stage does not move; Booking and Closing still need the unit and the value from a person. |
| 9 | `tasks` | "Hubungi lewat WhatsApp atau telepon" (Lead baru) is done when the first answer goes out: AI, Inbox, or the phone. Welcome, follow-up, night greeting and the thank-you message do not count. "Gali kebutuhan" (Dihubungi) is done when the purpose is recorded. Every AI handoff sets the task "Tanggapi chat yang diserahkan AI: <reason>", due now. A completed task is logged as activity `task_auto_done`. |

Every move goes through `MoveLeadToStage` with a reason. It is logged as "Pindah otomatis dari X ke Y: reason", the
new stage's follow-ups run, and `Lead::saved` sends its conversion event and WhatsApp label as for a manual move.
Moves only go forward among open stages, never out of a won stage, and an agent's own move always stands: a lead an
agent put further along is not pulled back.

## Finding the stages

`PropertyStages` finds each step by `stages.template_key` first. New agencies get the keys from
`IndustryTemplate::markTemplateStages`. Agencies without keys are matched in this order:

1. by the template's names;
2. the survey step by its `survey` requirement;
3. Booking by its `booking` requirement among open stages;
4. Closing as the first won stage;
5. Gugur as the first lost stage;
6. the first three steps by place, but only when they come before the survey step.

A step the agency does not have switches its rule off silently.

## Effect on shops

None. The hooks run for every agency, but each method returns at once unless the agency is property and the rule
is on. The hooks are:

- `HandleInboundWhatsApp::automate` and `fromPhone`;
- `SendWhatsAppMessage::finish`;
- `Lead::saved` (needs fields only);
- `GenerateAiReply::draft` (handoff) and the booking check.

`stages:automate` now loops over both industries and calls each one's own runner. `StageAutomation` and
`RetailStages` are unchanged.
