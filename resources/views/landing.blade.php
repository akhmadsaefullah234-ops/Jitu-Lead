@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $num = fn ($n) => number_format($n, 0, ',', '.');
    $limitText = fn ($n) => $n === null ? 'tanpa batas' : $num($n);
    $mode = config('jitu.registration');
    $open = $mode !== 'closed';
    $invite = $mode === 'invite';
    $contact = config('jitu.billing_contact');
    $cta = $open ? url('/app/register') : url('/app/login');
    $ctaText = $invite ? 'Daftar dengan kode undangan' : ($open ? 'Coba gratis '.$trialDays.' hari' : 'Masuk');
    $ctaShort = $invite ? 'Daftar' : 'Coba gratis';
    $waDigits = \App\Support\PhoneNumber::whatsapp($contact);
    $wa = $waDigits ? 'https://wa.me/'.$waDigits : null;
    $login = url('/app/login');
    $testimonials = collect($testimonials ?? [])->filter(fn ($t) => filled($t['quote'] ?? null) && filled($t['name'] ?? null))->values();
    $cheapest = collect($plans)->min(fn ($p) => $p['price']['monthly']);
    $trialPlan = $plans[config('plans.trial_plan')]['name'] ?? null;
    // "Pay for N months a year" is only claimed when every plan's yearly price works out to the same N.
    $yearMonths = collect($plans)->map(fn ($p) => round($p['price']['yearly'] / $p['price']['monthly']))->unique();
    $yearMonths = $yearMonths->count() === 1 && $yearMonths->first() < 12 ? (int) $yearMonths->first() : null;
    $graceDays = config('plans.grace_days');
    $ownerContact = $ownerContact ?? [];
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]/u', '', $name))))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JITU LEAD - CRM untuk agen dan agensi properti</title>
<meta name="description" content="Tangkap lead dari iklan dan WhatsApp, balas lebih cepat dengan AI, dan tindak lanjut otomatis. CRM untuk agen dan agensi properti.">
<link rel="icon" href="{{ asset('images/icon.png') }}">
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|jetbrains-mono:500,600&display=swap">
<style>
/* Layout: halaman jualan satu kolom; setiap fitur dipasangkan dengan cuplikan layar aplikasi yang dibangun dari HTML. */
:root {
  --bg: #ffffff;
  --bg-soft: #fbf6f5;
  --surface: #ffffff;
  --ink: #1c1414;
  --muted: #6e6261;
  --line: #ece3e1;
  --red: #d92b20;
  --red-ink: #b4231a;
  --red-soft: #fdecea;
  --green: #15803d;
  --green-soft: #e7f6ec;
  --amber: #b45309;
  --amber-soft: #fdf3e2;
  --blue: #1d4ed8;
  --blue-soft: #e8efff;
  --wa: #e3f7dd;
  --screen: #f6f2f1;
  --shadow: 0 1px 2px rgba(60, 20, 18, .06), 0 12px 32px -12px rgba(60, 20, 18, .18);
  --f-display: "Plus Jakarta Sans", "Segoe UI", system-ui, sans-serif;
  --f-body: "Plus Jakarta Sans", "Segoe UI", system-ui, sans-serif;
  --f-mono: "JetBrains Mono", ui-monospace, "Cascadia Mono", Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #141011; --bg-soft: #1b1617; --surface: #211b1c; --ink: #f4eeed; --muted: #b3a7a5; --line: #352c2d;
    --red: #f0574c; --red-ink: #ff8278; --red-soft: #3a1d1b; --green: #4ade80; --green-soft: #173323;
    --amber: #f6b450; --amber-soft: #3a2a14; --blue: #8fb0ff; --blue-soft: #1d2741; --wa: #1f3a24; --screen: #1a1516;
    --shadow: 0 1px 2px rgba(0,0,0,.4), 0 12px 32px -12px rgba(0,0,0,.6);
    color-scheme: dark;
  }
}
:root[data-theme="dark"] {
  --bg: #141011; --bg-soft: #1b1617; --surface: #211b1c; --ink: #f4eeed; --muted: #b3a7a5; --line: #352c2d;
  --red: #f0574c; --red-ink: #ff8278; --red-soft: #3a1d1b; --green: #4ade80; --green-soft: #173323;
  --amber: #f6b450; --amber-soft: #3a2a14; --blue: #8fb0ff; --blue-soft: #1d2741; --wa: #1f3a24; --screen: #1a1516;
  --shadow: 0 1px 2px rgba(0,0,0,.4), 0 12px 32px -12px rgba(0,0,0,.6);
  color-scheme: dark;
}

* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } * { animation: none !important; transition: none !important; } }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: var(--f-body); font-size: 16px; line-height: 1.6; overflow-x: hidden; }
a { color: inherit; }
a:focus-visible, button:focus-visible, summary:focus-visible { outline: 3px solid var(--red); outline-offset: 2px; border-radius: 6px; }
h1, h2, h3 { font-family: var(--f-display); text-wrap: balance; margin: 0; letter-spacing: -.02em; }
p { margin: 0; }
.wrap { max-width: 72rem; margin: 0 auto; padding-inline: 1rem; }
@media (min-width: 640px) { .wrap { padding-inline: 1.5rem; } }
.eyebrow { font-family: var(--f-mono); font-size: .75rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--red-ink); }
.lead-text { color: var(--muted); font-size: 1.06rem; max-width: 36rem; }
.mono { font-family: var(--f-mono); font-variant-numeric: tabular-nums; }

/* Tombol */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; padding: .8rem 1.3rem; border-radius: .7rem; background: var(--red); color: #fff; font-weight: 700; text-decoration: none; border: 0; font: inherit; font-weight: 700; cursor: pointer; transition: transform .15s, background .15s; }
.btn:hover { background: var(--red-ink); transform: translateY(-1px); }
.btn.ghost { background: transparent; color: var(--ink); border: 1px solid var(--line); }
.btn.ghost:hover { background: var(--bg-soft); }
.btn.sm { padding: .5rem .9rem; font-size: .9rem; }

/* Header */
header.top { position: sticky; top: env(safe-area-inset-top, 0px); z-index: 20; background: color-mix(in srgb, var(--bg) 88%, transparent); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
header.top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; height: 3.75rem; }
.brand { display: flex; align-items: center; gap: .55rem; text-decoration: none; font-family: var(--f-display); font-weight: 800; letter-spacing: -.01em; font-size: 1.1rem; }
.brand-mark { width: 1.9rem; height: 1.9rem; border-radius: .55rem; background: var(--red); display: grid; place-items: center; }
.brand-mark svg { width: 1.1rem; height: 1.1rem; }
nav.links { display: none; gap: 1.5rem; font-size: .92rem; font-weight: 500; }
nav.links a { text-decoration: none; color: var(--muted); }
nav.links a:hover { color: var(--ink); }
@media (min-width: 860px) { nav.links { display: flex; } }
.top-cta { display: flex; gap: .5rem; align-items: center; }
.top-cta .login { display: none; }
@media (min-width: 520px) { .top-cta .login { display: inline-flex; } }

