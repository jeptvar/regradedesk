import { chromium } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';

mkdirSync('artifacts', { recursive: true });
const wp = (...args) => {
    const r = spawnSync('docker', ['compose', 'run', '--rm', '-T', 'cli', ...args], { encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr + r.stdout);
    return r.stdout.trim();
};
wp('eval-file', 'wp-content/plugins/regradedesk/tests/seed.php');
const fixture = JSON.parse(wp('eval-file', 'wp-content/plugins/regradedesk/tests/browser-fixture.php'));
const credentials = readFileSync('artifacts/demo-login.txt', 'utf8');
const username = credentials.match(/^User: (.+)$/m)[1];
const password = credentials.match(/^Password: (.+)$/m)[1];
const base = 'http://localhost:8097';
const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', e => errors.push(e.message));
try {
    await page.goto(`${base}/wp-login.php`);
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
    await page.goto(`${base}/wp-admin/admin.php?page=second-shelf`);
    await page.getByRole('heading', { name: 'RegradeDesk', exact: true }).waitFor();
    await page.screenshot({ path: 'artifacts/workbench-desktop.png', fullPage: true, animations: 'disabled' });
    const dashboard = await new AxeBuilder({ page }).include('.ss-wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    assert.equal(dashboard.violations.length, 0, JSON.stringify(dashboard.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))));

    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: 'artifacts/workbench-mobile.png', fullPage: true, animations: 'disabled' });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Dashboard fits mobile viewport');
    const dashboardMobile = await new AxeBuilder({ page }).include('.ss-wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    assert.equal(dashboardMobile.violations.length, 0, JSON.stringify(dashboardMobile.violations.map(v => v.id)));
    await page.setViewportSize({ width: 1440, height: 1100 });
    await page.getByRole('link', { name: /^Needs inspection \(/ }).click();
    assert.equal(new URL(page.url()).searchParams.get('state'), 'received');
    assert.equal(await page.locator('.ss-filters [aria-current="page"]').count(), 1);
    for (const text of await page.locator('.ss-table .ss-badge').allTextContents()) assert.equal(text, 'Needs inspection');
    await page.goto(`${base}/wp-admin/admin.php?page=second-shelf`);

    await page.getByLabel('Order ID', { exact: true }).fill(String(fixture.order));
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Find order', exact: true }).click()]);
    await page.getByLabel('Returned item', { exact: true }).selectOption(String(fixture.item));
    await page.getByLabel('This unit has physically arrived').check();
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Start inspection', exact: true }).click()]);
    const received = Number(new URL(page.url()).searchParams.get('unit'));
    assert(received > 0, 'Receipt creates a unit');
    {
        await page.getByLabel('Resale listing title').fill('Arc lamp — browser-tested return');
        await page.getByLabel('Condition', { exact: true }).selectOption('open-box');
        await page.getByLabel('Resale price', { exact: false }).fill('54.00');
        await page.getByLabel('What the buyer should know').fill('Minor scuff on base. Cable included. Box missing. Demo only.');
        await page.getByLabel('Internal inspection notes').fill('BROWSER-PRIVATE-NOTE: keep this out of the product.');
        await page.getByRole('button', { name: 'Choose photos', exact: true }).click();
        await page.getByRole('tab', { name: 'Media Library', exact: true }).click();
        const attachment = page.locator(`.media-modal .attachment[data-id="${fixture.photo}"]`);
        await attachment.waitFor();
        await attachment.click();
        await page.getByRole('button', { name: 'Use these photos', exact: true }).click();
        await page.getByLabel('I reviewed these photos').check();
        await page.getByLabel('I physically inspected').check();
        await page.getByLabel('Inspection decision').selectOption('ready');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save inspection', exact: true }).click()]);
        await page.locator('#ss-create-draft').waitFor();
        assert.equal(await page.getByLabel('Inspection decision').inputValue(), 'ready', 'Sidebar decision is included in the saved form');
        await page.getByLabel('Inspection decision').selectOption('received');
        assert(await page.locator('#ss-create-draft').isDisabled(), 'Unsaved sidebar changes disable draft creation');
        await page.getByLabel('Inspection decision').selectOption('ready');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save inspection', exact: true }).click()]);
        await page.locator('#ss-create-draft').waitFor();
        assert(await page.locator('#ss-create-draft').isEnabled());
        await page.screenshot({ path: 'artifacts/inspection-desktop.png', fullPage: true, animations: 'disabled' });
        const detail = await new AxeBuilder({ page }).include('.ss-wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        assert.equal(detail.violations.length, 0, JSON.stringify(detail.violations.map(v => v.id)));
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: 'artifacts/inspection-mobile.png', fullPage: true, animations: 'disabled' });
        const mobile = await new AxeBuilder({ page }).include('.ss-wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        assert.equal(mobile.violations.length, 0, JSON.stringify(mobile.violations.map(v => v.id)));
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'No mobile horizontal overflow');
        await page.setViewportSize({ width: 1440, height: 1100 });
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Create resale draft', exact: true }).click()]);
    }
    await page.getByRole('link', { name: 'Review resale product', exact: true }).click();
    await page.locator('#title').waitFor();
    assert((await page.locator('#title').inputValue()).includes('Arc lamp'));
    assert.equal(await page.locator('#post_status').inputValue(), 'draft');
    const content = await page.locator('#content').inputValue();
    assert(content.includes('Minor scuff') && !content.includes('BROWSER-PRIVATE-NOTE'));
    await page.screenshot({ path: 'artifacts/resale-draft.png', fullPage: true });

    // A logged-in admin still cannot mutate without a valid nonce.
    const rejected = await page.request.post(`${base}/wp-admin/admin-post.php`, { form: { action: 'second_shelf', operation: 'create', unit_id: received, version: 2 } });
    assert.equal(rejected.status(), 403, 'Missing nonce rejected');
    const customer = await browser.newContext();
    const publicResponse = await customer.request.get(`${base}/wp-admin/admin.php?page=second-shelf`);
    assert(publicResponse.url().includes('wp-login.php'), 'Anonymous visitor redirected to login');
    await customer.close();
    assert.equal(errors.length, 0, JSON.stringify(errors));
    writeFileSync('artifacts/browser-results.json', JSON.stringify({ passed: true, checks: ['desktop/mobile dashboard axe and overflow', 'status filters', 'inspection form and media picker', 'sidebar decision/save and unsaved-change protection', 'desktop/mobile inspection axe', 'mobile overflow', 'draft creation and private-note exclusion', 'nonce and anonymous access', 'no JavaScript errors'] }, null, 2));
    console.log('Browser checks passed; screenshots and results are in artifacts/.');
} catch (error) {
    await page.screenshot({ path: 'artifacts/browser-failure.png', fullPage: true }).catch(() => {});
    console.error('Browser failure URL:', page.url());
    console.error((await page.locator('body').innerText()).slice(0, 1800));
    throw error;
} finally {
    await browser.close();
}
