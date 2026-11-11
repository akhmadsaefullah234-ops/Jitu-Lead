// Takes the tutorial screenshots: run through `php artisan tutorial:capture`, which writes the manifest (one entry
// per step with a "shot" in resources/tutorials/*.php), serves the app and turns the PNGs into WebP.
//
//   node scripts/tutorial-capture.mjs <manifest.json>
//
// Needs Playwright with Chromium (npm i -D playwright && npx playwright install chromium) unless it is installed
// globally. Each shot: log in as the demo user in a fresh browser (so a period or filter one shot picks, which the
// app keeps in the session, never shows in the next), open the page, run its steps (click, fill, select, press,
// hover, scroll, wait, waitFor), draw a numbered box on each element to highlight, then save the viewport.
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const manifest = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const browser = await chromium.launch({
    args: ['--lang=id-ID'],
    ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
});
const AVATAR = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#fee2e2"/><text x="32" y="41" font-family="sans-serif" font-size="26" font-weight="700" fill="#b91c1c" text-anchor="middle">A</text></svg>';
let failed = 0;

async function loggedIn(shot) {
    const context = await browser.newContext({
        viewport: shot.viewport,
        deviceScaleFactor: 1,
        isMobile: !!shot.mobile,
        hasTouch: !!shot.mobile,
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        colorScheme: 'light',
    });
    // The container may have no internet: avatars are drawn locally, other outside requests are dropped.
    await context.route((url) => !url.href.startsWith(manifest.base), (route) => route.request().url().includes('ui-avatars.com')
        ? route.fulfill({ contentType: 'image/svg+xml', body: AVATAR })
        : route.abort());

    if (shot.email) {
        const page = await context.newPage();
        await page.goto(`${manifest.base}/app/login`);
        await page.locator('input[type="email"]').fill(shot.email);
        await page.locator('input[type="password"]').fill(shot.password);
        await page.locator('input[type="password"]').press('Enter');
        await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
        await page.close();
    }

    return context;
}

async function run(page, step) {
    if (step.click) {
        await page.locator(step.click).filter({ visible: true }).first().click();
    } else if (step.fill) {
        await page.locator(step.fill[0]).filter({ visible: true }).first().fill(step.fill[1]);
    } else if (step.select) {
        await page.locator(step.select[0]).filter({ visible: true }).first().selectOption(step.select[1]);
    } else if (step.press) {
        await page.locator(step.press[0]).filter({ visible: true }).first().press(step.press[1]);
    } else if (step.scroll) {
        await page.locator(step.scroll).filter({ visible: true }).first().scrollIntoViewIfNeeded();
    } else if (step.hover) {
        await page.locator(step.hover).filter({ visible: true }).first().hover();
    } else if (step.wait) {
        await page.waitForTimeout(step.wait);
    } else if (step.waitFor) {
        await page.locator(step.waitFor).filter({ visible: true }).first().waitFor({ timeout: 15000 });
    }

    await page.waitForLoadState('networkidle').catch(() => {});
}

// Only what is on screen counts: hidden copies (another tab, a closed menu) never match.
const visible = (page, selector) => page.locator(selector).filter({ visible: true }).first();

async function highlight(page, selectors) {
    for (const selector of selectors) {
        if (await visible(page, selector).count() === 0) {
            throw new Error(`highlight not found: ${selector}`);
        }
    }

    // The first element sets the view; the others are drawn where they are, on screen or not.
    await visible(page, selectors[0]).scrollIntoViewIfNeeded({ timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(150);
    const boxes = [];

    for (const selector of selectors) {
        boxes.push(await visible(page, selector).boundingBox());
    }

    await page.evaluate((boxes) => {
        document.querySelectorAll('.tutorial-mark').forEach((el) => el.remove());
        boxes.forEach((b, i) => {
            if (!b) {
                return;
            }

            const pad = 4;
            const box = document.createElement('div');
            box.className = 'tutorial-mark';
            Object.assign(box.style, {
                position: 'fixed', left: `${b.x - pad}px`, top: `${b.y - pad}px`, width: `${b.width + 2 * pad}px`, height: `${b.height + 2 * pad}px`,
                border: '3px solid #dc2626', borderRadius: '8px', boxShadow: '0 0 0 3px rgba(220,38,38,.25)', zIndex: 2147483646, pointerEvents: 'none',
            });
            const badge = document.createElement('div');
            badge.className = 'tutorial-mark';
            badge.textContent = String(i + 1);
            Object.assign(badge.style, {
                position: 'fixed', left: `${Math.max(2, b.x - pad - 12)}px`, top: `${Math.max(2, b.y - pad - 12)}px`, width: '24px', height: '24px',
                borderRadius: '9999px', background: '#dc2626', color: 'white', font: '700 13px/24px system-ui, sans-serif', textAlign: 'center',
                zIndex: 2147483647, pointerEvents: 'none', boxShadow: '0 1px 3px rgba(0,0,0,.3)',
            });
            document.body.append(box, badge);
        });
    }, boxes);
}

for (const shot of manifest.shots) {
    const context = await loggedIn(shot);
    const page = await context.newPage();

    try {
        await page.goto(`${manifest.base}${shot.path}`, { waitUntil: 'networkidle' });
        // No pop-ups, toasts or chat bubbles over the picture.
        await page.addStyleTag({ content: '.fi-no-notification, .jl-support { display: none !important; } *, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }' });

        for (const step of shot.steps) {
            await run(page, step);
        }

        // No focus ring on a field the page focused by itself.
        await page.evaluate(() => document.activeElement?.blur?.());
        await page.waitForTimeout(300);

        if (shot.highlight.length) {
            await highlight(page, shot.highlight);
        }

        await page.screenshot({ path: shot.out, fullPage: false });
        console.log(`ok ${shot.id}`);
    } catch (e) {
        failed++;
        console.error(`FAILED ${shot.id}: ${e.message.split('\n')[0]}`);
    } finally {
        await context.close();
    }
}

await browser.close();
process.exit(failed ? 1 : 0);
