// Browser check for Tilen's project controls (status + note, archive/unarchive) against a LOCAL server.
// Setup:  DB_DATABASE=/tmp/tilen-controls.sqlite php artisan migrate:fresh --force
//         DB_DATABASE=/tmp/tilen-controls.sqlite php -S 127.0.0.1:8794 -t public public/index.php   (public/build -> a vite build in /tmp)
// Usage:  node tests/browser/tilen-controls.mjs   (KANBAN_BASE overrides http://127.0.0.1:8794)
// Signs in with BRIDGE_TEST_EMAIL / BRIDGE_TEST_PASSWORD (a local synthetic account), never printed. Exits 1 when any check fails.
const { chromium } = await import(process.env.PLAYWRIGHT_CORE ?? 'playwright-core');
import { mkdirSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const BASE = process.env.KANBAN_BASE ?? 'http://127.0.0.1:8794';
const DB = '/tmp/tilen-controls.sqlite';
const SHOTS = process.env.SHOTS_DIR ?? './shots-out/tilen-controls';
// Login of a local synthetic account from the environment; never a literal, never printed.
const EMAIL = process.env.BRIDGE_TEST_EMAIL || '';
const PW = process.env.BRIDGE_TEST_PASSWORD || '';
if (!EMAIL || !PW) { console.error('Set BRIDGE_TEST_EMAIL and BRIDGE_TEST_PASSWORD'); process.exit(2); }
mkdirSync(SHOTS, { recursive: true });

let failed = 0, checks = 0;
const ok = (cond, msg) => { checks++; console.log(`${cond ? 'ok  ' : 'FAIL'} ${msg}`); if (!cond) failed++; };
const env = { ...process.env, DB_DATABASE: DB };
execSync('php artisan migrate:fresh --force -q', { cwd: REPO, env });
execSync('php artisan db:seed --class=KanbanSampleSeeder --force -q', { cwd: REPO, env });

const NOTE = 'Cleared my tickets, over to the PM';
const archiveText = (n) => `Archive ${n}? It moves to Archived; you can unarchive any time.`;
const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
  for (const [w, h] of [[1440, 1000], [390, 844]]) {
    const m = w < 500;
    const tag = `[${w}]`;
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, hasTouch: m });
    const p = await ctx.newPage();
    p.on('pageerror', (e) => ok(false, `${tag} page error: ${e.message}`));
    const shot = (name) => p.screenshot({ path: `${SHOTS}/${name}-${w}.png` });
    const settle = (ms = 500) => p.waitForTimeout(ms);
    const noOverflow = async (what) => ok(await p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), `${tag} no horizontal overflow ${what}`);
    const card = (name) => p.locator('.pcw', { has: p.locator('h2', { hasText: new RegExp(`^${name}$`) }) });
    const dialog = () => p.locator(m ? '.sheet' : '.pop.smenu');
    await p.goto(BASE + '/login'); await p.fill('#email', EMAIL); await p.fill('#pw', PW); await p.click('button[type=submit]'); await p.waitForURL(BASE + '/');

    // ---- 1. board: set Waiting with a note
    await p.goto(BASE + '/projects/blue-harbor'); await settle();
    await p.click('.bbtn'); await settle(300);
    ok(await dialog().locator('text=Smooth sailing').count() === 1 && await dialog().locator('text=Waiting input').count() === 1, `${tag} board status menu offers Smooth sailing and Waiting input`);
    ok(await dialog().locator('text=Archive project').count() === 1, `${tag} board status menu has "Archive project"`);
    ok(await dialog().locator('input[value=archived], label:has-text("Archived")').count() === 0, `${tag} no raw "Archived" radio in the status form`);
    await shot('board-status-menu');
    await dialog().getByLabel('Waiting input').check();
    await p.fill('#pst-note', NOTE);
    await dialog().getByRole('button', { name: 'Save status' }).click(); await settle(700);
    ok(await p.locator('.pstat .snote').innerText().catch(() => '') === NOTE, `${tag} board shows the note`);
    ok((await p.locator('.pstat .sset').innerText()).includes('Tilen'), `${tag} board shows "Set by Tilen"`);
    ok((await p.locator('.pstat .bbtn').innerText()).includes('Waiting input'), `${tag} board badge reads Waiting input`);
    await p.click('.pstat .hist .lnk'); await settle(300);
    const hist = await p.locator('.shist li').first().innerText();
    ok(hist.includes(NOTE) && hist.includes('Tilen') && hist.includes('via Bridge') && hist.includes('Waiting input'), `${tag} History logs it as Tilen via Bridge`);
    await shot('board-status-set');
    await p.keyboard.press('Escape'); if (m) await p.keyboard.press('Escape');
    await noOverflow('on the board');

    // ---- 2. portfolio: Smooth from the card menu
    await p.goto(BASE + '/'); await settle();
    const ca = card('Blue Harbor');
    ok((await ca.innerText()).includes(NOTE) && (await ca.innerText()).includes('Waiting input'), `${tag} portfolio card reflects the Tilen-set Waiting status and note`);
    await ca.getByRole('button', { name: 'Blue Harbor: project actions' }).click(); await settle(300);
    await shot('portfolio-card-menu');
    await dialog().getByLabel('Smooth sailing').check();
    await dialog().getByRole('button', { name: 'Save status' }).click(); await settle(800);
    ok((await card('Blue Harbor').innerText()).includes('Smooth sailing'), `${tag} card now Smooth sailing`);
    ok(await card('Blue Harbor').locator('.pc.s-ok').count() === 1, `${tag} card rule colour is smooth`);
    await noOverflow('on the portfolio');
    await shot('portfolio-after-smooth');

    // ---- 3. archive from the board: cancel keeps, confirm archives
    await p.goto(BASE + '/projects/northwind'); await settle();
    await p.click('.bbtn'); await settle(300);
    await dialog().getByRole('button', { name: 'Archive project' }).click(); await settle(300);
    const cfm = p.locator('.sheet.cfm');
    ok((await cfm.locator('h3').innerText()) === archiveText('Northwind'), `${tag} confirm text is exact: "${archiveText('Northwind')}"`);
    await shot('archive-confirm');
    await cfm.getByRole('button', { name: 'Cancel' }).click(); await settle(300);
    ok(await p.locator('.sheet.cfm').count() === 0 && await p.getByRole('button', { name: 'Unarchive' }).count() === 0, `${tag} Cancel closes the confirm and keeps the project`);
    ok((await p.locator('.pstat .bbtn').innerText()).includes('Waiting input'), `${tag} still Waiting input after cancel`);
    await p.click('.bbtn'); await settle(300);
    await dialog().getByRole('button', { name: 'Archive project' }).click(); await settle(300);
    await p.locator('.sheet.cfm').getByRole('button', { name: 'Archive', exact: true }).click(); await settle(800);
    ok(await p.getByRole('button', { name: 'Unarchive' }).count() === 1, `${tag} archived board shows an Unarchive control`);
    ok((await p.locator('.pstat').innerText()).includes('Archived'), `${tag} board badge reads Archived`);
    await shot('board-archived');
    await p.click('.pstat .hist .lnk'); await settle(300);
    const h2 = await p.locator('.shist li').first().innerText();
    ok(h2.includes('Archived') && h2.includes('Tilen') && h2.includes('via Bridge'), `${tag} archive is in History as Tilen via Bridge`);
    await p.keyboard.press('Escape'); if (m) await p.keyboard.press('Escape');

    // ---- 4. find it in Archived, unarchive from the card menu
    await p.goto(BASE + '/'); await settle();
    ok(await card('Northwind').count() === 0, `${tag} archived project is hidden from the active grid`);
    await p.getByRole('switch', { name: 'Show archived' }).click(); await settle(300);
    ok(await p.locator('h2.sect', { hasText: 'Archived' }).count() === 1 && await card('Northwind').count() === 1, `${tag} Show archived lists it under Archived`);
    await p.locator('h2.sect').scrollIntoViewIfNeeded(); await shot('archived-section');
    await card('Northwind').getByRole('button', { name: 'Northwind: project actions' }).click(); await settle(300);
    await shot('archived-card-menu');
    await dialog().getByRole('button', { name: 'Unarchive' }).click(); await settle(800);
    ok(!(await p.locator('h2.sect', { hasText: 'Archived' }).locator('xpath=following-sibling::div[1]').innerText().catch(() => '')).includes('Northwind'), `${tag} Northwind left the Archived group (no confirm needed)`);
    ok((await card('Northwind').innerText()).includes('Waiting input'), `${tag} unarchived back to its prior status (Waiting input)`);

    // ---- 5. archive from the card menu, unarchive from the board
    await p.goto(BASE + '/'); await settle();
    await card('Acme Studio').getByRole('button', { name: 'Acme Studio: project actions' }).click(); await settle(300);
    await dialog().getByRole('button', { name: 'Archive project' }).click(); await settle(300);
    ok((await p.locator('.sheet.cfm h3').innerText()) === archiveText('Acme Studio'), `${tag} card-menu confirm text is exact`);
    await p.locator('.sheet.cfm').getByRole('button', { name: 'Archive', exact: true }).click(); await settle(800);
    ok(await card('Acme Studio').count() === 0, `${tag} archived from the card menu`);
    await p.goto(BASE + '/projects/acme-studio'); await settle();
    await p.getByRole('button', { name: 'Unarchive' }).click(); await settle(800);
    ok(await p.getByRole('button', { name: 'Unarchive' }).count() === 0 && (await p.locator('.pstat .bbtn').innerText()).includes('Smooth sailing'), `${tag} board Unarchive restores Smooth sailing`);
    await ctx.close();
  }
} finally {
  await browser.close();
}
console.log(failed ? `\n${failed} of ${checks} check(s) failed` : `\nall ${checks} checks passed`);
process.exit(failed ? 1 : 0);
