import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { writeFileSync, mkdirSync } from 'node:fs';

const run = (args, allowFailure = false) => {
    const result = spawnSync('docker', ['compose', ...args], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
    if (!allowFailure && result.status !== 0) {
        process.stderr.write(result.stdout || '');
        process.stderr.write(result.stderr || '');
        throw new Error(`Docker command failed: ${args.join(' ')}`);
    }
    return result;
};
const wp = (...args) => run(['run', '--rm', '-T', 'cli', ...args]);
run(['up', '-d', '--wait', 'wordpress']);
let ready = false;
for (let attempt = 0; attempt < 30; attempt++) {
    const result = run(['run', '--rm', '-T', 'cli', 'core', 'version'], true);
    if (result.status === 0) { ready = true; break; }
    await new Promise(resolve => setTimeout(resolve, 1000));
}
if (!ready) throw new Error('WordPress files did not become ready.');
const installed = run(['run', '--rm', '-T', 'cli', 'core', 'is-installed'], true);
if (installed.status !== 0) {
    const password = randomBytes(18).toString('base64url');
    wp('core', 'install', '--url=http://localhost:8097', '--title=RegradeDesk Demo', '--admin_user=demo', `--admin_password=${password}`, '--admin_email=demo@example.test', '--skip-email');
    mkdirSync('artifacts', { recursive: true });
    writeFileSync('artifacts/demo-login.txt', `Local development only\nhttp://localhost:8097/wp-admin/\nUser: demo\nPassword: ${password}\n`);
    console.log('Local login saved in artifacts/demo-login.txt (ignored by Git).');
}
wp('core', 'update-db');
const woo = run(['run', '--rm', '-T', 'cli', 'plugin', 'is-installed', 'woocommerce'], true);
if (woo.status !== 0) {
    process.stdout.write(wp('plugin', 'install', 'woocommerce', `--version=${process.env.SECOND_SHELF_WC_VERSION || '11.2.1'}`, '--activate').stdout);
} else {
    wp('plugin', 'activate', 'woocommerce');
}
wp('plugin', 'activate', 'regradedesk');
wp('option', 'update', 'woocommerce_onboarding_profile', '{"completed":true}', '--format=json');
wp('option', 'update', 'woocommerce_store_address', 'Demo only');
wp('option', 'update', 'woocommerce_default_country', 'US:CA');
wp('option', 'update', 'woocommerce_currency', 'USD');
wp('rewrite', 'structure', '/%postname%/');
console.log('WordPress + WooCommerce ready at http://localhost:8097');
if (process.argv.includes('--test')) {
    for (const hpos of ['no', 'yes']) {
        wp('wc', 'hpos', 'sync');
        wp('option', 'update', 'woocommerce_custom_orders_table_enabled', hpos);
        console.log(`Integration tests: HPOS ${hpos}`);
        process.stdout.write(wp('eval-file', 'wp-content/plugins/regradedesk/tests/integration.php').stdout);
    }
}
if (process.argv.includes('--seed')) {
    process.stdout.write(wp('eval-file', 'wp-content/plugins/regradedesk/tests/seed.php').stdout);
}
