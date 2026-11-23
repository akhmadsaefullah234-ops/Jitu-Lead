# Shop AI: photos, videos and closing orders without a person

For shops (`industry = retail`) only. Property and digital agencies are unchanged.

## Closing an order

The AI closes a COD order by itself, at any hour:

1. `catat_pesanan` with the status `draft` writes the order to the lead and moves it to Order masuk.
2. The buyer confirms with "ok", "sesuai" and the like. `OrderConfirmation::pending` then records the order
   (`catat_pesanan` with the status `konfirmasi`, through `RecordOrder`).
3. A COD order moves to **Dikemas & dikirim** and a transfer order moves to **Menunggu pembayaran**.

No step depends on the time of day. The only setting that holds AI replies is Arahan AI > Jam malam, which is off unless
the agency turns it on.

The AI still hands the chat to a person for:

- a payment that has to be checked (transfer proof, or a claim that the payment arrived);
- a complaint or a handoff word;
- an expedition the shop doesn't use;
- a shipping cost that can't be priced;
- a question that neither the knowledge nor a tool answers.

## Photos and videos (`App\Ai\MediaRequest`)

A buyer may ask to see the product, for example "ada fotonya?", "real pict", "gambar" or "videonya". In that case:

- `MediaRequest::pick` chooses up to two library items for the product of the chat. It matches the product name in
  the item's title, description or folder, puts the buyer's variant first, and prefers a video when one was asked
  for. Without a product, it only picks items named in the buyer's own words.
- `MediaRequest::videoLinks` reads the video links (YouTube, TikTok, Instagram, Vimeo, Google Drive, Facebook) from
  the knowledge the AI was given.
- When either one finds something, the model gets `MediaRequest::NOTE`: send the item with `LAMPIRAN: <id>` or
  quote the link, and don't hand off.
- If the model still calls `serahkan_ke_admin`, it is asked once more. After that the buyer gets a fixed line,
  "Ini foto produknya ya kak 😊" with the photo, or "Ini videonya ya kak 😊" with the link, and no handoff is logged.
- An answer that attaches nothing and has no link gets the picked photo added.
- When the library has no photo of the product and the knowledge has no video link, nothing changes: the chat goes
  to a person.

The shop prompt has the rule `MediaRequest::RULE` whenever the shop has library items.

The model sees at most 30 library items (`GenerateAiReply::MAX_LIBRARY_ITEMS`). For a shop, the items of the chat's
product come first (`MediaRequest::rank`, out of the first 300 active items). That way a large library still shows
the product's own photos. A shop with 30 items or fewer sees the same list as before.
