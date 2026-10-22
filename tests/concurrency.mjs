import { spawn } from 'node:child_process';
import assert from 'node:assert/strict';

const worker = operation => new Promise((resolve, reject) => {
    const process = spawn('docker', ['compose', 'run', '--rm', '-T', 'cli', 'eval-file', 'wp-content/plugins/second-shelf/tests/concurrency.php', operation]);
    let out = '', err = '';
    process.stdout.on('data', data => { out += data; });
    process.stderr.on('data', data => { err += data; });
    process.on('error', reject);
    process.on('exit', code => code === 0 ? resolve(out.trim()) : reject(new Error(out + err)));
});
await worker('setup');
const receipts = await Promise.all([worker('receive'), worker('receive')]);
assert.equal(receipts.filter(r => r.startsWith('RECEIVED:')).length, 1, JSON.stringify(receipts));
assert.equal(receipts.filter(r => r === 'CAPACITY_BLOCKED').length, 1, JSON.stringify(receipts));
await worker('prepare');
const drafts = await Promise.all([worker('create'), worker('create')]);
assert(drafts[0].startsWith('DRAFT:'));
assert.equal(drafts[0], drafts[1], 'Both concurrent requests resolve to the same product');
assert.equal(await worker('verify'), 'VERIFIED');
console.log('Concurrent receipt and draft requests passed: one unit, one product, stock of one.');
