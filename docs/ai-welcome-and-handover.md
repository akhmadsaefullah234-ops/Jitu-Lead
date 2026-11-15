# AI welcome attachments, property follow-ups, and handing a chat to a person

## Welcome brochure and price list (Arahan AI > Lampiran sambutan)

`tenants.ai_settings.welcome_assets` holds up to two Pustaka media items (`AiSettings::welcomeAssets()`). The media
library has no tags, so the agency picks the items in the settings form instead of tagging them "sambutan".

The first automatic reply to a lead carries them, after the text, when all of these hold:

- the channel is in Auto mode (Draft mode never adds them: the agent decides what to send);
- the client's message is not a plain acknowledgement ("ok", "siap");
- `leads.ai_welcome_sent_at` is empty and the lead has no earlier `ai_reply` activity (a lead the AI already answered
  before the setting was chosen does not get them later);
- the items are still active.

The model is told (`GenerateAiReply::WELCOME_NOTE`) that the items follow its message, so it can say so. After the
reply is really sent, `ai_welcome_sent_at` is set; a reply that is held (guard, pause, no free route) does not set it.

## Built-in property follow-ups in one click

`App\FollowUps\PropertyFollowUps::enable()` (Follow-up > "Follow-up properti bawaan" > **Nyalakan semua**):

- switches on the starter rules from `config/industries.php` (Sapaan H+1 in Lead baru, Tanya kebutuhan H+3 in
  Dihubungi, Tawarkan survei H+7 in Terkualifikasi) and the survey reminder H-1;
- keeps the wording an agency changed; a starter rule it deleted is made again with the built-in text, unless its
  stage no longer exists or the plan's follow-up limit is reached (reported in the notification).

A sequence step stops for a lead when:

- the client wrote back since entering the stage (every agency, unchanged);
- **property agencies only:** a person answered since then, from the Inbox (`wa_messages.origin = 'agent'`) or from
  the WhatsApp app on the phone (`from_phone`). Logged as skipped, "Agen sudah menangani chat ini".

The survey reminder still goes in both cases. Follow-up messages are now marked `origin = 'follow_up'`, so they never
read as a person. A shop's follow-ups (payment reminders) keep their schedule after an agent writes.

## When a person handles the chat

| Setting (`ai_settings`) | Default | Effect |
| --- | --- | --- |
| `agent_pause` (minutes) | `whatsapp.ai_after_send_pause_seconds` (5 minutes) | After an agent sends from the Inbox or the phone, the AI does not send on its own for this long. |
| `handoff_pause` (minutes) | 0 | After the AI hands a chat to a person, it stays out of that chat for this long. 0 keeps the old behaviour: later questions the knowledge covers are still answered. |

Typing in the Inbox still pauses the AI for `whatsapp.ai_typing_pause_seconds` (1 minute after the last keystroke).
While a chat is paused (`wa_conversations.ai_paused_until`), an automatic answer is kept as a pending suggestion in the
Inbox with the reason "Agen sedang membalas chat ini, AI tidak mengirim sendiri" and is not sent.

## Behaviour for existing agencies

- No welcome attachments until items are chosen.
- Pauses unchanged until set (5 minutes after an agent message, none after a handoff).
- The property follow-ups are switched on only by the button.
- **Changed for property agencies:** a follow-up sequence step is no longer sent to a lead a person already answered
  in that stage (Inbox or phone). Inbox messages sent before this change carry no origin, so only phone replies and
  new Inbox messages count.
- Inbox messages are stored with `origin = 'agent'` and follow-ups with `origin = 'follow_up'`; labels in the Inbox and
  lead activity are unchanged.
