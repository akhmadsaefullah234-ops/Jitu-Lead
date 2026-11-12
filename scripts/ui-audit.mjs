// UI audit for phones and desktops (docs/ui-audit.md): opens every page of Toko Demo and Agensi Demo at several
// widths and colour schemes, saves a full-page screenshot, and measures in the page itself:
//
//   hscroll   the page is wider than the screen (scrollWidth > innerWidth, measured with overflow-x: hidden off,
//             so content the theme cuts off counts too)
//   overflow  an element sticks out of the viewport (and is not inside a container that scrolls sideways)
//   covered   an element whose middle is under another element (a sticky header, the tab bar, a floating button,
//             a toast), checked with the page scrolled to the top and to the bottom (elementFromPoint)
//   touch     a button, link or field under 44 x 44 px (phones and tablets only; links inside running text skipped)
//   smalltext text under 14px
//   blank     a panel at least 250px tall that is empty, or whose content ends far above its bottom edge
//
//   npm run ui:audit                       # every page, 320 375 414 768 1280, light and dark
//   npm run ui:audit -- --widths=375 --schemes=light --only=inbox,pipeline --out=storage/app/ui-audit/after --concurrency=4
//
// Needs the demo data (php artisan db:seed --class=TokoDemoSeeder and --class=AgensiDemoSeeder) and the app
// running on --base (default http://127.0.0.1:8000) with CACHE_STORE=array, so logging in is never throttled.
// Writes <out>/report.json, <out>/summary.md and <out>/shots/*.jpg. Playwright with Chromium must be installed
// (globally, or npm i -D playwright && npx playwright install chromium); CHROMIUM_PATH picks another binary.
import { mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const args = Object.fromEntries(process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => {
    const [k, ...v] = a.slice(2).split('=');
    return [k, v.join('=') || 'true'];
}));
const BASE = (args.base || process.env.UI_AUDIT_BASE || 'http://127.0.0.1:8000').replace(/\/$/, '');
const WIDTHS = (args.widths || '320,375,414,768,1280').split(',').map(Number);
const SCHEMES = (args.schemes || 'light,dark').split(',');
const ONLY = args.only ? args.only.split(',') : null;
const OUT = args.out || 'storage/app/ui-audit/latest';
const SHOTS = args.shots !== 'false';
const HEIGHT = { 320: 640, 375: 812, 414: 896, 768: 1024, 1280: 800 };

