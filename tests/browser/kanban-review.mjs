// Browser check for Tommy's review of 1ae66bd (status menu, filters, ask step) against the LOCAL server.
// Usage: node tests/browser/kanban-review.mjs   (server: php -S 127.0.0.1:8792 from public/, DB_DATABASE=/tmp/kanban.sqlite)
// The gate password is read from .env and never printed. Exits 1 when any check fails.
import { chromium } from '/home/box/pwcheck/node_modules/playwright-core/index.mjs';
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const BASE = process.env.KANBAN_BASE ?? 'http://127.0.0.1:8792';
const PW = readFileSync(REPO + '/.env', 'utf8').match(/^BRIDGE_GATE_PASSWORD=(.*)$/m)?.[1].trim().replace(/^"|"$/g, '');
if (!PW) { console.error('BRIDGE_GATE_PASSWORD missing in .env'); process.exit(2); }
const BOARD = BASE + '/projects/concierge-avant';
const HTTP_CODE = /HTTP|\b[45]\d\d\b/;

let failed = 0;
const ok = (cond, msg) => { console.log(`${cond ? 'ok  ' : 'FAIL'} ${msg}`); if (!cond) failed++; };
execSync('php artisan db:seed --class=KanbanSampleSeeder --force -q', { cwd: REPO, env: { ...process.env, DB_DATABASE: '/tmp/kanban.sqlite' } });