/* Hero */
.hero { padding-block: 3.5rem 2rem; background: radial-gradient(60rem 28rem at 85% -10%, var(--red-soft), transparent 60%), var(--bg); }
.hero-grid { display: grid; gap: 2.5rem; align-items: center; }
@media (min-width: 1180px) { .hero-grid { grid-template-columns: 1fr 1.3fr; } }
.hero h1 { font-size: clamp(2.1rem, 5.2vw, 3.5rem); line-height: 1.06; font-weight: 800; margin-block: .75rem 1.1rem; }
.hero h1 em { font-style: normal; color: var(--red); }
.hero .row { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.6rem; }
.chips { display: flex; flex-wrap: wrap; gap: .5rem 1.1rem; margin-top: 1.5rem; font-size: .88rem; color: var(--muted); padding: 0; list-style: none; }
.chips li { display: flex; align-items: center; gap: .4rem; }
.chips li::before { content: ""; width: .5rem; height: .5rem; border-radius: 50%; background: var(--green); }

/* Bingkai aplikasi (mockup) */
.stage { position: relative; min-width: 0; }
.window { background: var(--surface); border: 1px solid var(--line); border-radius: 1rem; box-shadow: var(--shadow); overflow: hidden; }
.window-bar { display: flex; align-items: center; gap: .4rem; padding: .55rem .8rem; border-bottom: 1px solid var(--line); background: var(--bg-soft); }
.window-bar i { width: .6rem; height: .6rem; border-radius: 50%; background: var(--line); display: block; }
.window-bar .url { margin-left: .6rem; font-family: var(--f-mono); font-size: .7rem; color: var(--muted); background: var(--surface); border: 1px solid var(--line); border-radius: .4rem; padding: .1rem .55rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.app { display: grid; grid-template-columns: 1fr; background: var(--screen); }
@media (min-width: 640px) { .app { grid-template-columns: 9.5rem 1fr; } }
.side { display: none; padding: .8rem .6rem; border-right: 1px solid var(--line); background: var(--surface); font-size: .75rem; }
@media (min-width: 640px) { .side { display: block; } }
.side b { display: block; font-size: .65rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: .7rem .4rem .3rem; font-weight: 600; }
.side span { display: flex; align-items: center; gap: .45rem; padding: .35rem .45rem; border-radius: .4rem; color: var(--muted); }
.side span.on { background: var(--red-soft); color: var(--red-ink); font-weight: 700; }
.side span .n { margin-left: auto; font-family: var(--f-mono); font-size: .65rem; background: var(--red); color: #fff; border-radius: 1rem; padding: 0 .35rem; }
.board { padding: .8rem; min-width: 0; }
.board-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .7rem; flex-wrap: wrap; }
.board-head h4 { margin: 0; font-size: .9rem; font-family: var(--f-display); }
.kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .5rem; margin-bottom: .7rem; }
.kpi { background: var(--surface); border: 1px solid var(--line); border-radius: .6rem; padding: .45rem .6rem; min-width: 0; }
.kpi small { display: block; font-size: .62rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kpi strong { font-family: var(--f-mono); font-size: 1rem; }
.kpi .up { font-family: var(--f-mono); font-size: .62rem; color: var(--green); margin-left: .25rem; }
.cols { display: grid; grid-template-columns: repeat(4, minmax(8.5rem, 1fr)); gap: .5rem; overflow-x: auto; padding-bottom: .2rem; }
.col { background: color-mix(in srgb, var(--line) 45%, transparent); border-radius: .6rem; padding: .45rem; min-width: 0; }
.col h5 { margin: 0 0 .4rem; font-size: .66rem; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); display: flex; justify-content: space-between; font-family: var(--f-body); }
.lc { background: var(--surface); border: 1px solid var(--line); border-radius: .5rem; padding: .45rem .5rem; margin-bottom: .4rem; font-size: .7rem; line-height: 1.35; }
.lc b { display: block; font-size: .74rem; }
.lc .meta { display: flex; align-items: center; justify-content: space-between; gap: .3rem; margin-top: .3rem; }
.pill { display: inline-block; font-size: .6rem; font-weight: 700; padding: .05rem .4rem; border-radius: 1rem; white-space: nowrap; }
.p-meta { background: var(--blue-soft); color: var(--blue); }
.p-wa { background: var(--green-soft); color: var(--green); }
.p-web { background: var(--red-soft); color: var(--red-ink); }
.p-warn { background: var(--amber-soft); color: var(--amber); }
.av { width: 1.15rem; height: 1.15rem; border-radius: 50%; display: grid; place-items: center; font-size: .55rem; font-weight: 800; color: #fff; flex: none; }
.a1 { background: #c2410c; } .a2 { background: #0f766e; } .a3 { background: #7c3aed; }

/* HP melayang */
.phone { width: 13.5rem; background: var(--surface); border: 1px solid var(--line); border-radius: 1.6rem; box-shadow: var(--shadow); padding: .5rem; }
.phone .scr { background: var(--screen); border-radius: 1.2rem; overflow: hidden; }
.phone .ph-head { display: flex; align-items: center; gap: .45rem; padding: .55rem .65rem; background: var(--surface); border-bottom: 1px solid var(--line); font-size: .72rem; }
.phone .ph-head small { display: block; color: var(--muted); font-size: .6rem; }
.msgs { padding: .6rem; display: flex; flex-direction: column; gap: .4rem; font-size: .7rem; line-height: 1.4; }
.bub { max-width: 85%; padding: .4rem .55rem; border-radius: .7rem; background: var(--surface); border: 1px solid var(--line); }
.bub.me { align-self: flex-end; background: var(--wa); border-color: transparent; }
.bub time { display: block; text-align: right; font-size: .55rem; color: var(--muted); font-family: var(--f-mono); }
.draft { border: 1.5px dashed var(--red); background: var(--red-soft); border-radius: .7rem; padding: .45rem .55rem; }
.draft .lbl { font-size: .58rem; font-weight: 800; color: var(--red-ink); letter-spacing: .05em; text-transform: uppercase; }
.draft .acts { display: flex; gap: .3rem; margin-top: .35rem; }
.draft .acts span { font-size: .6rem; font-weight: 700; padding: .15rem .5rem; border-radius: .4rem; background: var(--red); color: #fff; }
.draft .acts span + span { background: var(--surface); color: var(--ink); border: 1px solid var(--line); }
.img-msg { border-radius: .5rem; height: 4.2rem; background: linear-gradient(135deg, #e9d5c3, #c7a98d 55%, #8f6f55); position: relative; overflow: hidden; }
.img-msg::after { content: ""; position: absolute; left: 18%; right: 18%; bottom: 0; height: 58%; background: linear-gradient(#f5efe8, #e6dbcf); clip-path: polygon(0 35%, 50% 0, 100% 35%, 100% 100%, 0 100%); }
.hero .phone { display: none; }
@media (min-width: 720px) { .hero .phone { display: block; position: absolute; right: -1rem; bottom: -2.5rem; } .hero .window { margin-right: 8rem; } }
@media (min-width: 1180px) { .hero .phone { right: -2rem; } }
.toast { position: absolute; left: 1rem; top: -1.2rem; display: none; align-items: center; gap: .6rem; background: var(--surface); border: 1px solid var(--line); border-radius: .8rem; box-shadow: var(--shadow); padding: .55rem .75rem; font-size: .75rem; max-width: 17rem; animation: pop .6s .4s both; }
.toast .dot { width: 1.8rem; height: 1.8rem; border-radius: .5rem; background: var(--red); display: grid; place-items: center; flex: none; }
.toast small { display: block; color: var(--muted); font-size: .65rem; }
@media (min-width: 720px) { .toast { display: flex; } }
@keyframes pop { from { transform: translateY(6px); } to { transform: none; } }

/* Strip masalah */
.pain { border-block: 1px solid var(--line); background: var(--bg-soft); padding-block: 2.5rem; margin-top: 3rem; }
.pain-grid { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
.pain h3 { font-size: 1rem; margin-bottom: .3rem; }
.pain p { color: var(--muted); font-size: .93rem; }
.pain .x { font-family: var(--f-mono); color: var(--red-ink); font-size: .8rem; font-weight: 600; margin-bottom: .4rem; display: block; }

/* Bagian fitur */
section.s { padding-block: 4.5rem; }
section.s.alt { background: var(--bg-soft); }
.s-head { max-width: 40rem; margin-bottom: 2.5rem; }
.s-head h2 { font-size: clamp(1.7rem, 3.4vw, 2.4rem); line-height: 1.12; margin-block: .5rem .8rem; }
.feat { display: grid; gap: 2rem; align-items: center; padding-block: 2.5rem; border-top: 1px solid var(--line); }
.feat:first-of-type { border-top: 0; }
@media (min-width: 900px) { .feat { grid-template-columns: 1fr 1.1fr; gap: 3.5rem; } .feat.flip > :first-child { order: 2; } }
.feat h3 { font-size: clamp(1.35rem, 2.6vw, 1.75rem); line-height: 1.2; margin-block: .5rem .7rem; }
.feat > div { min-width: 0; }
.ticks { list-style: none; padding: 0; margin: 1.1rem 0 0; display: grid; gap: .55rem; }
.ticks li { display: grid; grid-template-columns: 1.3rem 1fr; gap: .5rem; font-size: .95rem; }
.ticks li::before { content: ""; width: 1.1rem; height: 1.1rem; margin-top: .2rem; border-radius: .35rem; background: var(--red-soft) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M4 8.5l2.5 2.5L12 5.5' fill='none' stroke='%23d92b20' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center/80% no-repeat; }
.panel { background: var(--surface); border: 1px solid var(--line); border-radius: 1rem; box-shadow: var(--shadow); padding: 1.1rem; min-width: 0; }
.panel-title { display: flex; justify-content: space-between; align-items: center; gap: .5rem; font-size: .8rem; font-weight: 700; margin-bottom: .9rem; }
.panel-title span { font-family: var(--f-mono); font-size: .65rem; font-weight: 500; color: var(--muted); }

/* Sumber lead */
.src { display: grid; gap: .7rem; }
.src-row { display: grid; grid-template-columns: 7.5rem 1fr 2.2rem; gap: .6rem; align-items: center; font-size: .82rem; }
.bar { height: .6rem; border-radius: 1rem; background: color-mix(in srgb, var(--line) 70%, transparent); overflow: hidden; }
.bar i { display: block; height: 100%; border-radius: 1rem; background: var(--red); }
.src-row .mono { text-align: right; font-size: .78rem; }
.src-foot { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: 1rem; }

/* Pembagian lead */
.team { display: grid; gap: .65rem; }
.mem { display: grid; grid-template-columns: 1.8rem 1fr auto; gap: .6rem; align-items: center; font-size: .84rem; }
.mem .av { width: 1.8rem; height: 1.8rem; font-size: .7rem; }
.mem small { display: block; color: var(--muted); font-size: .7rem; }
.share { font-family: var(--f-mono); font-weight: 600; font-size: .85rem; }
.rule { margin-top: 1rem; border-radius: .7rem; background: var(--amber-soft); color: var(--ink); padding: .7rem .8rem; font-size: .8rem; display: grid; grid-template-columns: auto 1fr; gap: .6rem; align-items: start; }
.rule b { color: var(--amber); }

/* Follow-up */
.flow { list-style: none; margin: 0; padding: 0; position: relative; display: grid; gap: .8rem; }
.flow::before { content: ""; position: absolute; left: 1.05rem; top: .6rem; bottom: .6rem; width: 2px; background: var(--line); }
.flow li { display: grid; grid-template-columns: 2.2rem 1fr; gap: .7rem; align-items: start; position: relative; }
.flow .day { width: 2.2rem; height: 2.2rem; border-radius: .6rem; background: var(--surface); border: 1.5px solid var(--red); color: var(--red-ink); font-family: var(--f-mono); font-size: .66rem; font-weight: 600; display: grid; place-items: center; }
.flow .card { border: 1px solid var(--line); border-radius: .7rem; padding: .55rem .7rem; font-size: .8rem; background: var(--surface); }
.flow .card small { display: block; color: var(--muted); font-size: .7rem; }
.att { display: inline-flex; align-items: center; gap: .3rem; margin-top: .35rem; font-size: .68rem; font-weight: 700; padding: .15rem .45rem; border-radius: .4rem; background: var(--bg-soft); border: 1px solid var(--line); }

/* Laporan */
.funnel { display: grid; gap: .55rem; }
.fn-row { display: grid; grid-template-columns: 5.5rem 1fr 3.4rem; gap: .6rem; align-items: center; font-size: .8rem; }
.fn-bar { height: 1.6rem; border-radius: .4rem; background: var(--red); opacity: var(--o, 1); }
.fn-row .mono { text-align: right; font-size: .78rem; }
.fn-note { margin-top: .9rem; font-size: .75rem; color: var(--muted); }

/* Grid fitur lain */
.more { display: grid; gap: 1px; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); background: var(--line); border: 1px solid var(--line); border-radius: 1rem; overflow: hidden; }
.more div { background: var(--surface); padding: 1.3rem; }
.more h3 { font-size: 1rem; margin-block: .65rem .3rem; letter-spacing: -.01em; }
.more p { font-size: .9rem; color: var(--muted); }
.ic { width: 2.2rem; height: 2.2rem; border-radius: .6rem; background: var(--red-soft); display: grid; place-items: center; }
.ic svg { width: 1.2rem; height: 1.2rem; stroke: var(--red-ink); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }

/* Langkah */
.steps { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); counter-reset: st; }
.step { border: 1px solid var(--line); border-radius: 1rem; padding: 1.3rem; background: var(--surface); counter-increment: st; }
.step::before { content: "Langkah " counter(st); font-family: var(--f-mono); font-size: .72rem; font-weight: 600; color: var(--red-ink); letter-spacing: .05em; text-transform: uppercase; }
.step h3 { font-size: 1.08rem; margin-block: .5rem .35rem; }
.step p { color: var(--muted); font-size: .92rem; }
.step .time { margin-top: .8rem; font-family: var(--f-mono); font-size: .75rem; color: var(--muted); }

/* Testimoni */
.notice { display: flex; gap: .6rem; align-items: flex-start; border: 1px dashed var(--amber); background: var(--amber-soft); border-radius: .8rem; padding: .75rem .9rem; font-size: .88rem; margin-bottom: 1.5rem; max-width: 44rem; }
.notice b { color: var(--amber); }
.quotes { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); }
.quote { position: relative; border: 1px solid var(--line); border-radius: 1rem; padding: 1.4rem; background: var(--surface); display: flex; flex-direction: column; gap: 1rem; }
.quote .tag { position: absolute; top: .9rem; right: .9rem; font-family: var(--f-mono); font-size: .62rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; background: var(--amber-soft); color: var(--amber); padding: .1rem .45rem; border-radius: .3rem; }
.quote blockquote { margin: 0; font-size: 1rem; line-height: 1.55; }
.quote blockquote::before { content: "“"; display: block; font-family: var(--f-display); font-size: 2.6rem; line-height: .8; color: var(--red); }
.who { display: flex; align-items: center; gap: .65rem; margin-top: auto; font-size: .85rem; }
.who .av { width: 2.3rem; height: 2.3rem; font-size: .8rem; }
.who small { display: block; color: var(--muted); font-size: .76rem; }
.result { font-family: var(--f-mono); font-size: .75rem; color: var(--green); background: var(--green-soft); border-radius: .4rem; padding: .25rem .5rem; align-self: flex-start; }

/* Harga */
.toggle { display: inline-flex; border: 1px solid var(--line); border-radius: .7rem; padding: .25rem; background: var(--surface); margin-bottom: 1.8rem; }
.toggle button { border: 0; background: transparent; font: inherit; font-size: .9rem; font-weight: 600; padding: .45rem .9rem; border-radius: .5rem; color: var(--muted); cursor: pointer; }
.toggle button[aria-pressed="true"] { background: var(--red); color: #fff; }
.toggle .save { font-family: var(--f-mono); font-size: .68rem; margin-left: .3rem; }
.plans { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16.5rem, 1fr)); align-items: start; }
.plan { border: 1px solid var(--line); border-radius: 1.1rem; padding: 1.5rem; background: var(--surface); display: flex; flex-direction: column; gap: .9rem; position: relative; }
.plan.pop { border: 2px solid var(--red); box-shadow: var(--shadow); }
.plan .badge { position: absolute; top: -.75rem; left: 1.5rem; background: var(--red); color: #fff; font-size: .72rem; font-weight: 700; padding: .2rem .65rem; border-radius: 1rem; }
.plan h3 { font-size: 1.2rem; }
.plan .tl { color: var(--muted); font-size: .88rem; margin-top: .15rem; }
.price { font-family: var(--f-display); font-weight: 800; font-size: 2rem; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.price small { font-family: var(--f-body); font-size: .9rem; font-weight: 500; color: var(--muted); letter-spacing: 0; }
.per { font-size: .8rem; color: var(--muted); min-height: 1.3em; }
.plan ul { list-style: none; padding: 0; margin: 0; display: grid; gap: .45rem; font-size: .88rem; }
.plan li { display: flex; justify-content: space-between; gap: .75rem; border-bottom: 1px dashed var(--line); padding-bottom: .4rem; }
.plan li span:last-child { font-family: var(--f-mono); font-weight: 600; font-size: .82rem; text-align: right; }
.plan li.no span:last-child { color: var(--muted); font-weight: 500; }
.plan .btn { margin-top: .3rem; }
.addons { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); margin-top: 1.5rem; }
.addon { border: 1px solid var(--line); border-radius: .8rem; padding: .85rem 1rem; display: flex; justify-content: space-between; align-items: center; gap: .75rem; background: var(--surface); font-size: .9rem; }
.addon b { font-family: var(--f-mono); font-size: .85rem; white-space: nowrap; }
.fine { color: var(--muted); font-size: .82rem; margin-top: 1rem; max-width: 46rem; }

/* FAQ */
.faq { display: grid; gap: .6rem; max-width: 50rem; }
.faq details { border: 1px solid var(--line); border-radius: .8rem; background: var(--surface); padding: .2rem 1.1rem; }
.faq summary { cursor: pointer; font-weight: 700; padding-block: .8rem; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
.faq summary::-webkit-details-marker { display: none; }
.faq summary::after { content: "+"; font-family: var(--f-mono); color: var(--red-ink); font-size: 1.1rem; line-height: 1.4; }
.faq details[open] summary::after { content: "−"; }
.faq details p { color: var(--muted); padding-bottom: 1rem; font-size: .95rem; max-width: 42rem; }

/* Kontak */
.kontak { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); max-width: 56rem; }
.kontak .k { background: var(--surface); border: 1px solid var(--line); border-radius: 1rem; padding: 1.2rem 1.3rem; min-width: 0; }
.kontak .k span { display: block; font-family: var(--f-mono); font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin-bottom: .35rem; }
.kontak .k a, .kontak .k address { font-style: normal; font-weight: 600; overflow-wrap: anywhere; text-decoration: none; }
.kontak .k a:hover { text-decoration: underline; }

/* CTA akhir */
.final { background: var(--red); color: #fff; padding-block: 4rem; text-align: center; }
.final h2 { font-size: clamp(1.8rem, 4vw, 2.6rem); line-height: 1.1; max-width: 34rem; margin: 0 auto .8rem; }
.final p { opacity: .92; margin-bottom: 1.6rem; }
.final .btn { background: #fff; color: #b4231a; }
.final .btn:hover { background: #fdecea; }
.final .btn.ghost { background: transparent; color: #fff; border-color: rgba(255,255,255,.55); }
.final .btn.ghost:hover { background: rgba(255,255,255,.12); }
.final .row { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }
footer { padding-block: 2rem; font-size: .85rem; color: var(--muted); border-top: 1px solid var(--line); }
footer .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; }
footer nav { display: flex; flex-wrap: wrap; gap: 1rem; }
footer a { text-decoration: none; }
footer a:hover { color: var(--ink); }
</style>
</head>
<body>
<header class="top">
  <div class="wrap">
    <a class="brand" href="{{ url('/') }}#atas" aria-label="JITU LEAD, ke atas halaman">
      <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="12" cy="12" r="3.2" fill="#fff"/></svg></span>
      JITU LEAD
    </a>
    <nav class="links" aria-label="Menu utama">
      <a href="#fitur">Fitur</a>
      <a href="#cara">Cara mulai</a>
      @if ($testimonials->isNotEmpty())<a href="#testimoni">Testimoni</a>@endif
      <a href="#harga">Harga</a>
      <a href="#tanya">Tanya jawab</a>
      @if ($ownerContact !== [])<a href="#kontak">Kontak</a>@endif
    </nav>
    <div class="top-cta">
      <a class="btn ghost sm login" href="{{ $login }}">Masuk</a>
      @if ($open)<a class="btn sm" href="{{ $cta }}">{{ $ctaShort }}</a>@endif
    </div>
  </div>
</header>

<main id="atas">
<!-- HERO -->
<section class="hero">
  <div class="wrap hero-grid">
    <div>
      <span class="eyebrow">CRM untuk agen &amp; agensi properti</span>
      <h1>Setiap calon pembeli dibalas cepat. <em>Tidak ada lead yang terlewat.</em></h1>
      <p class="lead-text">Lead dari iklan Meta, formulir, landing page, dan WhatsApp masuk ke satu tempat. AI menyiapkan balasan, lead dibagi otomatis ke agen, dan follow-up berjalan sendiri sampai closing.</p>
      <div class="row">
        <a class="btn" href="{{ $cta }}">{{ $ctaText }}</a>
        <a class="btn ghost" href="#harga">Lihat harga</a>
      </div>
      <ul class="chips">
        <li>Siap pakai dalam 10 menit</li>
        <li>WhatsApp cukup scan QR</li>
        <li>Bisa dipasang di HP seperti aplikasi</li>
      </ul>
    </div>

    <div class="stage" aria-label="Cuplikan dashboard JITU LEAD">
      <div class="toast" role="img" aria-label="Contoh notifikasi lead baru di HP">
        <span class="dot" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg></span>
        <div><b>Lead baru · Formulir Meta Ads</b><small>Bpk. Hendra, cari rumah 2 KT di Denpasar</small></div>
      </div>
      <div class="window">
        <div class="window-bar"><i></i><i></i><i></i><span class="url">{{ parse_url(url('/'), PHP_URL_HOST) }}/app/pipeline</span></div>
        <div class="app">
          <div class="side">
            <b>Penjualan</b>
            <span>Dasbor</span>
            <span class="on">Pipeline</span>
            <span>Inbox WA <em class="n" style="font-style:normal">3</em></span>
            <span>Tugas</span>
            <b>Pemasaran</b>
            <span>Landing page</span>
            <span>Follow-up</span>
            <span>Pustaka media</span>
          </div>
          <div class="board">
            <div class="board-head"><h4>Pipeline · Oktober</h4><span class="pill p-warn">2 belum ditanggapi</span></div>
            <div class="kpis">
              <div class="kpi"><small>Lead bulan ini</small><strong>128</strong><span class="up">+18%</span></div>
              <div class="kpi"><small>Survei minggu ini</small><strong>7</strong></div>
              <div class="kpi"><small>Closing</small><strong>9</strong><span class="up">+3</span></div>
            </div>
            <div class="cols">
              <div class="col"><h5>Baru <span>12</span></h5>
                <div class="lc"><b>Hendra W.</b>Rumah 2 KT · Denpasar<div class="meta"><span class="pill p-meta">Meta Ads</span><span class="av a1">RN</span></div></div>
                <div class="lc"><b>Sari Utami</b>Villa · Canggu<div class="meta"><span class="pill p-warn">32 mnt</span><span class="av a2">DM</span></div></div>
              </div>
              <div class="col"><h5>Dihubungi <span>8</span></h5>
                <div class="lc"><b>Yoga P.</b>Kavling · Tabanan<div class="meta"><span class="pill p-wa">WhatsApp</span><span class="av a3">AD</span></div></div>
                <div class="lc"><b>Maya K.</b>Ruko · Kuta<div class="meta"><span class="pill p-web">Landing</span><span class="av a1">RN</span></div></div>
              </div>
              <div class="col"><h5>Survei <span>5</span></h5>
                <div class="lc"><b>Rizal A.</b>Rumah 3 KT · Sanur<div class="meta"><span class="pill p-meta">Meta Ads</span><span class="av a2">DM</span></div></div>
              </div>
              <div class="col"><h5>Negosiasi <span>3</span></h5>
                <div class="lc"><b>Dewi L.</b>Villa · Ubud<div class="meta"><span class="pill p-wa">WhatsApp</span><span class="av a3">AD</span></div></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="phone" aria-label="Cuplikan Inbox WhatsApp dengan draf AI">
        <div class="scr">
          <div class="ph-head"><span class="av a1" aria-hidden="true">HW</span><div><b>Hendra W.</b><small>WhatsApp · baru saja</small></div></div>
          <div class="msgs">
            <div class="bub">Halo, rumah 2 kamar yang di iklan masih ada? Bisa KPR?<time>09.14</time></div>
            <div class="draft"><span class="lbl">Draf AI</span><div>Masih ada, Pak Hendra. Unit 2 KT tipe 45 mulai Rp 685 jt dan bisa KPR. Mau saya kirim brosur dan simulasi cicilannya?</div><div class="acts"><span>Kirim</span><span>Ubah</span></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="pain">
    <div class="wrap pain-grid">
      <div><span class="x">Masalah 01</span><h3>Lead dari iklan dibalas terlambat</h3><p>Calon pembeli menghubungi beberapa agen sekaligus. Yang membalas duluan biasanya yang diajak survei.</p></div>
      <div><span class="x">Masalah 02</span><h3>Chat tercecer di banyak HP</h3><p>Lead ada di WA pribadi agen. Saat agen cuti atau keluar, riwayatnya ikut hilang.</p></div>
      <div><span class="x">Masalah 03</span><h3>Follow-up bergantung ingatan</h3><p>Lead yang belum siap beli dilupakan, padahal banyak yang closing setelah dihubungi beberapa kali.</p></div>
    </div>
  </div>
</section>

<!-- FITUR UTAMA -->
<section class="s" id="fitur">
  <div class="wrap">
    <div class="s-head">
      <span class="eyebrow">Fitur</span>
      <h2>Dari klik iklan sampai serah terima kunci, semuanya tercatat</h2>
      <p class="lead-text">Dibuat khusus untuk alur jualan properti: iklan, WhatsApp, survei lokasi, KPR, dan closing.</p>
    </div>

    <div class="feat">
      <div>
        <span class="eyebrow">Tangkap lead</span>
        <h3>Semua sumber lead masuk ke satu daftar</h3>
        <p class="lead-text">Tidak perlu lagi menyalin dari Meta Ads, Google Form, dan chat WA satu per satu.</p>
        <ul class="ticks">
          <li>Formulir siap pasang di iklan, situs, atau bio Instagram</li>
          <li>Pembuat landing page properti dengan template, tanpa coding</li>
          <li>Chat WhatsApp baru otomatis menjadi lead</li>
          <li>Impor data lama dari Excel dengan template CSV</li>
          <li>Kirim event ke Pixel Meta, TikTok, dan Google agar iklan makin tepat sasaran</li>
        </ul>
      </div>
      <div class="panel" role="img" aria-label="Contoh grafik sumber lead">
        <div class="panel-title">Sumber lead · 30 hari <span>contoh data</span></div>
        <div class="src">
          <div class="src-row"><span>Formulir Meta</span><div class="bar"><i style="width:92%"></i></div><span class="mono">54</span></div>
          <div class="src-row"><span>WhatsApp</span><div class="bar"><i style="width:63%"></i></div><span class="mono">37</span></div>
          <div class="src-row"><span>Landing page</span><div class="bar"><i style="width:41%"></i></div><span class="mono">24</span></div>
          <div class="src-row"><span>Impor CSV</span><div class="bar"><i style="width:15%"></i></div><span class="mono">9</span></div>
          <div class="src-row"><span>Input manual</span><div class="bar"><i style="width:7%"></i></div><span class="mono">4</span></div>
        </div>
        <div class="src-foot"><span class="pill p-meta">Pixel Meta aktif</span><span class="pill p-web">3 landing page</span><span class="pill p-wa">2 nomor WA</span></div>
      </div>
    </div>

    <div class="feat flip">
      <div>
        <span class="eyebrow">Inbox WhatsApp + AI</span>
        <h3>AI menyiapkan balasan, agen tinggal menekan kirim</h3>
        <p class="lead-text">AI membaca data properti Anda, harga, lokasi, dan aturan KPR, lalu menyusun jawaban dalam bahasa yang sopan dan singkat.</p>
        <ul class="ticks">
          <li>Hubungkan WhatsApp cukup dengan scan QR, atau pakai WhatsApp Business API resmi</li>
          <li>Semua chat tim di satu Inbox, lengkap dengan foto yang dikirim pelanggan</li>
          <li>Kirim brosur PDF, foto unit, dan link dari pustaka media berfolder</li>
          <li>AI berhenti saat agen mengetik, dan menyerahkan chat ke agen untuk pertanyaan sulit</li>
          <li>Notifikasi langsung di HP dan PC saat ada pesan atau lead baru</li>
        </ul>
      </div>
      <div class="panel" aria-label="Contoh percakapan WhatsApp di Inbox">
        <div class="panel-title">Inbox · Rizal A. <span>WhatsApp · nomor kantor</span></div>
        <div class="msgs" style="padding:0">
          <div class="bub">Selamat siang, ini lokasi persisnya di mana ya? Dekat sekolah?<time>13.02</time></div>
          <div class="bub me">Siang Pak Rizal. Lokasinya di Jl. Bypass Ngurah Rai, 5 menit ke SD dan SMP negeri. Ini foto unit contohnya 👇<time>13.03</time></div>
          <div class="bub me" style="width:62%"><div class="img-msg" aria-hidden="true"></div><time>13.03</time></div>
          <div class="bub">Bagus. Sabtu bisa survei?<time>13.10</time></div>
          <div class="draft"><span class="lbl">Draf AI · dari jadwal survei</span><div>Bisa, Pak. Sabtu ada slot jam 10.00 atau 14.00. Mau yang jam berapa? Nanti saya kirim titik lokasinya.</div><div class="acts"><span>Kirim</span><span>Ubah</span></div></div>
        </div>
      </div>
    </div>

    <div class="feat">
      <div>
        <span class="eyebrow">Pembagian lead</span>
        <h3>Lead dibagi adil, dan tidak ada yang mengendap</h3>
        <p class="lead-text">Atur porsi tiap agen dalam persen. Lead yang tidak ditanggapi dalam batas waktu dipindah ke agen berikutnya.</p>
        <ul class="ticks">
          <li>Porsi per agen, misalnya 40% · 35% · 25%, atau dibagi rata</li>
          <li>Pindah otomatis bila lead belum ditanggapi, dengan batas jumlah pindah</li>
          <li>Pemilik atau team leader bisa ikut menerima lead</li>
          <li>Agen hanya melihat lead miliknya, admin melihat semuanya</li>
        </ul>
      </div>
      <div class="panel" aria-label="Contoh pengaturan pembagian lead">
        <div class="panel-title">Tim · Pembagian lead <span>30 hari terakhir</span></div>
        <div class="team">
          <div class="mem"><span class="av a1" aria-hidden="true">RN</span><div><b>Rina</b><small>Agen · 41 lead</small><div class="bar" style="margin-top:.3rem"><i style="width:40%"></i></div></div><span class="share">40%</span></div>
          <div class="mem"><span class="av a2" aria-hidden="true">DM</span><div><b>Dimas</b><small>Agen · 36 lead</small><div class="bar" style="margin-top:.3rem"><i style="width:35%"></i></div></div><span class="share">35%</span></div>
          <div class="mem"><span class="av a3" aria-hidden="true">AD</span><div><b>Ayu (pemilik)</b><small>Admin, ikut menerima · 25 lead</small><div class="bar" style="margin-top:.3rem"><i style="width:25%"></i></div></div><span class="share">25%</span></div>
        </div>
        <div class="rule"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><div><b>Pindah otomatis aktif.</b> Lead yang belum ditanggapi <span class="mono">30 menit</span> dipindah ke agen berikutnya, maksimal <span class="mono">2×</span>.</div></div>
      </div>
    </div>

    <div class="feat flip">
      <div>
        <span class="eyebrow">Follow-up otomatis</span>
        <h3>Follow-up berjalan sendiri sampai calon pembeli siap</h3>
        <p class="lead-text">Buat urutan pesan sekali, lalu sistem mengirimkannya tepat waktu. Pesan berhenti otomatis begitu pelanggan membalas.</p>
        <ul class="ticks">
          <li>Jadwal per hari, misalnya hari ke-1, 3, dan 7 setelah lead masuk</li>
          <li>Sertakan brosur, foto unit, atau price list dari pustaka media</li>
          <li>Pengingat tugas untuk agen: telepon, survei, kirim penawaran</li>
        </ul>
      </div>
      <div class="panel" aria-label="Contoh urutan follow-up">
        <div class="panel-title">Aturan: Lead iklan Villa Canggu <span>aktif</span></div>
        <ol class="flow">
          <li><span class="day">H+0</span><div class="card"><b>Sapaan &amp; brosur</b><small>Terima kasih sudah bertanya, ini brosur lengkapnya.</small><span class="att">PDF · Brosur-Villa-Canggu.pdf</span></div></li>
          <li><span class="day">H+1</span><div class="card"><b>Tanya jadwal survei</b><small>Kapan Bapak/Ibu ada waktu melihat unit langsung?</small></div></li>
          <li><span class="day">H+3</span><div class="card"><b>Foto progres unit</b><small>Update pembangunan minggu ini.</small><span class="att">Foto · 4 gambar</span></div></li>
          <li><span class="day">H+7</span><div class="card"><b>Promo akhir bulan</b><small>Diskon DP dan biaya KPR berlaku sampai tanggal 30.</small></div></li>
        </ol>
      </div>
    </div>

    <div class="feat">
      <div>
        <span class="eyebrow">Laporan</span>
        <h3>Tahu iklan mana yang menghasilkan closing</h3>
        <p class="lead-text">Lihat perjalanan lead dari masuk sampai closing, hasil tiap sumber iklan, dan kinerja tiap agen.</p>
        <ul class="ticks">
          <li>Konversi per tahap pipeline dan per sumber iklan</li>
          <li>Jumlah lead dan closing tiap agen</li>
          <li>Alasan lead batal dicatat: harga, lokasi, tidak lolos KPR, dan lainnya</li>
          <li>Ekspor ke CSV kapan saja</li>
        </ul>
      </div>
      <div class="panel" role="img" aria-label="Contoh corong penjualan: 128 lead, 92 dihubungi, 31 survei, 14 negosiasi, 9 closing">
        <div class="panel-title">Corong penjualan · Oktober <span>contoh data</span></div>
        <div class="funnel">
          <div class="fn-row"><span>Lead masuk</span><div class="fn-bar" style="width:100%"></div><span class="mono">128</span></div>
          <div class="fn-row"><span>Dihubungi</span><div class="fn-bar" style="width:72%;--o:.85"></div><span class="mono">92</span></div>
          <div class="fn-row"><span>Survei</span><div class="fn-bar" style="width:24%;--o:.7"></div><span class="mono">31</span></div>
          <div class="fn-row"><span>Negosiasi</span><div class="fn-bar" style="width:11%;--o:.55"></div><span class="mono">14</span></div>
          <div class="fn-row"><span>Closing</span><div class="fn-bar" style="width:7%;--o:.45"></div><span class="mono">9</span></div>
        </div>
        <p class="fn-note">Konversi lead ke closing <span class="mono">7,0%</span> · nilai transaksi tercatat per lead</p>
      </div>
    </div>
  </div>
</section>

<!-- FITUR LAIN -->
<section class="s alt">
  <div class="wrap">
    <div class="s-head">
      <span class="eyebrow">Juga termasuk</span>
      <h2>Hal-hal kecil yang membuat tim tetap rapi</h2>
    </div>
    <div class="more">
      <div><span class="ic"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg></span><h3>Terasa seperti aplikasi di HP</h3><p>Pasang ke layar utama HP, lengkap dengan menu bawah dan tombol tambah lead.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M3 7h6l2 2h10v10H3z"/></svg></span><h3>Pustaka media berfolder</h3><p>Simpan brosur, price list, foto, dan video per proyek. Kirim dari Inbox dengan dua klik.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/></svg></span><h3>Tugas &amp; aksi berikutnya</h3><p>Setiap lead punya langkah berikutnya. Yang terlambat diberi tanda merah.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/></svg></span><h3>Peran tim yang jelas</h3><p>Admin, team leader, dan agen dengan hak akses berbeda. Nonaktifkan agen tanpa kehilangan datanya.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z"/><path d="M4 7v10c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></span><h3>Backup harian otomatis</h3><p>Data dicadangkan setiap malam dan disalin ke penyimpanan terpisah di luar server.</p></div>
      <div><span class="ic"><svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg></span><h3>Data milik Anda</h3><p>Ekspor kapan saja. Turun paket atau berhenti berlangganan tidak menghapus data.</p></div>
    </div>
  </div>
</section>

<!-- CARA MULAI -->
<section class="s" id="cara">
  <div class="wrap">
    <div class="s-head">
      <span class="eyebrow">Cara mulai</span>
      <h2>Mulai menerima lead hari ini juga</h2>
    </div>
    <div class="steps">
      <div class="step"><h3>Daftar dan undang tim</h3><p>Buat akun agensi, tambahkan agen, lalu atur porsi pembagian lead.</p><div class="time">± 3 menit</div></div>
      <div class="step"><h3>Sambungkan WhatsApp dan iklan</h3><p>Scan QR dari HP kantor, lalu pasang formulir atau landing page di iklan Anda.</p><div class="time">± 5 menit</div></div>
      <div class="step"><h3>Isi data properti untuk AI</h3><p>Masukkan harga, lokasi, dan tanya jawab umum. AI mulai menyiapkan balasan.</p><div class="time">± 10 menit</div></div>
    </div>
  </div>
</section>

<!-- TESTIMONI: hanya tampil bila config/landing.php berisi data -->
@if ($testimonials->isNotEmpty())
<section class="s alt" id="testimoni">
  <div class="wrap">
    <div class="s-head">
      <span class="eyebrow">Testimoni</span>
      <h2>Cerita dari agen yang sudah memakai JITU LEAD</h2>
    </div>
    <div class="quotes">
      @foreach ($testimonials as $t)
        <figure class="quote" style="margin:0">
          <blockquote>{{ $t['quote'] }}</blockquote>
          @if (filled($t['result'] ?? null))<span class="result">{{ $t['result'] }}</span>@endif
          <figcaption class="who"><span class="av a{{ ($loop->index % 3) + 1 }}" aria-hidden="true">{{ $initials($t['name']) }}</span><div><b>{{ $t['name'] }}</b>@if (filled($t['role'] ?? null))<small>{{ $t['role'] }}</small>@endif</div></figcaption>
        </figure>
      @endforeach
    </div>
  </div>
</section>
@endif

<!-- HARGA -->
<section class="s" id="harga">
  <div class="wrap">
    <div class="s-head">
      <span class="eyebrow">Harga</span>
      <h2>Harga tetap per tim, bukan per pengguna</h2>
      <p class="lead-text">Coba gratis {{ $trialDays }} hari{{ $trialPlan ? ' dengan fitur paket '.$trialPlan : '' }}.@if ($yearMonths) Bayar tahunan cukup {{ $yearMonths }} bulan, gratis {{ 12 - $yearMonths }} bulan.@endif</p>
    </div>
    <div class="toggle" role="group" aria-label="Pilih periode tagihan">
      <button type="button" id="bulanan" aria-pressed="true">Bulanan</button>
      <button type="button" id="tahunan" aria-pressed="false">Tahunan @if ($yearMonths)<span class="save">−{{ 12 - $yearMonths }} bln</span>@endif</button>
    </div>
    <div class="plans">
      @foreach ($plans as $plan)
        <div class="plan {{ $plan['popular'] ? 'pop' : '' }}">
          @if ($plan['popular'])<span class="badge">Paling populer</span>@endif
          <div><h3>{{ $plan['name'] }}</h3><p class="tl">{{ $plan['tagline'] }}</p></div>
          <div>
            <div class="price" data-m="{{ $num($plan['price']['monthly']) }}" data-y="{{ $num($plan['price']['yearly']) }}">{{ $rp($plan['price']['monthly']) }}<small> / bulan</small></div>
            <div class="per" data-m="" data-y="setara {{ $rp(round($plan['price']['yearly'] / 12)) }} / bulan"></div>
          </div>
          <ul>
            @foreach ($plan['limits'] as $lk => $lv)<li><span>{{ $limitLabels[$lk] }}</span><span>{{ $limitText($lv) }}</span></li>@endforeach
            @foreach ($plan['features'] as $fk => $fv)<li class="{{ $fv ? '' : 'no' }}"><span>{{ $featureLabels[$fk] }}</span><span>{{ $fv ? 'ya' : 'tidak' }}</span></li>@endforeach
          </ul>
          <a class="btn {{ $plan['popular'] ? '' : 'ghost' }}" href="{{ $cta }}">{{ $ctaText }}</a>
        </div>
      @endforeach
    </div>
    <div class="addons" aria-label="Tambahan per bulan">
      @foreach ($addons as $addon)<div class="addon"><span>{{ $addon['unit_label'] }}</span><b>{{ $rp($addon['price']) }}</b></div>@endforeach
    </div>
    <p class="fine">Tambahan ditagih per bulan. Biaya template WhatsApp Business API resmi ditagih langsung oleh Meta ke akun Anda dan tidak termasuk paket.</p>
  </div>
</section>

<!-- FAQ -->
<section class="s alt" id="tanya">
  <div class="wrap">
    <div class="s-head"><span class="eyebrow">Tanya jawab</span><h2>Yang sering ditanyakan</h2></div>
    <div class="faq">
      @foreach ($faq as $item)
      <details><summary>{{ $item['q'] }}</summary><p>{{ $item['a'] }}</p></details>
      @endforeach
    </div>
  </div>
</section>

@if ($ownerContact !== [])
<section class="s" id="kontak">
  <div class="wrap">
    <div class="s-head"><span class="eyebrow">Kontak</span><h2>Hubungi kami</h2></div>
    <div class="kontak">
      @isset($ownerContact['email'])<div class="k"><span>Email</span><a href="mailto:{{ $ownerContact['email'] }}">{{ $ownerContact['email'] }}</a></div>@endisset
      @isset($ownerContact['phone'])<div class="k"><span>Telepon / WhatsApp</span>@isset($ownerContact['phone_digits'])<a href="tel:+{{ $ownerContact['phone_digits'] }}">{{ $ownerContact['phone'] }}</a> <a href="https://wa.me/{{ $ownerContact['phone_digits'] }}" rel="noopener" style="font-weight:500">Chat WhatsApp</a>@else{{ $ownerContact['phone'] }}@endisset</div>@endisset
      @isset($ownerContact['address'])<div class="k"><span>{{ $ownerContact['address_label'] }}</span><address>{{ $ownerContact['address'] }}</address></div>@endisset
    </div>
  </div>
</section>
@endif

<section class="final">
  <div class="wrap">
    <h2>Mulai balas lead lebih cepat dari pesaing Anda</h2>
    <p>@if ($open)Gratis {{ $trialDays }} hari. Setelah itu @endif{{ $open ? 'mulai' : 'Mulai' }} dari {{ $rp($cheapest) }} per bulan.</p>
    <div class="row">
      <a class="btn" href="{{ $cta }}">{{ $ctaText }}</a>
      <a class="btn ghost" href="#harga">Bandingkan paket</a>
      @if ($wa)<a class="btn ghost" href="{{ $wa }}" rel="noopener">Tanya dulu lewat WhatsApp</a>@endif
    </div>
  </div>
</section>
</main>

<footer>
  <div class="wrap">
    <span>&copy; {{ date('Y') }} JITU LEAD</span>
    <nav aria-label="Tautan bawah">
      <a href="{{ url('/faq') }}">FAQ</a>
      <a href="{{ url('/kontak') }}">Kontak</a>
      <a href="{{ url('/kebijakan-privasi') }}">Kebijakan Privasi</a>
      <a href="{{ url('/syarat-layanan') }}">Syarat Layanan</a>
      <a href="{{ url('/kebijakan-pengembalian-dana') }}">Pengembalian Dana</a>
      <a href="{{ $login }}">Masuk</a>
    </nav>
  </div>
</footer>

<script>
(function () {
  var m = document.getElementById('bulanan'), y = document.getElementById('tahunan');
  function set(yearly) {
    m.setAttribute('aria-pressed', String(!yearly));
    y.setAttribute('aria-pressed', String(yearly));
    document.querySelectorAll('.price').forEach(function (el) {
      el.innerHTML = 'Rp ' + (yearly ? el.dataset.y : el.dataset.m) + '<small> / ' + (yearly ? 'tahun' : 'bulan') + '</small>';
    });
    document.querySelectorAll('.per').forEach(function (el) { el.textContent = yearly ? el.dataset.y : el.dataset.m; });
  }
  m.addEventListener('click', function () { set(false); });
  y.addEventListener('click', function () { set(true); });
})();
</script>
</body>
</html>
