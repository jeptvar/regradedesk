import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, mkdtempSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { randomBytes } from 'node:crypto';

// Extract the actual distributable into a fresh folder; never install over the development bind mount.
const minimum = process.argv.includes('--minimum');
const stack = minimum ? 'minimum' : 'current';
const version = JSON.parse(readFileSync('package.json', 'utf8')).version;
mkdirSync('artifacts', { recursive: true });
const stage = mkdtempSync(resolve('artifacts/package-'));
const extracted = spawnSync(process.env.PHP_BINARY || 'php', ['-r', '$z = new ZipArchive(); if ($z->open($argv[1]) !== true || !$z->extractTo($argv[2])) { exit(1); } $z->close();', resolve(`dist/regradedesk-${version}.zip`), stage], { encoding: 'utf8' });
if (extracted.status !== 0) throw new Error(extracted.error?.message || extracted.stderr || extracted.stdout || 'Cannot extract release ZIP.');
const env = { ...process.env, SECOND_SHELF_PACKAGE_DIR: join(stage, 'regradedesk') };
if (minimum) {
    env.SECOND_SHELF_VERIFY_WP = 'wordpress:6.7-php8.1-apache';
    env.SECOND_SHELF_VERIFY_CLI = 'wordpress:cli-php8.1';
}
const project = `second-shelf-package-${stack}-${randomBytes(4).toString('hex')}`;
const log = [];
const compose = (args, allowFailure = false) => {
    const result = spawnSync('docker', ['compose', '-f', 'tools/compose.verify.yaml', '-p', project, ...args], { env, encoding: 'utf8', maxBuffer: 30 * 1024 * 1024 });
    log.push(result.stdout || '', result.stderr || '');
    if (!allowFailure && result.status !== 0) throw new Error(result.stdout + result.stderr);
    return result;
};
const wp = (...args) => compose(['run', '--rm', '-T', 'cli', ...args]).stdout.trim();
try {
    console.log(`Starting isolated ${stack} package verification (${project}).`);
    compose(['up', '-d', '--wait', 'wordpress']);
    // Docker's 6.7 tag floats to a patch release; exercise the exact declared core minimum.
    if (minimum) wp('core', 'download', '--version=6.7', '--force');
    wp('core', 'install', '--url=http://wordpress', '--title=Package verification', '--admin_user=package-test', `--admin_password=${randomBytes(24).toString('base64url')}`, '--admin_email=package@example.test', '--skip-email');
    wp('plugin', 'install', 'woocommerce', `--version=${minimum ? '9.5.0' : '11.2.1'}`, '--activate');
    wp('plugin', 'activate', 'regradedesk');
    console.log(wp('plugin', 'get', 'woocommerce', '--field=version'));
    console.log(wp('core', 'version'));
    console.log(wp('eval', 'echo PHP_VERSION;'));
    for (const hpos of ['no', 'yes']) {
        wp('wc', minimum ? 'cot' : 'hpos', 'sync');
        wp('option', 'update', 'woocommerce_custom_orders_table_enabled', hpos);
        const result = wp('eval-file', '/second-shelf-tests/integration.php');
        console.log(`HPOS ${hpos}: ${result}`);
    }
    if (!minimum) {
        wp('plugin', 'install', 'plugin-check', '--version=2.1.0', '--activate');
        const report = wp('plugin', 'check', 'regradedesk', '--require=/var/www/html/wp-content/plugins/plugin-check/cli.php', '--format=json');
        writeFileSync('artifacts/plugin-check.txt', report + '\n');
        const findings = report.split(/\r?\n/).filter(line => line.startsWith('[')).flatMap(line => JSON.parse(line));
        if (findings.length || /\b(?:ERROR|WARNING)\b/.test(report)) throw new Error(`Plugin Check has findings; see artifacts/plugin-check.txt.\n${report}`);
        if (!/Success:/.test(report)) throw new Error('Plugin Check did not confirm completion.');
        console.log(report);
    }
    console.log(`Package verification passed: ${stack}.`);
} finally {
    // Only this invocation's uniquely named, disposable project and volumes are removed.
    compose(['down', '--volumes'], true);
    writeFileSync(`artifacts/package-${stack}.log`, log.join('\n'));
}
