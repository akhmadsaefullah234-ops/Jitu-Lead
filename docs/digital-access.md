# Akses unduhan produk digital yang aman

For agencies with industry `digital` only. The aim is to limit link sharing and leave a trail, not DRM.

## Product file (Katalog)

- **Berkas produk**: one HTML, ZIP or PDF per product, at most 10 MB, the same as the server's upload limit
  (`upload_max_filesize` and `client_max_body_size` 10M in deploy/install.sh). It is stored on the private disk
  `digital` (`storage/app/private/digital/{tenant}/…`, `serve` false), under the agency's own folder. The original
  file name is kept (`properties.access_file_name`) and the size is recorded. A path outside the agency's folder is
  dropped when the product is saved.
- **Tautan akses produk** (`access_url`) still works for a product without a file: class, group, outside download.
- **Masa akses** (`access_days`, default 90, max 365) and **Batas unduhan per pembeli** (`access_max_downloads`,
  default 10, max 100).

## The buyer's link

`App\Digital\DigitalAccesses::issue` creates one `digital_accesses` row per buyer once the lead is paid (`paid_at`). It
reuses the one still working. The link is `/akses/{token}` (40 characters, signed with
`URL::temporarySignedRoute` until `expires_at`).

- The page greets the buyer by name. It has a download button (`/akses/{token}/unduh`, also signed), a "Buka dengan
  Chrome" hint, and shows the remaining downloads and the end date. It never redirects straight to the file.
- Opening the page sets `opened_at` (with a lead activity the first time) and the last IP and user agent.
- A download is counted in one SQL update guarded by `downloads < max_downloads`. It records `last_ip`,
  `last_user_agent` and `last_downloaded_at`, and writes an activity "Pembeli mengunduh produk (n dari m)". A product
  with only a link is sent on to that link from the download, which counts the same way.
- **Licence:** in an HTML file, the first `{{LISENSI}}` inside a `data-lic="…"` attribute becomes `Lisensi {nama} ·
  Pesanan #{lead id}`. Without a name it uses `WA •••1234`. The text is HTML-escaped. This happens at download time,
  and the stored file is never changed. Other `{{LISENSI}}` text is left alone. PDF and ZIP are served as they are.
- Refused with page 410: unpaid lead, revoked, expired, downloads used up. A wrong or missing signature gives 403. An
  unknown token gives 404.

## Sending, resending, revoking

- **"Pembayaran diterima"** (manual transfer) and **iPaymu** use the same flow: issue, then send the link on WhatsApp
  (origin `digital_access`). iPaymu also sends it by email. A link typed in the button is kept on the lead as the
  outside target and is never sent as it is.
- **Kirim ulang akses** (lead page and Inbox) sends the same working link again. Staff and the AI can do this at most
  `MAX_RESENDS` = 3 times per lead in total. An admin can always resend. Every resend, and every refused resend, is an
  activity without the link.
- **Tautan akses baru** (admin only) revokes the old links and sends a new one with fresh days and downloads.
- **Cabut akses** revokes every link of the lead. A lead moved to the **Refund** stage, through `MoveLeadToStage`, is
  revoked automatically. Both write an `access_revoked` activity.

## The AI

- Tool `kirim_ulang_akses` exists only for a paid lead that already has a link. It only asks for a resend, which is
  sent after the answer, through `GenerateAiReply::applyActions`. Over the limit, or when the link no longer works, the
  tool answers `perlu_admin` and the AI hands off. The AI never creates a link.
- "tidak bisa buka", "link mati" and "refund" always hand off for a digital agency, whatever the agency's own handoff
  words are (`DigitalAccesses::HANDOFF_WORDS`). They were also added to the digital template's handoff words.
- The AI reads an access message as `[Tautan akses produk dari sistem]`, with every URL replaced by `[tautan akses]`
  (`MessageContent::forAi`). The token is never in an activity.

## Migration

`2026_12_10_000100_create_digital_accesses` (additive): the new table `digital_accesses`, and five nullable columns
on `properties`.
