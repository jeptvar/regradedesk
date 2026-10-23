import { chromium } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';

mkdirSync('.wordpress-org', { recursive: true });
const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined, headless: true });
try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
    const page = await context.newPage();
    const login = readFileSync('artifacts/demo-login.txt', 'utf8');
    await page.goto('http://localhost:8097/wp-login.php');
    await page.locator('#user_login').fill(login.match(/^User: (.+)$/m)[1]);
    await page.locator('#user_pass').fill(login.match(/^Password: (.+)$/m)[1]);
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
    await page.goto('http://localhost:8097/wp-admin/admin.php?page=second-shelf&state=ready');
    await page.locator('.ss-table').waitFor();
    await page.locator('.ss-wrap').screenshot({ path: '.wordpress-org/screenshot-1.png', animations: 'disabled' });
    await Promise.all([page.waitForNavigation(), page.locator('.ss-table .row-title').first().click()]);
    await page.locator('#ss-create-draft').waitFor();
    await page.locator('.ss-wrap').screenshot({ path: '.wordpress-org/screenshot-2.png', animations: 'disabled' });

    const icon = readFileSync('.wordpress-org/icon.svg', 'utf8');
    for (const size of [128, 256]) {
        await page.setViewportSize({ width: size, height: size });
        await page.setContent(`<style>html,body{margin:0}svg{width:100%;height:100%}</style>${icon}`);
        await page.screenshot({ path: `.wordpress-org/icon-${size}x${size}.png` });
    }
    const banner = `<style>*{box-sizing:border-box}html,body{margin:0}body{width:772px;height:250px;background:#f0f6fc;font:16px Arial,sans-serif;color:#1d2327;display:flex;align-items:center;padding:42px;gap:32px}.mark{width:132px;height:132px;flex:none}.mark svg{width:100%;height:100%}h1{font-size:46px;letter-spacing:-1.5px;margin:0 0 12px;font-weight:700}p{font-size:19px;margin:0 0 16px;line-height:1.4}.meta{font-size:13px;color:#50575e;letter-spacing:1.5px}</style><div class="mark">${icon}</div><div><h1>Second Shelf</h1><p>Inspect returns. Prepare resale drafts.</p><div class="meta">BUILT FOR WOOCOMMERCE</div></div>`;
    for (const scale of [1, 2]) {
        const art = await browser.newContext({ viewport: { width: 772, height: 250 }, deviceScaleFactor: scale });
        const canvas = await art.newPage();
        await canvas.setContent(banner);
        await canvas.screenshot({ path: `.wordpress-org/banner-${772 * scale}x${250 * scale}.png` });
        await art.close();
    }
    console.log('Directory screenshots, banners and icons saved in .wordpress-org/.');
} finally {
    await browser.close();
}
