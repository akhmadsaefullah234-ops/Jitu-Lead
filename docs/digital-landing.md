# Landing page "Produk digital" dan Ludo Pasutri

## Template

`App\LandingPages\Templates::DIGITAL` (`produk_digital`) is for agencies with industry `digital` only. It is built from
blocks (`Templates::digitalBlocks()`), with no raw HTML. The blocks are:

1. hero
2. `comparison`: two columns, the usual way against ours
3. `steps`: four how-to steps
4. `benefits`
5. `bonus`: each card has the badge "Sudah termasuk di harga"
6. `tiers`
7. `faq`
8. `buy_bar`: a bar fixed at the bottom, with name, price, optional strike price, and a button to `#daftar`
9. `form`

The five new section types are only offered to digital agencies (`Sections::ONLY`). Their CSS is printed only on a
page that uses them, so existing pages are unchanged.

The template sells a product (`'product' => true`). Picking a catalogue product fills in the buy bar's name, price and
strike price (`compare_price`). The new order form is made from `FormTemplates::ORDER_DIGITAL`, with these fields:

- name and WhatsApp;
- email, optional;
- device: Android, iPhone or Komputer;
- payment method, `payment_type`: Transfer bank (transfer) or QRIS / e-wallet (other);
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

## Note on content

Content for adults can be refused by some payment providers (including payment gateways) and ad platforms (Meta,
TikTok). The template and the Ludo Pasutri copy therefore use neutral words ("permainan pasutri", "tingkat",
"tantangan"), have no explicit images, and never claim "APK": the page says the game is **not** an APK and needs no
install from outside the Play Store. Keep it that way when editing the page or the ads.