// One entry per screen. `path` is after /app/{tenant} (or absolute when it starts with "/" and `public` is set).
// `open` runs before measuring: click (selector), goto (first href matching a selector), wait (ms).
const PAGES = [
    { key: 'dasbor', name: 'Dasbor', path: '' },
    { key: 'pipeline', name: 'Pipeline', path: '/pipeline' },
    { key: 'lead-daftar', name: 'Lead: daftar', path: '/leads' },
    { key: 'lead-detail', name: 'Lead: detail', path: '/leads', open: [{ goto: '.fi-ta-row a[href*="/leads/"]' }] },
    { key: 'lead-buat', name: 'Lead: buat', path: '/leads/create' },
    { key: 'inbox', name: 'Inbox: daftar', path: '/inbox' },
    { key: 'inbox-buka', name: 'Inbox: percakapan terbuka', path: '/inbox', open: [{ click: '.wa-item' }, { wait: 800 }] },
    { key: 'inbox-menu', name: 'Inbox: menu ⋯', path: '/inbox', open: [{ click: '.wa-item' }, { wait: 800 }, { click: '.wa-more button' }, { wait: 400 }] },
    { key: 'inbox-lead', name: 'Inbox: panel lead', path: '/inbox', open: [{ click: '.wa-item' }, { wait: 800 }, { click: '.wa-name' }, { wait: 400 }] },
    { key: 'tugas', name: 'Tugas', path: '/tasks' },
    { key: 'pengiriman', name: 'Pengiriman', path: '/kiriman', tenant: 'toko-demo' },
    { key: 'follow-up', name: 'Follow-up otomatis', path: '/follow-up-rules' },
    { key: 'landing-daftar', name: 'Halaman landing: daftar', path: '/landing-pages' },
    { key: 'landing-builder', name: 'Halaman landing: builder', path: '/landing-pages', open: [{ goto: '.fi-ta-row a[href*="/landing-pages/"][href$="/edit"]' }] },
    { key: 'katalog-daftar', name: 'Properti/Produk: daftar', path: '/properties' },
    { key: 'katalog-edit', name: 'Properti/Produk: edit', path: '/properties', open: [{ goto: '.fi-ta-row a[href*="/properties/"][href$="/edit"]' }], tabs: true },
    { key: 'formulir', name: 'Formulir', path: '/lead-forms' },
    { key: 'formulir-edit', name: 'Formulir: edit', path: '/lead-forms', open: [{ goto: '.fi-ta-row a[href*="/lead-forms/"][href$="/edit"]' }] },
    { key: 'impor-lead', name: 'Impor lead', path: '/import-leads' },
    { key: 'laporan', name: 'Laporan', path: '/reports' },
    { key: 'laporan-harian', name: 'Laporan harian', path: '/laporan-harian' },
    { key: 'wa', name: 'Pengaturan: WhatsApp', path: '/wa-channels' },
    { key: 'ai-mode', name: 'Pengaturan: AI Asisten', path: '/ai-modes' },
    { key: 'ai-pengetahuan', name: 'Pengaturan: Pengetahuan AI', path: '/ai-knowledge' },
    { key: 'ai-usulan', name: 'Pengaturan: Usulan Q&A', path: '/ai-suggestions' },
    { key: 'pelacakan', name: 'Pengaturan: Pelacakan iklan', path: '/tracking-settings' },
    { key: 'tim', name: 'Pengaturan: Tim', path: '/team/teams' },
    { key: 'langganan', name: 'Pengaturan: Langganan', path: '/subscription' },
    { key: 'alasan-gugur', name: 'Pengaturan: Alasan gugur', path: '/lost-reasons' },
    { key: 'tahap', name: 'Pengaturan: Tahap', path: '/stages' },
    { key: 'sumber', name: 'Pengaturan: Sumber lead', path: '/lead-sources' },
    { key: 'otomasi-tahap', name: 'Pengaturan: Otomatisasi tahap', path: '/otomatisasi-tahap', tenant: 'toko-demo' },
    { key: 'notifikasi', name: 'Pengaturan: Notifikasi', path: '/notifications' },
    { key: 'privasi', name: 'Pengaturan: Data dan privasi', path: '/data-privacy' },
    { key: 'pengiriman-atur', name: 'Pengaturan: Pengiriman', path: '/pengiriman', tenant: 'toko-demo' },
    { key: 'impor-resi', name: 'Impor resi', path: '/import-resi', tenant: 'toko-demo' },
    { key: 'media', name: 'Pustaka media', path: '/media-assets' },
    { key: 'tutorial', name: 'Tutorial', path: '/tutorial' },
    { key: 'tutorial-bab', name: 'Tutorial: bab', path: '/tutorial?bab=inbox', tenant: 'toko-demo' },
    { key: 'menu-hp', name: 'Menu samping (HP)', path: '', open: [{ click: '.jl-tabbar button[aria-label="Buka semua menu"]' }, { wait: 400 }], phoneOnly: true },
    // Public pages (no login)
    { key: 'pub-beranda', name: 'Publik: beranda', path: '/', public: true },
    { key: 'pub-harga', name: 'Publik: harga', path: '/#harga', public: true },
    { key: 'pub-masuk', name: 'Publik: masuk', path: '/app/login', public: true },
    { key: 'pub-daftar', name: 'Publik: daftar', path: '/app/register', public: true },
    { key: 'pub-landing', name: 'Publik: halaman landing', path: null, public: true, tenant: 'toko-demo', from: '/landing-pages', fromLink: 'a[href*="/l/"], a[href*="/p/"]' },
    { key: 'pub-formulir', name: 'Publik: formulir', path: null, public: true, tenant: 'toko-demo', from: '/lead-forms', fromLink: 'a[href*="/f/"]' },
];
const TENANTS = (args.tenants || 'toko-demo,agensi-demo').split(',');

