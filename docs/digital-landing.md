# Landing page "Produk digital" dan Ludo Pasutri

## Template

`App\LandingPages\Templates::DIGITAL` (`produk_digital`) is for agencies with industry `digital` only. It is built from
blocks (`Templates::digitalBlocks()`), with no raw HTML. The blocks are:

1. hero, with `visual: product`: the product picture beside the text on a wide screen and under it on a phone, with its
   own alt text (`image_alt`). This visual is offered to digital agencies only; other heroes are unchanged.
2. `comparison`: two columns, the usual way against ours
3. `kind_cards`: 2 to 6 cards with an icon (set, emoji or picture), a title, a short text and an example in italics.
   Each card takes a colour: ungu, biru, jingga or merah muda (`Sections::KIND_COLORS`).
4. `phone_shots`: 1 to 4 screenshots in a phone frame drawn in CSS. They scroll sideways on a phone and stand side by
   side on a wide screen, with a caption under each. Pictures are resized on upload (1080 px), load lazily, and carry
   their width and height. The template starts it empty, and an empty block is not drawn (`BlockFormat::visible`).
5. `steps`: four how-to steps
6. `benefits`
7. `bonus`: one prominent ribbon above the bonus cards. `{harga}` in its text becomes the product's price
   ("Sudah termasuk di harga Rp99.000"); "Tampilkan harga coret produk" adds the product's `compare_price` when it is
   higher.
8. `tiers`: stepped cards with a clear 1, 2, 3 marker and a level bar; each next card stands taller on a wide screen.
9. `faq`
10. `buy_bar`: a bar fixed at the bottom, with name, price, optional strike price, and a button to `#daftar`
11. `form`

The seven digital section types are only offered to digital agencies (`Sections::ONLY`). Their CSS is printed only on
a page that uses them (`PageData::DIGITAL_TYPES`), so other pages are unchanged; `tests/Feature/LandingSnapshotTest.php`
compares a property page and two shop pages byte for byte with the HTML they had before.

## Gaya halaman (tema gelap)

`landing_pages.layout` (the JSON that already held the spacing) also keeps `theme` and `accent`
(`App\LandingPages\PageTheme`). No migration.

- Without a theme, or `terang`, nothing new is printed: the page is exactly as before.
- `gelap` prints one extra stylesheet: page `#171020`, cards `#221731` (and `#2d2040` on a tinted section), text
  `#f5ecf4`, muted text `#b9a9c4`, lines `#46345f`. The header, form, FAQ and buy bar follow it.
- The accent (default `#e9c27b`) colours the main buttons, the heading underline, check marks, prices, the bonus ribbon
  and the tier markers. Text on it is the dark page colour when that reaches WCAG AA (4.5:1), otherwise white or black,
  whichever contrasts more. A section's own "Latar" (terang, gelap, warna utama) still works on a dark page.
- The editor shows "Gaya halaman" and "Warna aksen" under Tampilan for digital agencies only. The "Produk digital"
  template starts dark (`Templates::DIGITAL_LAYOUT`), with colour `#5b2a86` and the `elegan` font.

The page loads no outside fonts or files: the fonts are system fonts and every picture comes from the agency's own
upload folder.

The template sells a product (`'product' => true`). Picking a catalogue product fills in the buy bar's name, price and
strike price (`compare_price`). The new order form is made from `FormTemplates::ORDER_DIGITAL`, with these fields:

- name and WhatsApp;
- email, optional;
- device: Android, iPhone or Komputer;
- payment method, `payment_type`: Transfer bank (transfer) or QRIS / e-wallet (other). For a digital agency whose
  iPaymu gateway is not switched on, the choices that need it (QRIS, e-wallet, virtual account, see
  `FormFields::GATEWAY_PAYMENT`) are left out of the form and refused when sent (`LeadForm::fieldList`); they come back
  by themselves once the gateway is active. Other agencies keep every choice they wrote;
- hidden UTM fields.

It has no address. After sending, it goes to the admin's WhatsApp. A lead from it starts in "Lead baru". As on every
form, `utm_*` is kept in `custom_fields.kampanye` and `fbc`/`fbp` in `custom_fields.pelacakan`.

The agency's name, WhatsApp, logo and colour come from Profil agensi ([profiles.md](profiles.md)). The access link
after payment follows [digital-access.md](digital-access.md).

## Ludo Pasutri

`php artisan landing:ludo-pasutri --tenant=<slug>` creates three things in one **Produk digital** agency only:

- the product "Ludo Pasutri" at Rp99.000, with no access link or file yet, so upload the file in Katalog;
- the form `beli-ludo-pasutri`;
- the landing `ludo-pasutri` as a **draft**.

A second run creates only what is missing and never changes or publishes what exists. A property or retail agency,
or an unknown slug, is refused and nothing is written. An admin checks the page and publishes it.

`--refresh` is the only way the command changes an existing page. It puts the latest sections (dark theme, kind cards,
an empty phone screenshot block) on the `ludo-pasutri` landing of that agency while it is still a **draft**. It keeps
the title, the colour, the font, the spacing, an accent the admin chose, the hero picture and any screenshots already
added. On a published page it refuses with an error and changes nothing; other agencies are never touched.

    php artisan landing:ludo-pasutri --tenant=<slug> --refresh

## Note on content

Content for adults can be refused by some payment providers (including payment gateways) and ad platforms (Meta,
TikTok). The template and the Ludo Pasutri copy therefore use neutral words ("permainan pasutri", "tingkat",
"tantangan"), have no explicit images, and never claim "APK": the page says the game is **not** an APK and needs no
install from outside the Play Store. Keep it that way when editing the page or the ads.
