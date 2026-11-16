// Uses only the disposable Docker demo. Creates one synthetic order and resale draft.
import { chromium } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';

const base = 'http://localhost:8097';
const output = 'docs/walkthrough';
const wp = (...args) => {
    const result = spawnSync('docker', ['compose', 'run', '--rm', '--no-deps', '-T', 'cli', ...args], { encoding: 'utf8' });
    if (result.status !== 0) throw new Error(result.stderr + result.stdout);
    return result.stdout.trim();
};
assert.equal(wp('option', 'get', 'siteurl'), base, 'Only run against the local demo');
wp('eval-file', 'wp-content/plugins/regradedesk/tests/seed.php');
const fixture = JSON.parse(wp('eval-file', 'wp-content/plugins/regradedesk/tests/browser-fixture.php'));
const login = readFileSync('artifacts/demo-login.txt', 'utf8');
mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
    await page.goto(`${base}/wp-login.php`);
    await page.locator('#user_login').fill(login.match(/^User: (.+)$/m)[1]);
    await page.locator('#user_pass').fill(login.match(/^Password: (.+)$/m)[1]);
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
    await page.goto(`${base}/wp-admin/admin.php?page=second-shelf&order_id=${fixture.order}`);
    await page.getByLabel('Returned item', { exact: true }).selectOption(String(fixture.item));
    await page.getByLabel('This unit has physically arrived').check();
    await page.locator('#ss-receive').screenshot({ path: `${output}/01-receive.png`, animations: 'disabled' });
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Start inspection', exact: true }).click()]);
    const inspectionUrl = page.url();
    await page.getByLabel('Resale listing title').fill('Arc desk lamp · forest green');
    await page.getByLabel('Condition', { exact: true }).selectOption('open-box');
    await page.getByLabel('Resale price', { exact: false }).fill('59.00');
    await page.getByLabel('What the buyer should know').fill('Open-box return. Small cosmetic scratch on the base. Lamp tested and working. Power cable included; original packaging is missing. Demo listing only.');
    await page.getByLabel('Internal inspection notes').fill('Demo inspection: checked switch, cable and base. Store on the returns shelf until the listing is reviewed.');
    await page.getByRole('button', { name: 'Choose photos', exact: true }).click();
    await page.getByRole('tab', { name: 'Media Library', exact: true }).click();
    await page.locator(`.media-modal .attachment[data-id="${fixture.photo}"]`).click();
    await page.getByRole('button', { name: 'Use these photos', exact: true }).click();
    await page.getByLabel('I reviewed these photos').check();
    await page.getByLabel('I physically inspected').check();
    await page.getByLabel('Inspection decision').selectOption('ready');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save inspection', exact: true }).click()]);
    assert(await page.locator('#ss-create-draft').isEnabled());
    await page.locator('.ss-detail').screenshot({ path: `${output}/02-inspection.png`, animations: 'disabled' });
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Create resale draft', exact: true }).click()]);
    await page.locator('.ss-sidebar').screenshot({ path: `${output}/03-draft-created.png`, animations: 'disabled' });
    await page.getByRole('link', { name: 'Review resale product', exact: true }).click();
    await page.locator('#title').waitFor();
    assert.equal(await page.locator('#post_status').inputValue(), 'draft');
    assert((await page.locator('#content').inputValue()).includes('Small cosmetic scratch'));
    assert(!(await page.locator('#content').inputValue()).includes('Store on the returns shelf'));
    await page.locator('.inventory_options a').click();
    assert.equal(await page.locator('#_stock').inputValue(), '1');
    assert(await page.locator('#_manage_stock').isChecked());
    assert(await page.locator('#_sold_individually').isChecked());
    await page.locator('#woocommerce-product-data').screenshot({ path: `${output}/04-inventory.png`, animations: 'disabled' });
    console.log(`Saved four walkthrough screenshots. Synthetic inspection: ${inspectionUrl}`);
} finally {
    await browser.close();
}
