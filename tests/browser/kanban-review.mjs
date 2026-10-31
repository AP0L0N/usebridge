// Browser check for Tommy's review of 1ae66bd (status menu, filters, ask step) against the LOCAL server.
// Usage: node tests/browser/kanban-review.mjs   (server: php -S 127.0.0.1:8792 from public/, DB_DATABASE=$KANBAN_DB, default /tmp/kanban.sqlite)
// The gate password is read from .env and never printed. Exits 1 when any check fails.
const { chromium } = await import(process.env.PLAYWRIGHT_CORE ?? 'playwright-core');
import { existsSync, readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const BASE = process.env.KANBAN_BASE ?? 'http://127.0.0.1:8792';
// Gate password from the environment (or the repo .env); never a literal. Empty = the gate rejects everyone.
const PW = process.env.BRIDGE_GATE_PASSWORD || (existsSync(REPO + '/.env') ? readFileSync(REPO + '/.env', 'utf8').match(/^BRIDGE_GATE_PASSWORD=(.*)$/m)?.[1].trim().replace(/^"|"$/g, '') : '');
if (!PW) { console.error('Set BRIDGE_GATE_PASSWORD (env or .env)'); process.exit(2); }
const BOARD = BASE + '/projects/blue-harbor';
const HTTP_CODE = /HTTP|\b[45]\d\d\b/;

let failed = 0;
const ok = (cond, msg) => { console.log(`${cond ? 'ok  ' : 'FAIL'} ${msg}`); if (!cond) failed++; };
const KANBAN_DB = process.env.KANBAN_DB ?? '/tmp/kanban.sqlite';
execSync('php artisan db:seed --class=KanbanSampleSeeder --force -q', { cwd: REPO, env: { ...process.env, DB_DATABASE: KANBAN_DB } });

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
    ok(JSON.stringify(await shownKeys()) === '["BH-118"]', `${tag} "New replies for you" leaves BH-118 (got ${JSON.stringify(await shownKeys())})`);
    if (m) {
      const tab = await p.evaluate(() => ({ sel: document.querySelector('[role=tab][aria-selected=true]')?.id, badge: document.querySelector('.fbtn .cnt')?.textContent,
        badgeShown: getComputedStyle(document.querySelector('.fbtn .cnt')).display !== 'none', zero: getComputedStyle(document.querySelector('#tab-backlog .cnt')).color }));
      ok(tab.sel === 'tab-in_progress', `${tag} filter change jumps to the first tab with matches (${tab.sel})`);
      ok(tab.badge === '1' && tab.badgeShown, `${tag} count badge on the icon-only filter button`);
      ok(tab.zero === 'rgb(138, 143, 156)', `${tag} 0 counts in tabs use --ink-400`);
      const pills = await p.evaluate(() => {
        const z = document.querySelector('.ctabs .cnt.is0'), n = document.querySelector('.ctabs .cnt:not(.is0)');
        return { z: z?.getBoundingClientRect().top, n: n?.getBoundingClientRect().top, mz: z && getComputedStyle(z).marginBottom };
      });
      ok(pills.z != null && pills.n != null && Math.abs(pills.z - pills.n) < 1 && pills.mz === '0px', `${tag} zero count pill sits inline with the other counts (${JSON.stringify(pills)})`);
      await openFilter();
    }
    await p.getByLabel('New replies for you').uncheck();
    await p.getByLabel('Your input not picked up').check(); await p.waitForTimeout(150);
    if (m) await p.click('.ffoot .btn');
    ok(JSON.stringify(await shownKeys()) === '["BH-119"]', `${tag} "Your input not picked up" leaves BH-119 (got ${JSON.stringify(await shownKeys())})`);
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
    // Archiving moved out of the radios into "Archive project" (with a confirm; see tests/browser/tilen-controls.mjs).
    ok(await p.locator('.sform').getByLabel('Archived').count() === 0 && await p.locator('.sform').getByRole('button', { name: 'Archive project' }).count() === 1, `${tag} no Archived radio; "Archive project" link instead`);
    await p.getByLabel('Smooth sailing').check();
    await p.fill('#pst-note', 'Cart sign-off done.');
    await p.route('**/projects/blue-harbor/status', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }));
    await p.click('.sform button[type=submit]');
    await p.waitForSelector('.sform .err-line');
    const err = await p.locator('.sform').innerText();
    ok(/✕ Couldn.t save the status\. Your note is kept\./.test(err) && !HTTP_CODE.test(err), `${tag} status error copy, no HTTP code`);
    ok(await p.locator('#pst-note').inputValue() === 'Cart sign-off done.', `${tag} form stays open with the note kept`);
    await p.unroute('**/projects/blue-harbor/status');
    await p.click('.sform .abtn'); await p.waitForTimeout(150);
    ok((await active()).cls.includes('bbtn'), `${tag} Cancel returns focus to .bbtn`);

    // ---------------- (c) the ask
    if (!m) {
      const card = await p.locator('[data-key="BH-131"]').boundingBox();
      const target = await p.locator('#col-waiting_tilen .colsub').boundingBox();
      await p.mouse.move(card.x + 60, card.y + 30); await p.mouse.down();
      await p.mouse.move(card.x + 80, card.y + 40, { steps: 3 });
      await p.mouse.move(target.x + 60, target.y + 80, { steps: 8 });
      await p.mouse.up();
      await p.waitForSelector('.askbox');
    } else {
      await p.click('#tab-backlog'); await p.waitForTimeout(300);
      await p.click('[data-key="BH-131"] .tkmv'); await p.waitForSelector('.sheet .mvl');
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
    ok(txt.includes('Move BH-131 to Waiting on you') && txt.includes('Late check-out request in the Home module') && txt.includes('What’s the ask?') && txt.includes('Shown on the card and in Your input.')
      && txt.includes('Enter moves it · Shift+Enter for a new line · 0/280'), `${tag} ask copy (heading, title, label, hints)`);
    ok((await active()).id === 'ask-in', `${tag} ask textarea has focus`);
    await p.click(`${box} .askf .btn`); await p.waitForTimeout(150);
    const askErr = await p.locator(box).innerText();
    ok(askErr.includes('✕ Add the ask. Every ticket in Waiting on you needs one.') && !HTTP_CODE.test(askErr), `${tag} empty ask error copy`);
    ok(await p.evaluate(() => { const t = document.querySelector('#ask-in'); const s = getComputedStyle(t);
      return document.activeElement === t && t.getAttribute('aria-invalid') === 'true' && s.borderTopColor === 'rgb(229, 72, 77)' && s.boxShadow.includes('200, 241, 53'); }),
    `${tag} invalid textarea: red border, focused with the signal ring`);
    ok(await p.evaluate(() => document.querySelector('[data-key="BH-131"]')?.closest('[data-col]')?.dataset.col === 'backlog'), `${tag} card stays put until confirmed`);
    // A failed move shows the error toast without an HTTP code.
    await p.fill('#ask-in', 'Should late check-out be paid?');
    await p.route('**/tickets/BH-131/move', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }));
    await p.keyboard.press('Enter');
    await p.waitForSelector('.toast.err');
    const toast = await p.locator('.toast.err').innerText();
    ok(/Couldn.t move BH-131\. Try again\./.test(toast) && !HTTP_CODE.test(toast), `${tag} move error toast, no HTTP code`);
    await p.unroute('**/tickets/BH-131/move');
    await p.click('.toast.err .tx'); await p.waitForTimeout(800);
    ok(await p.evaluate(() => document.querySelector('[data-key="BH-131"]')?.closest('[data-col]')?.dataset.col === 'backlog'), `${tag} failed move rolls back`);
    // Esc cancels; focus goes back to the dragged card (desktop) / its Move button (mobile).
    if (!m) {
      const card = await p.locator('[data-key="BH-131"]').boundingBox();
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
      ok(!(await p.$('.askbox')) && a.key === 'BH-131' && a.cls.includes('tkl'), `${tag} Esc after a drag returns focus to the dragged card (${JSON.stringify(a)})`);
    } else {
      await p.click('[data-key="BH-131"] .tkmv'); await p.waitForSelector('.sheet .mvl');
      await p.click('.mvl [data-col="waiting_tilen"]'); await p.waitForSelector('.sheet .askf');
      await p.keyboard.press('Escape'); await p.waitForTimeout(200);
      const a = await active();
      ok(!(await p.$('.sheet')) && a.key === 'BH-131' && a.cls.includes('tkmv'), `${tag} Esc closes the sheet, focus back on the card's Move button`);
    }
    ok(await p.evaluate(() => document.querySelector('[data-key="BH-131"]')?.closest('[data-col]')?.dataset.col === 'backlog'), `${tag} cancelled ask leaves the card in Backlog`);
    // ---------------- (d) ticket detail: close paths, dirty guard, nested sheets
    // Opening a ticket marks it seen, so these use BH-121 (no unread, input or blocked state): BH-118 and BH-119 feed the filter checks.
    const isOpen = async () => (await p.locator('.kb-drawer').count()) > 0;
    const waitClosed = () => p.waitForSelector('.kb-drawer', { state: 'detached', timeout: 3000 }).then(() => true, () => false);
    const confirmShown = async () => (await p.locator('[aria-label="Discard your comment?"]').count()) > 0;
    const openTicketDirect = async (key) => { await p.goto(`${BOARD}/tickets/${key}`); await p.waitForSelector('.kb-drawer h1'); await p.waitForTimeout(250); };
    const scrimClick = async () => { await p.mouse.click(60, 400); await p.waitForTimeout(150); }; // desktop: the scrim left of the 800px drawer
    const typeInComposer = async (text) => { await p.click('#cmp'); await p.fill('#cmp', text); await p.waitForTimeout(100); };
    const keepFocused = () => p.evaluate(() => document.activeElement?.textContent ?? '');
    const pressButton = (name) => p.getByRole('button', { name, exact: true }).click();
    const back = () => (m ? p.click('[aria-label="Back to board"]') : scrimClick()); // 390: no scrim, the full-screen panel's Back button
    await p.goto(BOARD); await p.waitForTimeout(300);

    if (m) {
      // Filter sheet: a drag that starts on the sheet and ends on the scrim does not close it; a click on the scrim or Esc does.
      await p.click('.fbtn'); await p.waitForSelector('.sheet'); await p.waitForTimeout(100);
      const hd = await p.locator('.sheet h3').boundingBox();
      await p.mouse.move(hd.x + 10, hd.y + hd.height / 2); await p.mouse.down(); await p.mouse.move(w / 2, 20, { steps: 8 }); await p.mouse.up(); await p.waitForTimeout(150);
      ok(!!(await p.$('.sheet')), `${tag} drag from the filter sheet ending on the scrim does not close it`);
      await p.mouse.click(w / 2, 20); await p.waitForTimeout(150);
      ok(!(await p.$('.sheet')), `${tag} filter sheet closes on backdrop click`);
      await p.click('.fbtn'); await p.waitForSelector('.sheet'); await p.keyboard.press('Escape'); await p.waitForTimeout(150);
      ok(!(await p.$('.sheet')), `${tag} filter sheet closes on Esc`);
    }

    // Clean ticket: backdrop (desktop), Esc, and a click inside the panel never closes it.
    await openTicketDirect('BH-121');
    const title = await p.locator('.kb-drawer h1').boundingBox();
    await p.mouse.click(title.x + 10, title.y + title.height / 2); await p.waitForTimeout(150);
    ok(await isOpen() && !(await confirmShown()), `${tag} click inside the panel does not close`);
    if (!m) {
      const hb = await p.locator('.kb-drawer h1').boundingBox();
      const mid = hb.y + hb.height / 2;
      await p.mouse.move(hb.x + 4, mid); await p.mouse.down(); await p.mouse.move(60, mid, { steps: 12 }); await p.mouse.up(); await p.waitForTimeout(150);
      ok(await isOpen() && !(await confirmShown()), `${tag} drag from inside the panel released on the scrim does not close`);
      await p.mouse.move(60, 400); await p.mouse.down(); await p.mouse.move(hb.x + 10, mid, { steps: 12 }); await p.mouse.up(); await p.waitForTimeout(150);
      ok(await isOpen(), `${tag} drag from the scrim released inside the panel does not close`);
      await scrimClick();
      ok(await waitClosed(), `${tag} backdrop click closes a clean ticket`);
      await openTicketDirect('BH-121');
    }
    await p.keyboard.press('Escape');
    ok(await waitClosed(), `${tag} Esc closes a clean ticket`);

    // Dirty comment box: backdrop (Back on 390) and Esc ask first; Keep editing keeps the text; Discard closes.
    await openTicketDirect('BH-121'); await typeInComposer('Draft reply');
    await back(); await p.waitForTimeout(150);
    ok(await confirmShown() && await isOpen(), `${tag} leaving with a dirty comment shows "Discard unsaved changes?" and keeps the ticket open`);
    ok((await keepFocused()) === 'Keep editing', `${tag} Keep editing is focused by default`);
    await pressButton('Keep editing'); await p.waitForTimeout(150);
    ok(!(await confirmShown()) && await isOpen() && (await p.inputValue('#cmp')) === 'Draft reply', `${tag} Keep editing keeps the ticket open with the text intact`);
    await back(); await p.waitForTimeout(150);
    await pressButton('Discard');
    ok(await waitClosed(), `${tag} Discard closes the ticket`);

    await openTicketDirect('BH-121'); await typeInComposer('Draft reply');
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    ok(await confirmShown() && await isOpen(), `${tag} Esc with a dirty comment asks first`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    ok(!(await confirmShown()) && await isOpen() && (await p.inputValue('#cmp')) === 'Draft reply', `${tag} Esc on the confirm = Keep editing, text intact`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    await pressButton('Discard');
    ok(await waitClosed(), `${tag} Discard after Esc closes the ticket`);

    if (!m) {
      await openTicketDirect('BH-121'); await typeInComposer('Draft reply');
      await p.click('[aria-label="Close"]'); await p.waitForTimeout(150);
      ok(await confirmShown(), `${tag} X button with a dirty comment asks first`);
      await pressButton('Discard'); ok(await waitClosed(), `${tag} Discard from the X button closes the ticket`);
    }

    // Esc inside a dirty description: confirm; Discard drops the edit and keeps the ticket open.
    await openTicketDirect('BH-121');
    await p.click('#desc-h .gbtn'); await p.fill('#desc-in', 'Edited description text'); await p.waitForTimeout(100);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    ok(await confirmShown() && (await p.inputValue('#desc-in')) === 'Edited description text', `${tag} Esc in a dirty description shows the confirm, editor kept`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    ok(!(await confirmShown()) && (await p.inputValue('#desc-in')) === 'Edited description text', `${tag} Keep editing (Esc) keeps the description text`);
    await p.keyboard.press('Escape'); await p.waitForTimeout(150);
    await pressButton('Discard'); await p.waitForTimeout(150);
    ok(!(await p.$('#desc-in')) && await isOpen(), `${tag} Discard drops the description edit, ticket stays open`);
    await p.keyboard.press('Escape');
    ok(await waitClosed(), `${tag} Esc on a clean ticket after that closes it`);

    if (!m) {
      // Labels editor (desktop side panel; on 390 the fields live in the Details sheet).
      await openTicketDirect('BH-121');
      await p.click('.fk .gbtn'); await p.fill('#lb-in', 'Home, Idea, Unsaved'); await p.waitForTimeout(100);
      await p.keyboard.press('Escape'); await p.waitForTimeout(150);
      ok(await confirmShown() && (await p.inputValue('#lb-in')) === 'Home, Idea, Unsaved', `${tag} Esc in a dirty label editor asks first`);
      await pressButton('Discard'); await p.waitForTimeout(150);
      ok(!(await p.$('#lb-in')) && await isOpen(), `${tag} Discard drops the label edit, ticket stays open`);
      await p.keyboard.press('Escape');
      ok(await waitClosed(), `${tag} Esc after that closes the clean ticket`);
    } else {
      // 390: the Details & history sheet sits over the ticket. Esc and its scrim close only that sheet.
      await openTicketDirect('BH-121');
      await p.click('text=Details & history'); await p.waitForSelector('.dsheet'); await p.waitForTimeout(100);
      await p.keyboard.press('Escape'); await p.waitForTimeout(150);
      ok(!(await p.$('.dsheet')) && await isOpen(), `${tag} Esc closes the Details sheet only, ticket stays open`);
      await p.click('text=Details & history'); await p.waitForSelector('.dsheet'); await p.waitForTimeout(100);
      await p.mouse.click(w / 2, 20); await p.waitForTimeout(150);
      ok(!(await p.$('.dsheet')) && await isOpen(), `${tag} Details sheet closes on its scrim, ticket stays open`);
      await p.keyboard.press('Escape');
      ok(await waitClosed(), `${tag} Esc closes the ticket once no sheet is open`);
    }
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
    await p.getByLabel('New replies for you').check(); await p.click('.ffoot .btn'); await p.waitForTimeout(250);
    const pills = await p.evaluate(() => {
      const z = document.querySelector('.ctabs .cnt.is0'), n = document.querySelector('.ctabs .cnt:not(.is0)');
      return { z: z?.getBoundingClientRect().top, n: n?.getBoundingClientRect().top };
    });
    ok(pills.z != null && pills.n != null && Math.abs(pills.z - pills.n) < 1, `[700] zero count pill sits inline with the other counts (${JSON.stringify(pills)})`);
    await p.click('.bbtn'); await p.waitForTimeout(250);
    ok(await p.evaluate(() => !!document.querySelector('.sheet .sform, .sheet [aria-label="Set project status"]') || document.querySelector('.sheet')?.textContent.includes('Save status')), `[700] status menu opens as a sheet`);
    await ctx.close();
  }
} finally {
  await browser.close();
}
console.log(failed ? `\n${failed} check(s) failed` : '\nall checks passed');
process.exit(failed ? 1 : 0);
