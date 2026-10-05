const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { createHash, createHmac, randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const image = process.argv[2] || 'life-dashboard:test';
const root = path.resolve(__dirname, '..');
const version = fs.readFileSync(path.join(root, 'version.js'), 'utf8').match(/version:\s*'([^']+)'/)[1];
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'life-dashboard-container-'));
const name = `life-dashboard-test-${randomBytes(6).toString('hex')}`;
const volume = `${name}-data`;
const secret = randomBytes(32).toString('hex');
let base;

function docker(...args) {
  return execFileSync('docker', args, { encoding: 'utf8', timeout: 120000 }).trim();
}

async function request(url, options = {}) {
  return fetch(`${base}${url}`, { ...options, signal: AbortSignal.timeout(15000) });
}

async function start(containerMode = '1', configured = true) {
  const args = ['run', '--detach', '--name', name, '--publish', '127.0.0.1::80',
    '--env', `LIFE_HUB_CONTAINER=${containerMode}`, '--mount', `type=volume,source=${volume},target=/var/lib/life-dashboard`];
  if (configured) args.push('--mount', `type=bind,source=${path.join(temporary, '.env')},target=/run/secrets/life-dashboard.env,readonly`);
  docker(...args, image);
  const info = JSON.parse(docker('inspect', name))[0];
  base = `http://127.0.0.1:${info.NetworkSettings.Ports['80/tcp'][0].HostPort}`;
  for (let attempt = 0; attempt < 60; attempt++) {
    try {
      if ((await request('/version.js')).ok) return;
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 1000));
  }
  throw new Error('Container HTTP service did not become ready');
}

async function update(command) {
  return request('/update.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Life-Hub-Update-Command': command },
    body: '{}',
  });
}

async function main() {
  fs.writeFileSync(path.join(temporary, '.env'), [
    'LIFE_HUB_OIDC_CLIENT_ID="container-smoke-test"',
    'LIFE_HUB_OIDC_AUTHORIZE_URL="https://example.invalid/authorize"',
    'LIFE_HUB_OIDC_TOKEN_URL="https://example.invalid/token"',
    'LIFE_HUB_OIDC_USERINFO_URL="https://127.0.0.1:1/userinfo"',
    'LIFE_HUB_ADMIN_GROUPS="Life Dashboard Admins"',
    'LIFE_HUB_HA_URL="https://example.invalid/home"',
    'LIFE_HUB_HA_TOKEN="container-private-token"',
    `LIFE_HUB_DSH_PUSH_SECRET="${secret}"`,
    '',
  ].join('\n'), { mode: 0o644 });
  fs.chmodSync(temporary, 0o755);
  docker('volume', 'create', volume);
  await start();

  for (const url of ['/', '/app.js', '/styles.css', '/upgrade.html', '/assets/life.svg', '/LICENSE', '/LICENSING.md']) {
    assert.equal((await request(url)).status, 200, url);
  }
  assert.ok((await (await request('/version.js')).text()).includes(`'${version}'`));
  const healthcheck = JSON.parse(docker('inspect', name))[0].Config.Healthcheck.Test;
  assert.equal(healthcheck[0], 'CMD-SHELL');
  docker('exec', name, 'sh', '-c', healthcheck[1]);
  const publicResponse = await request('/config.php?action=public');
  assert.equal(publicResponse.status, 200);
  const publicText = await publicResponse.text();
  assert.equal(JSON.parse(publicText).oidc.clientId, 'container-smoke-test');
  assert.ok(!publicText.includes(secret) && !publicText.includes('container-private-token'));
  assert.equal((await request('/config.php?action=private')).status, 401);
  // A forwarded bearer token reaches the deliberately unreachable UserInfo endpoint.
  assert.equal((await request('/config.php?action=private', { headers: { Authorization: 'Bearer smoke-test' } })).status, 502);
  assert.equal((await request('/config.php?action=public', { headers: { Origin: 'https://other.invalid' } })).status, 403);

  // Plant fixtures to prove Apache denies access even if private files exist in the document root.
  docker('exec', name, 'sh', '-c', 'printf secret > /var/www/html/.env; printf secret > /var/www/html/config.js; mkdir -p /var/www/html/.git; printf secret > /var/www/html/.git/config');
  for (const url of ['/.env', '/.git/config', '/config.js', '/config.example.js', '/scripts/check_version.php', '/docker/php.ini', '/assets/']) {
    const response = await request(url);
    assert.ok([403, 404].includes(response.status), `${url}: ${response.status}`);
    assert.ok(!(await response.text()).includes('secret'));
  }
  assert.equal(docker('exec', '--user', 'www-data', name, 'sh', '-c', 'test ! -w /var/www/html && test ! -w /var/www/html/version.js && test -w /var/lib/life-dashboard && echo OK'), 'OK');
  const blockedUpdate = await update('update');
  assert.equal(blockedUpdate.status, 409);
  assert.ok((await blockedUpdate.json()).error.includes('docker compose pull'));
  const stream = await update('update-stream');
  assert.ok(stream.headers.get('content-type').includes('text/event-stream'));
  assert.ok((await stream.text()).includes('docker compose pull'));
  assert.equal((await request('/update.php')).status, 405);

  // Seed the existing cache format so the check path is tested without contacting GitHub.
  const cacheHash = createHash('sha256').update('/var/www/html:main').digest('hex');
  const cache = { remoteVersion: version, branch: 'main', checkedAt: new Date().toISOString() };
  docker('exec', '--user', 'www-data', name, 'php', '-r', 'file_put_contents($argv[1], $argv[2]);',
    `/var/lib/life-dashboard/life-hub-version-${cacheHash}.json`, JSON.stringify(cache));
  const check = await update('check');
  assert.equal(check.status, 200);
  assert.equal((await check.json()).remoteVersion, version);

  const timestamp = String(Math.floor(Date.now() / 1000));
  const body = JSON.stringify({ ok: true, generatedAt: Date.now(), summary: {}, workspaces: [] });
  const signature = createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
  const pushed = await request('/config.php?action=workspace-push', { method: 'POST', body, headers: {
    'Content-Type': 'application/json', 'X-DSH-Push-Timestamp': timestamp, 'X-DSH-Push-Signature': signature,
  } });
  assert.equal(pushed.status, 202, await pushed.text());
  const snapshotPath = `/var/lib/life-dashboard/life-dashboard-workspaces-${createHash('sha256').update('/var/www/html').digest('hex').slice(0, 16)}.json`;
  const snapshot = docker('exec', '--user', 'www-data', name, 'cat', snapshotPath);
  assert.equal(JSON.parse(snapshot).pushTimestamp, Number(timestamp));
  docker('rm', '--force', name);
  await start();
  assert.equal(docker('exec', '--user', 'www-data', name, 'cat', snapshotPath), snapshot);
  docker('rm', '--force', name);
  await start('0');
  assert.equal((await update('update')).status, 401, 'non-container updater still requires administrator login');
  docker('rm', '--force', name);
  await start('1', false);
  assert.equal((await request('/config.php?action=public')).status, 503, 'missing config fails closed');
  console.log(`PASS container ${image}: HTTP, PHP, private files, authorization header, update modes, DSH persistence and missing config`);
}

main().catch(error => {
  console.error(error);
  try { console.error(docker('logs', name)); } catch {}
  process.exitCode = 1;
}).finally(() => {
  try { docker('rm', '--force', name); } catch {}
  try { docker('volume', 'rm', volume); } catch {}
  fs.rmSync(temporary, { recursive: true, force: true });
});