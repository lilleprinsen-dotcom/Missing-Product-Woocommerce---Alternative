// Admin order screen and order list (Playwright + Chromium).
//   wp eval-file tests/e2e/admin-fixture.php | tail -1 > tests/e2e/admin-fixture.json
//   node tests/e2e/admin.e2e.cjs [path/to/admin-fixture.json]
// Run it once with HPOS on and once with it off (a new fixture each time).
const path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const fx = require(path.resolve(process.argv[2] || path.join(__dirname, 'admin-fixture.json')));

let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('  PASS  ' + m); } else { fail++; console.log('  FAIL  ' + m); } };
const row = (page, id) => page.locator(`tr#post-${id}, tr#order-${id}`);
// Waits (up to 15s) until fn(arg) is truthy in the page; the AJAX responses can be slow on the shared dev server.
const until = (page, fn, arg) => page.waitForFunction(fn, arg, { timeout: 15000 }).catch(() => {});

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
  // The single-threaded PHP dev server can be busy: be patient with navigations.
  context.setDefaultNavigationTimeout(120000);
  context.setDefaultTimeout(60000);
  const page = await context.newPage();
  const scriptErrors = [];
  page.on('pageerror', (e) => { if (/lp-missing|admin\.js|lpMissing/.test(String(e.stack || e))) scriptErrors.push(String(e)); });
  let dialogs = [];
  let answer = true;
  page.on('dialog', (d) => { dialogs.push(d.message()); answer ? d.accept() : d.dismiss(); });

  await page.goto(fx.login);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  console.log(`[Admin] Order screen (${fx.hpos ? 'HPOS' : 'legacy'})`);
  await page.goto(fx.openEdit);
  const box = page.locator('#lp_missing_metabox');
  ok(await box.count() === 1, 'missing-items box is shown');
  ok(await page.locator(`link#lp-missing-admin-css[href*="assets/css/admin.css?ver=${fx.version}"]`).count() === 1, 'admin.css enqueued with the plugin version');
  ok(await page.locator(`script[src*="assets/js/admin.js?ver=${fx.version}"]`).count() === 1, 'admin.js enqueued with the plugin version');
  ok(await page.evaluate(() => typeof window.lpMissingAdminApi === 'object' && !!window.lpMissingAdmin && !!window.lpMissingAdmin.ajaxUrl), 'admin.js runs and has its settings');
  ok(await box.locator('[style]').count() === 0, 'no inline styles in the box');
  ok(await page.evaluate(() => getComputedStyle(document.querySelector('.lp-missing-status')).backgroundColor) === 'rgb(248, 248, 248)', 'styles come from admin.css');

  // S4 history line.
  const history = (await box.locator('.lp-missing-history').first().innerText()).trim();
  ok(/^Notified \d\d\.\d\d · 0\/\d+ reminders · next \d\d\.\d\d \d\d:\d\d$/.test(history), 'history line: ' + history);

  // S9 preview on load.
  const items = box.locator(`.lp-missing-item[data-item-id="${fx.openItem}"] .lp-alt-list li`);
  ok(await items.count() === 2, 'saved alternatives are previewed');
  const dear = box.locator(`.lp-alt-list li[data-product-id="${fx.dear}"]`);
  const none = box.locator(`.lp-alt-list li[data-product-id="${fx.none}"]`);
  const dearText = await dear.innerText();
  ok(/150[.,]00\/unit incl\. VAT/.test(dearText) && /\+kr\s?100[.,]00 for 2 \(\+50%\)/.test(dearText), 'price and difference for the missing quantity: ' + dearText.replace(/\s+/g, ' '));
  ok(await dear.evaluate((el) => el.classList.contains('lp-alt-preview--pricey') && getComputedStyle(el).backgroundColor === 'rgb(252, 249, 232)'), 'more than +20% is yellow');
  ok(await none.evaluate((el) => el.classList.contains('lp-alt-preview--nostock') && getComputedStyle(el).backgroundColor === 'rgb(252, 240, 241)'), 'out of stock is red');

  // Preview refresh without saving: drop an alternative, then change the missing quantity.
  const sel = `#lp-missing-alt-${fx.openItem}`;
  let resp = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData().includes('lp_missing_preview_alternatives'));
  await page.evaluate(([s, keep]) => { const $ = window.jQuery; $(s).find('option').filter((i, o) => o.value !== String(keep)).prop('selected', false); $(s).trigger('change'); }, [sel, fx.dear]);
  await resp;
  await until(page, (id) => document.querySelectorAll(`.lp-missing-item[data-item-id="${id}"] .lp-alt-list li`).length === 1, fx.openItem);
  ok(await items.count() === 1, 'preview follows the selection (AJAX, not saved)');
  resp = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData().includes('lp_missing_preview_alternatives'));
  await page.fill(`input[name="lp_missing_items[${fx.openItem}][qty_missing]"]`, '1');
  await resp;
  await until(page, (id) => /for 1 \(/.test(document.querySelector(`.lp-alt-list li[data-product-id="${id}"]`).textContent), fx.dear);
  ok(/for 1 \(\+50%\)/.test(await dear.innerText()), 'preview follows the missing quantity');

  // S5 customer link tools.
  const copy = box.locator('button.lp-missing-copy-link');
  ok(await copy.count() === 1 && await copy.getAttribute('data-link') === fx.openLink, 'copy button carries the customer link');
  await copy.click();
  await until(page, () => document.querySelector('.lp-missing-copy-status').textContent !== '');
  ok((await box.locator('.lp-missing-copy-status').innerText()).includes('copied'), 'copy reports success');
  ok(await page.evaluate(() => navigator.clipboard.readText()) === fx.openLink, 'customer link is on the clipboard');
  await page.evaluate(() => { navigator.clipboard.writeText = () => Promise.reject(new Error('denied')); });
  await copy.click();
  await until(page, () => !document.querySelector('.lp-missing-link-field').hidden);
  const field = box.locator('.lp-missing-link-field');
  ok(await field.isVisible() && await field.inputValue() === fx.openLink, 'clipboard refused: the link is shown in a text field');

  const preview = box.locator('a.lp-missing-preview');
  const href = await preview.getAttribute('href');
  ok(await preview.getAttribute('target') === '_blank' && /noopener/.test(await preview.getAttribute('rel')), 'view-as-customer opens a new tab');
  ok(href.includes(`oid=${fx.open}`) && href.includes('lp_preview='), 'view-as-customer is the staff preview URL: ' + href);
  const [popup] = await Promise.all([context.waitForEvent('page'), preview.click()]);
  await popup.waitForLoadState('domcontentloaded');
  ok(popup.url() === href, 'new tab opens the preview URL');
  await popup.close();

  const revoke = box.locator('a.lp-missing-revoke');
  const revokeHref = await revoke.getAttribute('href');
  ok(revokeHref.includes('admin-post.php') && revokeHref.includes('action=lp_missing_revoke_links') && revokeHref.includes(`order_id=${fx.open}`) && /_wpnonce=[a-f0-9]+/.test(revokeHref), 'revoke link is a nonce\'d admin-post action');
  answer = false; dialogs = [];
  const before = page.url();
  await revoke.click();
  await page.waitForTimeout(300);
  ok(dialogs.length === 1 && dialogs[0].includes('Revoke all customer links') && page.url() === before, 'revoke asks first; cancelling does nothing');
  answer = true; dialogs = [];
  await Promise.all([page.waitForNavigation(), revoke.click()]);
  const notices = (await page.locator('.notice').allInnerTexts()).join(' ');
  ok(notices.includes('Customer links revoked'), 'revoking shows a notice');
  ok((await page.locator('#woocommerce-order-notes').innerText()).includes('Customer portal links revoked by'), 'revocation noted on the order');
  ok(page.url().includes(String(fx.open)) && page.url().includes('lp_missing_links_revoked=1'), 'back on the order after revoking');

  console.log('[Admin] Same product, other variant + Update');
  await page.goto(fx.vEdit);
  const vbox = page.locator(`#lp_missing_metabox .lp-missing-item[data-item-id="${fx.vItem}"]`);
  const vbtn = vbox.locator('button.lp-missing-variants');
  ok(await vbtn.count() === 1, 'variant button shown for a variation line');
  resp = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData().includes('lp_missing_variant_suggestions'));
  const previewResp = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData().includes('lp_missing_preview_alternatives'));
  await vbtn.click();
  const json = await (await resp).json();
  ok(json.success && json.data.suggestions.map((s) => s.id).join() === [fx.variants['M-Blue'], fx.variants['L-Red'], fx.variants['L-Blue']].join(), 'endpoint suggests the 3 closest in-stock variants');
  await previewResp;
  await until(page, (id) => document.querySelectorAll(`.lp-missing-item[data-item-id="${id}"] .lp-alt-list li`).length === 3, fx.vItem);
  const selected = await vbox.locator('.lp-alt-select').evaluate((s) => Array.from(s.selectedOptions).map((o) => Number(o.value)));
  ok(selected.join() === [fx.variants['M-Blue'], fx.variants['L-Red'], fx.variants['L-Blue']].join(), 'variants added to the select as selected options');
  ok((await vbox.locator('.lp-missing-variants-status').innerText()).includes('Added 3 variant'), 'status says what was added');
  ok(await vbox.locator('.lp-alt-list li').count() === 3, 'preview refreshed for the new alternatives');
  ok(/\+kr\s?20[.,]00 for 2 \(\+10%\)/.test(await vbox.locator(`.lp-alt-list li[data-product-id="${fx.variants['L-Red']}"]`).innerText()), 'variant preview shows its difference');
  await vbtn.click();
  await until(page, (id) => /maximum/.test(document.querySelector(`.lp-missing-item[data-item-id="${id}"] .lp-missing-variants-status`).textContent), fx.vItem);
  ok((await vbox.locator('.lp-missing-variants-status').innerText()).includes('maximum'), 'a full list is not extended');
  ok(await page.evaluate((id) => window.lpMissingAdminApi.addAlternatives(window.jQuery(`#lp-missing-alt-${id}`), [{ id: 999999, text: 'X' }]), fx.vItem) === 0, 'never more than the maximum');

  const saveBtn = page.locator('button[name=save], input[name=save]').first();
  await Promise.all([page.waitForNavigation(), saveBtn.click()]);
  const url = page.url();
  ok(url.includes('action=edit') && (url.includes('id=' + fx.vorder) || url.includes('post=' + fx.vorder)), 'Update stays on the order: ' + url);
  ok((await page.locator('#message, .notice').allInnerTexts()).join(' ').match(/Order updated|updated/i), 'WooCommerce reports the order as updated');
  const saved = await page.locator(`#lp-missing-alt-${fx.vItem}`).evaluate((s) => Array.from(s.selectedOptions).map((o) => Number(o.value)));
  ok(saved.join() === selected.join(), 'suggested variants were saved with the order');
  ok(await page.locator(`#lp_missing_metabox .lp-missing-item[data-item-id="${fx.vItem}"] .lp-alt-list li`).count() === 3, 'saved variants previewed after reload');

  console.log(`[Admin] Order list (${fx.hpos ? 'HPOS' : 'legacy'})`);
  await page.goto(fx.listReady);
  const readyView = page.locator('.subsubsub a', { hasText: 'Customer answered (' });
  ok(await readyView.count() === 1 && (await readyView.innerText()).includes(`Customer answered (${fx.readyCount})`), 'ready view with count: ' + (await readyView.count() ? await readyView.innerText() : '-'));
  ok(await readyView.getAttribute('class') === 'current' && await page.locator('.subsubsub a.current').count() === 1, 'ready view is the only current view');
  ok(await page.locator('.subsubsub a', { hasText: `Missing items (${fx.openCount})` }).count() === 1, 'missing items view still there');
  const readyRow = row(page, fx.ready);
  ok(await readyRow.count() === 1, 'the ready order is listed');
  ok(await row(page, fx.vorder).count() === 0 && await row(page, fx.open).count() === 0, 'orders still waiting for the customer are not');
  const state = readyRow.locator('.lp-missing-state');
  ok((await state.innerText()).includes('Customer answered – ready to apply'), 'column shows the ready state');
  ok(await state.evaluate((el) => el.classList.contains('lp-missing-state--ready') && getComputedStyle(el).color === 'rgb(0, 112, 23)'), 'ready state is green (admin.css on the list)');
  await page.goto(fx.listOpen);
  ok((await row(page, fx.vorder).locator('.lp-missing-state').innerText()).includes('Awaiting resolution'), 'open view lists waiting orders as "Awaiting resolution"');

  ok(scriptErrors.length === 0, 'no script errors from admin.js ' + scriptErrors.join(' | '));
  await browser.close();
  console.log(`BROWSER RESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