const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
  for (const [w, h] of [[1440, 1000], [390, 844]]) {
    const m = w < 500;
    const tag = `[${w}]`;
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, hasTouch: m });
    const p = await ctx.newPage();
    p.on('pageerror', (e) => ok(false, `${tag} page error: ${e.message}`));
    await p.goto(BASE + '/gate'); await p.fill('input[type=password]', PW); await p.click('button[type=submit]'); await p.waitForURL(BASE + '/');
    await p.goto(BOARD); await p.waitForTimeout(300); // first visit: status toast + mark seen
    await p.goto(BOARD); await p.waitForTimeout(400);

    const active = () => p.evaluate(() => {
      const a = document.activeElement;
      return { cls: a?.className ?? '', id: a?.id ?? '', key: a?.closest('[data-key]')?.dataset.key ?? null, checked: a instanceof HTMLInputElement && a.type === 'radio' ? a.checked : null };
    });
    const shownKeys = () => p.evaluate(() => [...document.querySelectorAll('.board .tk[data-key]')].map((el) => el.dataset.key).sort());
    const outside = async () => {
      if (m) await p.mouse.click(w / 2, 20); // the sheet's scrim
      else { const r = await p.locator('h1').boundingBox(); await p.mouse.click(r.x + 10, r.y + r.height / 2); }
      await p.waitForTimeout(150);
    };

    // ---------------- (b) filters
    const openFilter = async () => { await p.click('.fbtn'); await p.waitForSelector(m ? '.sheet' : '.fmenu'); await p.waitForTimeout(100); };
    await openFilter();
    if (m) {
      const sheet = await p.evaluate(() => {
        const s = document.querySelector('.sheet');
        const vw = document.documentElement.clientWidth;
        const over = [...s.querySelectorAll('*')].filter((el) => el.getBoundingClientRect().right > vw + 0.5).map((el) => el.className || el.tagName);
        return { label: s.getAttribute('aria-label'), h3: s.querySelector('h3')?.textContent, popover: !!document.querySelector('.fmenu'), over, sheetRight: s.getBoundingClientRect().right, vw,
          selectsFull: [...s.querySelectorAll('select')].every((x) => x.getBoundingClientRect().width >= s.getBoundingClientRect().width - 48) };
      });
      ok(sheet.label === 'Filter tickets' && sheet.h3 === 'Filter tickets', `${tag} filters render in a .sheet titled "Filter tickets"`);
      ok(!sheet.popover, `${tag} no popover at 390`);
      ok(sheet.over.length === 0 && sheet.sheetRight <= sheet.vw, `${tag} nothing in the filter sheet passes the right edge ${JSON.stringify(sheet.over.slice(0, 5))}`);
      ok(sheet.selectsFull, `${tag} filter selects are full width`);
    } else {
      ok(await p.evaluate(() => !!document.querySelector('.pop.fmenu[role=dialog]') && !document.querySelector('.sheet')), `${tag} filters render as a popover`);
    }
    ok((await active()).id === 'f-as', `${tag} filter open focuses Assignee`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    ok((await active()).cls.includes('fbtn'), `${tag} Esc returns focus to .fbtn`);
    await openFilter();
    await p.click('.ffoot .btn'); await p.waitForTimeout(150);
    ok((await active()).cls.includes('fbtn'), `${tag} Done returns focus to .fbtn`);
    await openFilter();
    await outside();
    ok((await active()).cls.includes('fbtn') && !(await p.$('.fmenu, .sheet')), `${tag} outside click closes and returns focus to .fbtn`);

    await openFilter();
    ok(!(await p.getByText('Has unread').count()), `${tag} "Has unread" is gone`);
    await p.getByLabel('New replies for you').check(); await p.waitForTimeout(150);
    if (m) await p.click('.ffoot .btn');
    ok(JSON.stringify(await shownKeys()) === '["CA-118"]', `${tag} "New replies for you" leaves CA-118 (got ${JSON.stringify(await shownKeys())})`);
    if (m) {
      const tab = await p.evaluate(() => ({ sel: document.querySelector('[role=tab][aria-selected=true]')?.id, badge: document.querySelector('.fbtn .cnt')?.textContent,
        badgeShown: getComputedStyle(document.querySelector('.fbtn .cnt')).display !== 'none', zero: getComputedStyle(document.querySelector('#tab-backlog .cnt')).color }));
      ok(tab.sel === 'tab-in_progress', `${tag} filter change jumps to the first tab with matches (${tab.sel})`);
      ok(tab.badge === '1' && tab.badgeShown, `${tag} count badge on the icon-only filter button`);
      ok(tab.zero === 'rgb(107, 111, 120)', `${tag} 0 counts in tabs use --ink3`);
      await openFilter();
    }
    await p.getByLabel('New replies for you').uncheck();
    await p.getByLabel('Your input not picked up').check(); await p.waitForTimeout(150);
    if (m) await p.click('.ffoot .btn');
    ok(JSON.stringify(await shownKeys()) === '["CA-119"]', `${tag} "Your input not picked up" leaves CA-119 (got ${JSON.stringify(await shownKeys())})`);
    if (m) await openFilter();
    await p.click('.ffoot .abtn'); await p.waitForTimeout(100);
    await p.click('.ffoot .btn'); await p.waitForTimeout(150);
    ok((await shownKeys()).length > 10, `${tag} Clear filters shows every card again`);

    // ---------------- (a) status menu
    const openStatus = async () => { await p.click('.bbtn'); await p.waitForSelector(m ? '.sheet' : '.smenu'); await p.waitForTimeout(150); };
    await openStatus();
    const a1 = await active();
    ok(a1.checked === true, `${tag} status menu focuses the checked radio`);
    if (!m) ok(await p.evaluate(() => { const s = document.querySelector('.smenu'); return s.getAttribute('role') === 'dialog' && s.getAttribute('aria-label') === 'Set project status'; }), `${tag} .smenu is a labelled dialog`);
    if (!m) ok(await p.evaluate(() => document.querySelector('.smenu').getBoundingClientRect().top >= document.querySelector('.pstat').getBoundingClientRect().bottom), `${tag} status menu sits below the .pstat card`);
    ok(await p.evaluate(() => document.querySelector('#pst-note').value === '' && document.querySelector('#pst-note').placeholder === 'Why? (optional)'), `${tag} note starts empty with the placeholder`);
    ok(await p.locator('.sform .hint.cur').innerText().then((t) => /^Janice’s note: “Two decisions/.test(t)), `${tag} current note shown as "Janice's note: …"`);
    ok(await p.locator('.sform button[type=submit]').isDisabled(), `${tag} Save is disabled until something changes`);
    ok(await p.locator('.sform').innerText().then((t) => t.includes('Saved as set by you. Janice sees it in History.') && t.includes('0/280')), `${tag} hint copy and n/280 counter`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    ok((await active()).cls.includes('bbtn'), `${tag} Esc returns focus to .bbtn`);
    await openStatus();
    await outside();
    ok((await active()).cls.includes('bbtn') && !(await p.$('.sform')), `${tag} outside click closes and returns focus to .bbtn`);

    await openStatus();
    await p.getByLabel('Archived').check();
    ok(await p.getByText('Hides the project from the portfolio. You can undo it in History.').isVisible(), `${tag} Archived hint`);
    await p.getByLabel('Smooth sailing').check();
    await p.fill('#pst-note', 'Cart sign-off done.');
    await p.route('**/projects/concierge-avant/status', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }));
    await p.click('.sform button[type=submit]');
    await p.waitForSelector('.sform .err-line');
    const err = await p.locator('.sform').innerText();
    ok(/✕ Couldn.t save the status\. Your note is kept\./.test(err) && !HTTP_CODE.test(err), `${tag} status error copy, no HTTP code`);
    ok(await p.locator('#pst-note').inputValue() === 'Cart sign-off done.', `${tag} form stays open with the note kept`);
    await p.unroute('**/projects/concierge-avant/status');
    await p.click('.sform .abtn'); await p.waitForTimeout(150);
    ok((await active()).cls.includes('bbtn'), `${tag} Cancel returns focus to .bbtn`);

    // ---------------- (c) the ask
    if (!m) {
      const card = await p.locator('[data-key="CA-131"]').boundingBox();
      const target = await p.locator('#col-waiting_tilen .colsub').boundingBox();
      await p.mouse.move(card.x + 60, card.y + 30); await p.mouse.down();
      await p.mouse.move(card.x + 80, card.y + 40, { steps: 3 });
      await p.mouse.move(target.x + 60, target.y + 80, { steps: 8 });
      await p.mouse.up();
      await p.waitForSelector('.askbox');
    } else {
      await p.click('#tab-backlog'); await p.waitForTimeout(300);
      await p.click('[data-key="CA-131"] .tkmv'); await p.waitForSelector('.sheet .mvl');
      await p.click('.mvl [data-col="waiting_tilen"]');
      await p.waitForSelector('.sheet .askf');
      ok(!(await p.$('.askbox')), `${tag} ask is a step inside the Move sheet, not a dialog`);
      await p.click('.askh .ibtn'); await p.waitForSelector('.sheet .mvl');
      ok(true, `${tag} back arrow returns to the column list`);
      await p.click('.mvl [data-col="waiting_tilen"]'); await p.waitForSelector('.sheet .askf'); await p.waitForTimeout(100);
      ok(await p.evaluate(() => { const [b, c] = document.querySelectorAll('.askf .qact > *'); return b.matches('.btn') && c.matches('.abtn') && b.getBoundingClientRect().bottom <= c.getBoundingClientRect().top; }),
        `${tag} full-width Move (.btn) then Cancel (.abtn)`);
    }
    const box = m ? '.sheet' : '.askbox';
    const txt = await p.locator(box).innerText();
    ok(txt.includes('Move CA-131 to Waiting on Tilen') && txt.includes('Late check-out request in the Home module') && txt.includes('What’s the ask?') && txt.includes('Shown on the card and in Your input.')
      && txt.includes('Enter moves it · Shift+Enter for a new line · 0/280'), `${tag} ask copy (heading, title, label, hints)`);
    ok((await active()).id === 'ask-in', `${tag} ask textarea has focus`);
    await p.click(`${box} .askf .btn`); await p.waitForTimeout(150);
    const askErr = await p.locator(box).innerText();
    ok(askErr.includes('✕ Add the ask. Every ticket in Waiting on Tilen needs one.') && !HTTP_CODE.test(askErr), `${tag} empty ask error copy`);
    ok(await p.evaluate(() => { const t = document.querySelector('#ask-in'); const s = getComputedStyle(t);
      return document.activeElement === t && t.getAttribute('aria-invalid') === 'true' && s.borderTopColor === 'rgb(180, 35, 24)' && s.outlineStyle !== 'none'; }),
    `${tag} invalid textarea: red border, focused with a ring`);
    ok(await p.evaluate(() => document.querySelector('[data-key="CA-131"]')?.closest('[data-col]')?.dataset.col === 'backlog'), `${tag} card stays put until confirmed`);
    // A failed move shows the error toast without an HTTP code.
    await p.fill('#ask-in', 'Should late check-out be paid?');
    await p.route('**/tickets/CA-131/move', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }));
    await p.keyboard.press('Enter');
    await p.waitForSelector('.toast.err');
    const toast = await p.locator('.toast.err').innerText();
    ok(/Couldn.t move CA-131\. Try again\./.test(toast) && !HTTP_CODE.test(toast), `${tag} move error toast, no HTTP code`);
    await p.unroute('**/tickets/CA-131/move');
    await p.click('.toast.err .tx'); await p.waitForTimeout(800);
    ok(await p.evaluate(() => document.querySelector('[data-key="CA-131"]')?.closest('[data-col]')?.dataset.col === 'backlog'), `${tag} failed move rolls back`);
    // Esc cancels; focus goes back to the dragged card (desktop) / its Move button (mobile).
    if (!m) {
      const card = await p.locator('[data-key="CA-131"]').boundingBox();
      const target = await p.locator('#col-waiting_tilen .colsub').boundingBox();
      await p.evaluate(() => document.activeElement?.blur());
      await p.mouse.move(card.x + 60, card.y + 30); await p.mouse.down();
      await p.mouse.move(card.x + 80, card.y + 40, { steps: 3 });
      await p.mouse.move(target.x + 60, target.y + 80, { steps: 8 });
      await p.mouse.up(); await p.waitForSelector('.askbox');
      ok(await p.evaluate(() => document.querySelector('.askbox').getAttribute('aria-modal') === 'true'), `${tag} ask dialog is aria-modal`);
      await p.keyboard.press('Tab'); await p.keyboard.press('Tab'); await p.keyboard.press('Tab'); await p.keyboard.press('Tab');
      ok(await p.evaluate(() => document.querySelector('.askbox').contains(document.activeElement)), `${tag} focus stays trapped in the ask dialog`);
      await p.keyboard.press('Escape'); await p.waitForTimeout(200);
      const a = await active();
      ok(!(await p.$('.askbox')) && a.key === 'CA-131' && a.cls.includes('tkl'), `${tag} Esc after a drag returns focus to the dragged card (${JSON.stringify(a)})`);
    } else {
      await p.click('[data-key="CA-131"] .tkmv'); await p.waitForSelector('.sheet .mvl');
      await p.click('.mvl [data-col="waiting_tilen"]'); await p.waitForSelector('.sheet .askf');
      await p.keyboard.press('Escape'); await p.waitForTimeout(200);
      const a = await active();
      ok(!(await p.$('.sheet')) && a.key === 'CA-131' && a.cls.includes('tkmv'), `${tag} Esc closes the sheet, focus back on the card's Move button`);
    }
    ok(await p.evaluate(() => document.querySelector('[data-key="CA-131"]')?.closest('[data-col]')?.dataset.col === 'backlog'), `${tag} cancelled ask leaves the card in Backlog`);
    await ctx.close();
  }
  // One mobile breakpoint (Tommy 29 Oct): at 700px (between the old 640 and 720) the filters open as the "Filter tickets" sheet.
  {
    const ctx = await browser.newContext({ viewport: { width: 700, height: 900 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    p.on('pageerror', (e) => ok(false, `[700] page error: ${e.message}`));
    await p.goto(BASE + '/gate'); await p.fill('input[type=password]', PW); await p.click('button[type=submit]'); await p.waitForURL(BASE + '/');
    await p.goto(BOARD); await p.waitForTimeout(400);
    for (const x of await p.$$('.toast .tx')) await x.click().catch(() => {});
    await p.click('.fbtn'); await p.waitForTimeout(250);
    const r = await p.evaluate(() => {
      const s = document.querySelector('.sheet'), vw = document.documentElement.clientWidth;
      return { sheet: s?.getAttribute('aria-label') ?? null, popover: !!document.querySelector('.fmenu'), right: s ? s.getBoundingClientRect().right : 0, vw };
    });
    ok(r.sheet === 'Filter tickets' && !r.popover && r.right <= r.vw, `[700] filters open as the "Filter tickets" sheet, not a popover (${JSON.stringify(r)})`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    await p.click('.bbtn'); await p.waitForTimeout(250);
    ok(await p.evaluate(() => !!document.querySelector('.sheet .sform, .sheet [aria-label="Set project status"]') || document.querySelector('.sheet')?.textContent.includes('Save status')), `[700] status menu opens as a sheet`);
    await ctx.close();
  }
} finally {
  await browser.close();
}
console.log(failed ? `\n${failed} check(s) failed` : '\nall checks passed');
process.exit(failed ? 1 : 0);
