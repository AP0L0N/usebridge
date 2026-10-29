// Kanban screenshots against the LOCAL server (sqlite in /tmp). Gate password read from .env, never printed.
// Usage: node tests/browser/shoot-kanban.mjs [name-prefix …]   (writes shots/kanban/*.png; prints the per-shot checks)
import { chromium } from '/home/box/pwcheck/node_modules/playwright-core/index.mjs';
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
const REPO = '/workspace/bridge-tickets/repo', BASE = 'http://127.0.0.1:8792', OUT = REPO + '/shots/kanban/';
const PW = readFileSync(REPO + '/.env', 'utf8').match(/^BRIDGE_GATE_PASSWORD=(.*)$/m)[1].trim().replace(/^"|"$/g, '');
const only = process.argv.slice(2);
const reseed = () => execSync('php artisan db:seed --class=KanbanSampleSeeder --force -q', { cwd: REPO, env: { ...process.env, DB_DATABASE: '/tmp/kanban.sqlite' } });
const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const results = {};
async function ctxFor(w, h) {
  const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, hasTouch: w < 500 });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => console.log('PAGEERROR', w, e.message));
  p.on('console', (m) => { if (m.type() === 'error') console.log('CONSOLE', w, m.text()); });
  await p.goto(BASE + '/gate'); await p.fill('input[type=password]', PW); await p.click('button[type=submit]'); await p.waitForURL(BASE + '/');
  return { ctx, p };
}
async function settle(p) { await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(400); }
async function check(p) {
  return p.evaluate(() => {
    const ov = document.documentElement.scrollWidth - document.documentElement.clientWidth;
    const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.opacity !== '0'; };
    const small = [...document.querySelectorAll('button,a,input:not([type=radio]):not([type=checkbox]),select,[role=tab]')]
      .filter((el) => vis(el) && el.getBoundingClientRect().height < 32 && !el.closest('.toast,.bsum,.err-line') && !el.matches('.tkl,.brand,.back,.search input'))
      .map((el) => (el.className || el.tagName) + ':' + Math.round(el.getBoundingClientRect().height));
    const clip = [...document.querySelectorAll('h1,h2,h3,p,span,b,label,button')]
      .filter((el) => vis(el) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow !== 'visible' && !el.matches('.snote,.ask,.who2 span,.selw .l,.sr-only'))
      .map((el) => el.className || el.tagName);
    // Overlays (sheets, popovers, dialogs, toasts): nothing may stick out past the viewport's right edge.
    const vw = document.documentElement.clientWidth;
    const edge = [...document.querySelectorAll('.sheet, .sheet *, .pop, .pop *, .toast, .toast *')]
      .filter((el) => vis(el) && el.getBoundingClientRect().right > vw + 0.5).map((el) => el.className || el.tagName);
    return { ov, edge: [...new Set(edge)].slice(0, 8), small: [...new Set(small)].slice(0, 8), clip: [...new Set(clip)].slice(0, 8), font: document.fonts.check('16px "Atkinson Hyperlegible"') };
  });
}
async function shot(p, name, { full = true, dialog = false } = {}) {
  await settle(p);
  const r = await check(p);
  if (dialog) {
    const vp = p.viewportSize();
    const h = await p.evaluate(() => document.querySelector('.dlgov').scrollHeight);
    await p.setViewportSize({ width: vp.width, height: Math.max(vp.height, h) }); await p.waitForTimeout(250);
    await p.screenshot({ path: OUT + name + '.png' });
    await p.setViewportSize(vp); await p.waitForTimeout(150);
  } else await p.screenshot({ path: OUT + name + '.png', fullPage: full });
  results[name] = r; console.log(name, JSON.stringify(r));
}
const want = (n) => !only.length || only.some((o) => n.startsWith(o));
for (const [w, h] of [[1440, 1000], [390, 844]]) {
  const m = w < 500;
  reseed();
  const { ctx, p } = await ctxFor(w, h);
  if (want('portfolio')) { await p.goto(BASE + '/?archived=1'); await shot(p, `portfolio-${w}`); }
  // First board open: the PM status changed since Tilen's last visit -> toast once.
  await p.goto(BASE + '/projects/concierge-avant'); await p.waitForSelector('.toast');
  if (want('states-toast')) await shot(p, `states-toast-${w}`, { full: false });
  if (want('states-history')) {
    await p.click('.pstat .hist .lnk'); await p.waitForSelector(m ? '.sheet' : '.hpop');
    await shot(p, `states-history-${w}`, { full: false }); await p.keyboard.press('Escape');
  }
  await p.goto(BASE + '/projects/concierge-avant'); await p.waitForTimeout(300);
  if (want('board')) await shot(p, `board-${w}`);
  // ---- Tommy review 29 Oct: status menu (a), filters (b), the ask (c)
  if (want('states-status')) {
    await p.click('.bbtn'); await p.waitForSelector(m ? '.sheet .sform' : '.smenu');
    await shot(p, `states-status-menu-${w}`, { full: false });
    await p.getByLabel('Smooth sailing').check(); await p.fill('#pst-note', 'Cart sign-off is in; nothing waiting on Tilen.');
    await p.route('**/projects/concierge-avant/status', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }));
    await p.click('.sform button[type=submit]'); await p.waitForSelector('.sform .err-line');
    await shot(p, `states-status-error-${w}`, { full: false });
    await p.unroute('**/projects/concierge-avant/status');
    await p.click('.sform .abtn'); await p.waitForTimeout(200);
  }
  if (want('states-filter')) {
    await p.click('.fbtn'); await p.waitForSelector(m ? '.sheet .fform' : '.fmenu');
    await shot(p, `states-filter-${w}`, { full: false });
    await p.getByLabel('New replies for you').check();
    await p.click('.ffoot .btn'); await p.waitForTimeout(300);
    await shot(p, `states-filter-result-${w}`);
    await p.click('.bsum .lnk.inl'); await p.waitForTimeout(300);
  }
  if (m && (want('states-move') || want('states-ask'))) {
    await p.click('#tab-todo'); await p.waitForTimeout(400);
    await p.click('[data-key="CA-124"] .tkmv'); await p.waitForSelector('.sheet');
    if (want('states-move')) await shot(p, `states-move-sheet-${w}`, { full: false });
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    await p.click('#tab-backlog'); await p.waitForTimeout(400);
    await p.click('[data-key="CA-131"] .tkmv'); await p.waitForSelector('.sheet');
    await p.click('.mvl [data-col="waiting_tilen"]'); await p.waitForSelector('.sheet .askf');
    if (want('states-ask')) {
      await shot(p, `states-ask-${w}`, { full: false });
      await p.click('.sheet .askf .btn'); await p.waitForSelector('#ask-err');
      await shot(p, `states-ask-error-${w}`, { full: false });
    }
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  }
  if (!m && want('states-ask')) {
    const card = await p.locator('[data-key="CA-131"]').boundingBox();
    const target = await p.locator('#col-waiting_tilen .colsub').boundingBox();
    await p.mouse.move(card.x + 60, card.y + 30); await p.mouse.down();
    await p.mouse.move(card.x + 80, card.y + 40, { steps: 3 });
    await p.mouse.move(target.x + 60, target.y + 80, { steps: 8 });
    await p.mouse.up(); await p.waitForSelector('.askbox');
    await shot(p, `states-ask-${w}`, { full: false });
    await p.click('.askbox .askf .btn'); await p.waitForSelector('#ask-err');
    await shot(p, `states-ask-error-${w}`, { full: false });
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  }
  if (!m && want('states-move-error')) {
    // A move whose request fails (500 injected): the card is back in Backlog, error toast without an HTTP code.
    await p.route('**/move', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }));
    await p.focus('[data-key="CA-133"] .tkl'); await p.keyboard.press('Space'); await p.keyboard.press('ArrowRight'); await p.keyboard.press('Space');
    await p.waitForSelector('.toast.err'); await p.evaluate(() => document.activeElement?.blur());
    await shot(p, `states-move-error-${w}`, { full: false });
    await p.unroute('**/move');
    await p.reload(); await p.waitForTimeout(300);
  }
  if (m && want('states-move-undo')) {
    await p.click('#tab-todo'); await p.waitForTimeout(300);
    await p.click('[data-key="CA-124"] .tkmv'); await p.click('.mvl [data-col="review"]');
    await p.waitForSelector('.toast .lnk'); await p.waitForTimeout(800);
    await shot(p, `states-move-undo-${w}`, { full: false });
    await p.click('.toast .lnk'); await p.waitForTimeout(1200);
  }
  if (!m && want('states-drag')) {
    const card = await p.locator('[data-key="CA-124"]').boundingBox();
    const target = await p.locator('#col-in_progress [data-key="CA-121"]').boundingBox();
    await p.mouse.move(card.x + 60, card.y + 30); await p.mouse.down();
    await p.mouse.move(card.x + 80, card.y + 40, { steps: 3 });
    await p.mouse.move(target.x + 60, target.y + 20, { steps: 8 });
    await shot(p, `states-drag-${w}`, { full: false });
    await p.mouse.up(); await p.waitForTimeout(800);
    await p.reload();
  }
  if (!m && want('states-keyboard')) {
    await p.focus('[data-key="CA-129"] .tkl'); await p.keyboard.press('Space'); await p.keyboard.press('ArrowRight'); await p.keyboard.press('ArrowUp');
    await shot(p, `states-keyboard-${w}`, { full: false }); await p.keyboard.press('Escape');
  }
  if (want('ticket')) {
    await p.goto(BASE + '/projects/concierge-avant/tickets/CA-115'); await p.waitForSelector('.dlg .thread');
    // match the mock: first option picked, note typed
    await p.click('.tin .opt >> nth=0'); await p.fill('#tin-t', 'Keep the current rule for launch. Revisit after the first 50 group stays.');
    await p.evaluate(() => { document.activeElement?.blur(); document.querySelector('.dlgov').scrollTop = 0; });
    if (m) { await settle(p); await p.screenshot({ path: OUT + `ticket-${w}-viewport.png` }); }
    await shot(p, `ticket-${w}`, { dialog: true });
    if (want('ticket') || want('states-sent')) {
      await p.click('.tin button[type=submit]'); await p.waitForSelector('.tin.sent');
      await p.waitForSelector('.cm.tl'); await p.evaluate(() => document.activeElement?.blur());
      await shot(p, `states-input-sent-${w}`, { dialog: true });
      // QA M1: the PM acks while the ticket stays in Waiting on Tilen -> "You answered at …", not a blank form.
      execSync(`php artisan tinker --execute="app(App\\Services\\Tickets\\TicketService::class)->ackInput(App\\Models\\Ticket::where('key','CA-115')->firstOrFail(), new App\\Services\\Tickets\\Actor('agent-janice','agent','api'));"`,
        { cwd: REPO, env: { ...process.env, DB_DATABASE: '/tmp/kanban.sqlite' } });
      await p.reload(); await p.waitForSelector('.dlg .thread'); await p.waitForSelector('.tin.sent');
      await p.evaluate(() => { document.activeElement?.blur(); document.querySelector('.dlgov').scrollTop = 0; });
      await shot(p, `ticket-after-ack-${w}`, { dialog: true });
    }
  }
  if (want('states-open')) {
    // open from the board by click: modal over the board, URL updates, Back closes
    await p.goto(BASE + '/projects/concierge-avant'); await p.waitForTimeout(300);
    if (m) { await p.click('#tab-in_progress'); await p.waitForTimeout(300); }
    await p.click('[data-key="CA-118"] .tkl'); await p.waitForSelector('.dlg .thread');
    const url1 = p.url(); await shot(p, `states-new-divider-${w}`, { full: false });
    await p.goBack(); await p.waitForTimeout(500);
    const closed = await p.evaluate(() => !document.querySelector('.dlg'));
    const focus = await p.evaluate(() => document.activeElement?.closest('[data-key]')?.dataset.key ?? null);
    console.log('open/back', w, url1.replace(BASE, ''), '->', p.url().replace(BASE, ''), 'closed', closed, 'focus', focus);
  }
  await ctx.close();
}
await b.close();
console.log('RESULTS', JSON.stringify(results));