// Measures one screen. Runs inside the page.
async function measure({ touchCheck }) {
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const doc = document.documentElement;
    const label = (el) => {
        if (!el || !el.tagName) return '';
        const id = el.id ? `#${el.id}` : '';
        const cls = [...el.classList].filter((c) => !c.includes(':') && !c.includes('[')).slice(0, 3).map((c) => `.${c}`).join('');
        const text = (el.getAttribute('aria-label') || el.innerText || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        return `${el.tagName.toLowerCase()}${id}${cls}${text ? ` "${text}"` : ''}`;
    };
    const shown = (el) => {
        for (let e = el; e && e !== document.body; e = e.parentElement) {
            const s = getComputedStyle(e);
            if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return false;
            if (e.getAttribute('aria-hidden') === 'true' || e.hasAttribute('inert')) return false;
            // The body of a closed <details> keeps its boxes in Chrome but is not on screen.
            if (e.parentElement && e.parentElement.tagName === 'DETAILS' && !e.parentElement.open && e.tagName !== 'SUMMARY') return false;
        }
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    };
    // An ancestor that scrolls sideways or clips: its overflow is intentional (a swipeable row) or already cut.
    const scrollsX = (el) => {
        for (let e = el.parentElement; e && e !== document.body && e !== doc; e = e.parentElement) {
            const ox = getComputedStyle(e).overflowX;
            if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') return e;
        }
        return null;
    };
    // The off-canvas sidebar (closed) and other things moved fully out of sight on purpose.
    const offCanvas = (el) => !!el.closest('.fi-sidebar:not(.fi-sidebar-open), [x-cloak], template, .sr-only, .fi-sr-only, .fi-sidebar-close-overlay');
    const all = [...document.body.querySelectorAll('*')].filter((el) => !['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'svg', 'path'].includes(el.tagName));
    const visibleEls = all.filter(shown);

    // The theme sets overflow-x: hidden on html and body, which hides a sideways scroll but cuts off whatever sticks
    // out. Measure the real width with that switched off for a moment.
    const saved = [doc.style.overflowX, document.body.style.overflowX];
    doc.style.overflowX = 'visible';
    document.body.style.overflowX = 'visible';
    const scrollWidth = Math.max(doc.scrollWidth, document.body.scrollWidth);
    [doc.style.overflowX, document.body.style.overflowX] = saved;
    const result = { hscroll: scrollWidth > vw + 1, scrollWidth, overflow: [], covered: [], touch: [], smalltext: [], blank: [] };

    // overflow
    const flagged = new Set();
    for (const el of visibleEls) {
        if (offCanvas(el)) continue;
        const r = el.getBoundingClientRect();
        if ((r.right > vw + 1 || r.left < -1) && !scrollsX(el) && getComputedStyle(el).position !== 'fixed') {
            if ([...flagged].some((f) => f.contains(el))) continue;
            flagged.add(el);
            result.overflow.push({ el: label(el), left: Math.round(r.left), right: Math.round(r.right) });
        }
    }

    // covered: interactive and text elements whose middle another element sits on
    const interactive = 'a[href], button, input:not([type=hidden]), select, textarea, [role=button], [role=tab], [role=menuitem], summary, label, h1, h2, h3, p, td, th, li';
    const scrollerY = (el) => {
        for (let e = el.parentElement; e && e !== document.body && e !== doc; e = e.parentElement) {
            const oy = getComputedStyle(e).overflowY;
            if ((oy === 'auto' || oy === 'scroll') && e.scrollHeight > e.clientHeight + 1) return e;
        }
        return null;
    };
    // Smooth scrolling would still be under way when the page is measured.
    doc.style.scrollBehavior = 'auto';
    const checkCovered = (where) => {
        for (const el of document.querySelectorAll(interactive)) {
            if (!shown(el) || offCanvas(el)) continue;
            const r = el.getBoundingClientRect();
            const x = r.left + r.width / 2;
            const y = r.top + Math.min(r.height / 2, 20);
            if (x < 0 || x >= vw || y < 0 || y >= vh || r.height < 4) continue;
            const hit = document.elementFromPoint(x, y);
            if (!hit || el === hit || el.contains(hit) || hit.contains(el)) continue;
            // Filament wraps a field in a label or a styled wrapper; those count as the same control.
            if (hit.closest('label, .fi-input-wrp, .fi-fo-field-wrp') && hit.closest('label, .fi-input-wrp, .fi-fo-field-wrp').contains(el)) continue;
            let cover = hit;
            for (let e = hit; e && e !== document.body; e = e.parentElement) {
                const p = getComputedStyle(e).position;
                if (p === 'fixed' || p === 'sticky') { cover = e; break; }
            }
            // A modal, an open drawer or a dropdown covers the page on purpose.
            if (cover.closest('.fi-modal-window, .fi-modal-close-overlay, .fi-sidebar-close-overlay, .fi-dropdown-panel, .fi-sidebar-open')) continue;
            if (cover.contains(el)) continue;
            // Inside a panel that scrolls on its own (the sidebar, a chat list): scrolling that panel brings it out.
            if (scrollerY(el) && !scrollerY(el).contains(cover)) continue;
            // A bar fixed to the top hides what scrolls under it, and one fixed to the bottom what is still to come:
            // that is only a problem when scrolling can no longer bring it out (top bars at the top of the page,
            // bottom bars at the end of it).
            const fixed = cover !== hit || getComputedStyle(cover).position === 'fixed';
            if (fixed) {
                const cr = cover.getBoundingClientRect();
                const atTop = cr.top + cr.height / 2 < vh / 2;
                if ((where === 'atas') !== atTop) continue;
            }
            const key = `${label(el)}|${label(cover)}`;
            if (result.covered.some((c) => c.key === key)) continue;
            result.covered.push({ key, where, el: label(el), by: label(cover) });
        }
    };
    const settle = () => new Promise((resolve) => setTimeout(resolve, 250));
    window.scrollTo(0, 0);
    await settle();
    checkCovered('atas');
    // Lazy widgets (the dashboard) load as they come into view: scroll down until the page stops growing.
    for (let i = 0, last = -1; i < 12 && doc.scrollHeight !== last; i++) {
        last = doc.scrollHeight;
        window.scrollTo(0, doc.scrollHeight);
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    await settle();
    checkCovered('bawah');
    window.scrollTo(0, 0);
    await settle();

    // touch targets
    if (touchCheck) {
        const targets = document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], [role=tab], [role=switch], summary');
        for (const el of targets) {
            if (!shown(el) || offCanvas(el)) continue;
            // A link inside a sentence is exempt (WCAG 2.5.8)
            if (el.tagName === 'A' && getComputedStyle(el).display === 'inline' && el.parentElement && /^(P|LI|SPAN|SMALL|TD)$/.test(el.parentElement.tagName)
                && (el.parentElement.innerText || '').trim().length > (el.innerText || '').trim().length + 10) continue;
            let r = el.getBoundingClientRect();
            // A checkbox or radio counts with the label wrapped around it
            const wrap = el.matches('input[type=checkbox], input[type=radio]') ? el.closest('label') : null;
            if (wrap) r = wrap.getBoundingClientRect();
            if (r.width < 44 || r.height < 44) {
                result.touch.push({ el: label(el), w: Math.round(r.width), h: Math.round(r.height) });
            }
        }
    }

    // small text: elements that hold text themselves
    for (const el of visibleEls) {
        if (offCanvas(el)) continue;
        const own = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 1);
        if (!own) continue;
        const fs = parseFloat(getComputedStyle(el).fontSize);
        if (fs < 14) result.smalltext.push({ el: label(el), px: fs });
    }

    // blank: a visible panel (background, border or shadow) at least 250px tall with nothing in it, or whose content
    // ends far above its bottom edge
    const contentBottom = (el) => {
        let bottom = -Infinity;
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        for (let n = walker.currentNode; n; n = walker.nextNode()) {
            if (n.nodeType === 3) {
                if (!n.textContent.trim()) continue;
                const range = document.createRange();
                range.selectNodeContents(n);
                const rr = range.getBoundingClientRect();
                if (rr.height > 0 && shown(n.parentElement)) bottom = Math.max(bottom, rr.bottom);
            } else if (n.matches && n.matches('img, svg, canvas, video, iframe, input, select, textarea, button, [role=img], .fi-badge')) {
                if (shown(n)) bottom = Math.max(bottom, n.getBoundingClientRect().bottom);
            }
        }
        return bottom;
    };
    const panels = visibleEls.filter((el) => {
        if (offCanvas(el)) return false;
        const r = el.getBoundingClientRect();
        if (r.height < 250 || r.width < Math.min(200, vw * 0.5)) return false;
        const s = getComputedStyle(el);
        const bg = s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent';
        const border = parseFloat(s.borderTopWidth) > 0 && parseFloat(s.borderBottomWidth) > 0;
        return (bg || border || s.boxShadow !== 'none') && !['BODY', 'HTML', 'MAIN'].includes(el.tagName)
            && !el.matches('.fi-main-ctn, .fi-main, .fi-layout, .fi-sidebar, .fi-page, .fi-body, .fi-topbar');
    });
    const blanks = [];
    for (const el of panels) {
        const r = el.getBoundingClientRect();
        const pb = parseFloat(getComputedStyle(el).paddingBottom) || 0;
        const cb = contentBottom(el);
        const gap = cb === -Infinity ? r.height : r.bottom - pb - cb;
        if (gap >= Math.max(220, vh * 0.35)) blanks.push({ node: el, el: label(el), gap: Math.round(gap), height: Math.round(r.height), empty: cb === -Infinity });
    }
    // innermost only
    result.blank = blanks.filter((b) => !blanks.some((o) => o !== b && b.node.contains(o.node))).map(({ node, ...b }) => b);

    return result;
}

async function login(browser, tenant) {
    const context = await browser.newContext({ locale: 'id-ID', timezoneId: 'Asia/Jakarta' });
    const page = await context.newPage();
    await page.goto(`${BASE}/app/login`);
    await page.locator('input[type="email"]').fill(`admin@${tenant}.test`);
    await page.locator('input[type="password"]').fill('password');
    await page.locator('input[type="password"]').press('Enter');
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
    const state = await context.storageState();
    await context.close();
    return state;
}

const browser = await chromium.launch({ args: ['--lang=id-ID'], ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
mkdirSync(join(OUT, 'shots'), { recursive: true });
const states = {};
for (const t of TENANTS) states[t] = await login(browser, t);
const rows = [];
const publicUrls = {};
const CONCURRENCY = Number(args.concurrency || 4);

// Public pages reached from a link in the panel (a landing page, a form): find their address once per agency.
for (const def of PAGES.filter((d) => d.from && (!ONLY || ONLY.includes(d.key)))) {
    for (const tenant of TENANTS.filter((t) => !def.tenant || def.tenant === t)) {
        const context = await browser.newContext({ storageState: states[tenant] });
        const page = await context.newPage();
        try {
            await page.goto(`${BASE}/app/${tenant}${def.from}`, { waitUntil: 'networkidle' });
            publicUrls[`${tenant}:${def.key}`] = new URL(await page.locator(def.fromLink).first().getAttribute('href', { timeout: 5000 }), BASE).href;
        } catch (e) {
            console.log(`ERR ${def.key} ${tenant}: no link (${String(e.message).split('\n')[0]})`);
        }
        await context.close();
    }
}

const jobs = [];
for (const tenant of TENANTS) {
    for (const def of PAGES) {
        if (ONLY && !ONLY.includes(def.key)) continue;
        if (def.tenant && def.tenant !== tenant) continue;
        // Public pages without a tenant are audited once.
        if (def.public && !def.tenant && tenant !== TENANTS[0]) continue;
        for (const scheme of SCHEMES) {
            for (const width of WIDTHS) {
                if (def.phoneOnly && width >= 1024) continue;
                jobs.push({ tenant, def, scheme, width });
            }
        }
    }
}

async function audit({ tenant, def, scheme, width }) {
    const phone = width < 1024;
    const context = await browser.newContext({
        viewport: { width, height: HEIGHT[width] || 900 },
        deviceScaleFactor: 1,
        isMobile: width < 768,
        hasTouch: phone,
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        colorScheme: scheme,
        storageState: def.public ? undefined : states[tenant],
    });
    await context.route((url) => !url.href.startsWith(BASE), (route) => route.abort());
    const page = await context.newPage();
    const row = { key: def.key, name: def.name, tenant: def.public && !def.tenant ? '-' : tenant, width, scheme };
    try {
        const url = def.from ? publicUrls[`${tenant}:${def.key}`] : (def.public ? `${BASE}${def.path}` : `${BASE}/app/${tenant}${def.path}`);
        if (!url) throw new Error('alamat tidak ditemukan');
        const response = await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
        row.status = response?.status();
        if (row.status >= 400) throw new Error(`HTTP ${row.status}`);
        for (const step of def.open || []) {
            if (step.click) await page.locator(step.click).filter({ visible: true }).first().click({ timeout: 5000 });
            if (step.goto) {
                const href = await page.locator(step.goto).first().getAttribute('href', { timeout: 5000 });
                await page.goto(new URL(href, BASE).href, { waitUntil: 'networkidle' });
            }
            if (step.wait) await page.waitForTimeout(step.wait);
            await page.waitForLoadState('networkidle').catch(() => {});
        }
        await page.waitForTimeout(300);
        row.url = page.url().replace(BASE, '');
        Object.assign(row, await page.evaluate(measure, { touchCheck: phone }));
        if (SHOTS) {
            row.shot = `shots/${def.key}-${row.tenant}-${width}-${scheme}.jpg`;
            await page.screenshot({ path: join(OUT, row.shot), fullPage: true, type: 'jpeg', quality: 70 });
        }
    } catch (e) {
        row.error = String(e.message || e).split('\n')[0];
    }
    await context.close();
    const n = (k) => (Array.isArray(row[k]) ? row[k].length : 0);
    console.log(`${row.error ? 'ERR ' : ''}${def.key} ${row.tenant} ${width} ${scheme}: hscroll=${row.hscroll ? 'YA' : '-'} overflow=${n('overflow')} covered=${n('covered')} touch=${n('touch')} small=${n('smalltext')} blank=${n('blank')}${row.error ? ` ${row.error}` : ''}`);
    return row;
}

const results = new Array(jobs.length);
let next = 0;
await Promise.all(Array.from({ length: CONCURRENCY }, async () => {
    while (next < jobs.length) {
        const i = next++;
        results[i] = await audit(jobs[i]);
    }
}));
rows.push(...results);
await browser.close();

const ok = rows.filter((r) => !r.error);
const totals = {
    screens: ok.length,
    errors: rows.length - ok.length,
    hscroll: ok.filter((r) => r.hscroll).length,
    overflow: ok.reduce((s, r) => s + r.overflow.length, 0),
    covered: ok.reduce((s, r) => s + r.covered.length, 0),
    touch: ok.reduce((s, r) => s + r.touch.length, 0),
    smalltext: ok.reduce((s, r) => s + r.smalltext.length, 0),
    blank: ok.reduce((s, r) => s + r.blank.length, 0),
};
writeFileSync(join(OUT, 'report.json'), JSON.stringify({ base: BASE, widths: WIDTHS, schemes: SCHEMES, totals, rows }, null, 2));

const lines = [
    `# Hasil audit UI (${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC)`,
    '',
    `Layar diperiksa: ${totals.screens} (gagal dibuka: ${totals.errors}). Lebar: ${WIDTHS.join(', ')}. Mode: ${SCHEMES.join(', ')}.`,
    '',
    '| Ukuran | Jumlah |',
    '| --- | --- |',
    `| Layar dengan scroll horizontal | ${totals.hscroll} |`,
    `| Elemen meluber dari viewport | ${totals.overflow} |`,
    `| Elemen tertutup | ${totals.covered} |`,
    `| Area sentuh < 44px (HP dan tablet) | ${totals.touch} |`,
    `| Teks < 14px | ${totals.smalltext} |`,
    `| Area kosong besar | ${totals.blank} |`,
    '',
    '| Halaman | Akun | Lebar | Mode | Scroll samping | Meluber | Tertutup | Sentuh kecil | Teks kecil | Kosong |',
    '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |',
    ...rows.map((r) => r.error
        ? `| ${r.name} | ${r.tenant} | ${r.width} | ${r.scheme} | gagal: ${r.error.replace(/\|/g, '/')} | | | | | |`
        : `| ${r.name} | ${r.tenant} | ${r.width} | ${r.scheme} | ${r.hscroll ? `ya (${r.scrollWidth}px)` : '-'} | ${r.overflow.length} | ${r.covered.length} | ${r.touch.length} | ${r.smalltext.length} | ${r.blank.length} |`),
];
writeFileSync(join(OUT, 'summary.md'), lines.join('\n') + '\n');
console.log(JSON.stringify(totals));
