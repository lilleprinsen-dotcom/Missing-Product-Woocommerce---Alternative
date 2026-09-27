// Browser tests (Playwright + Chromium) for the customer portal and the admin order screen.
//   wp eval-file tests/e2e/fixture.php | tail -1 > /path/to/fixture.json
//   LP_FIXTURE=/path/to/fixture.json node tests/e2e/e2e.cjs      (default: tests/e2e/fixture.json)
const path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const fx = require(process.env.LP_FIXTURE ? path.resolve(process.env.LP_FIXTURE) : './fixture.json');
const base = fx.base || 'http://127.0.0.1:8899/site';
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('  PASS  ' + m); } else { fail++; console.log('  FAIL  ' + m); } };
const text = async (page, sel) => (await page.locator(sel).first().innerText()).replace(/\s+/g, ' ');
const submit = (page, sel) => Promise.all([page.waitForNavigation(), page.click(sel)]);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  // ------------------------------------------------------------------------------------------------------------
  console.log('[Browser] Guest customer portal (JavaScript on, phone width)');
  const guestCtx = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true });
  const guest = await guestCtx.newPage();
  const landing = await guest.goto(fx.portal);
  const linkRequest = landing.request().redirectedFrom();
  ok(linkRequest && linkRequest.url().includes('lpk='), 'magic link answers with a redirect');
  ok(!guest.url().includes('lpk=') && guest.url().includes('oid=' + fx.order1), 'lands on a clean URL without the key: ' + guest.url());
  const linkHeaders = linkRequest ? await (await linkRequest.response()).allHeaders() : {};
  ok(/no-store|no-cache/.test(linkHeaders['cache-control'] || ''), 'link redirect is not cacheable');
  ok(/HttpOnly/i.test(linkHeaders['set-cookie'] || '') && /SameSite=Lax/i.test(linkHeaders['set-cookie'] || ''), 'session cookie set with HttpOnly and SameSite=Lax');
  const headers = await landing.allHeaders();
  ok(/no-store|no-cache/.test(headers['cache-control'] || ''), 'portal page: Cache-Control ' + headers['cache-control']);
  ok(headers['referrer-policy'] === 'no-referrer', 'portal page: Referrer-Policy no-referrer');
  ok((headers['x-robots-tag'] || '').includes('noindex'), 'portal page: X-Robots-Tag noindex');
  const robots = await guest.locator('meta[name=robots]').getAttribute('content');
  ok(robots && robots.includes('noindex'), 'portal page: meta robots ' + robots);
  const cookie = (await guestCtx.cookies()).find(c => c.name === 'lp_missing_portal_' + fx.order1);
  ok(cookie && cookie.httpOnly && cookie.sameSite === 'Lax' && cookie.secure === false && cookie.expires > Date.now() / 1000, 'browser holds an expiring HttpOnly per-order cookie (not Secure on http)');
  ok(await guest.locator('link[href*="assets/css/portal.css"]').count() === 1 && await guest.locator('script[src*="assets/js/portal.js"]').count() === 1, 'portal CSS and JS are loaded');
  ok(await guest.locator('.lp-missing-portal style').count() === 0, 'no inline style blocks in the portal');

  ok(await guest.locator('input[name=lp_missing_verify_email]').count() === 1, 'guest is asked to confirm the billing email');
  await guest.fill('#lp-verify-email', 'feil@example.com');
  await submit(guest, '.lp-portal-verify-form button[type=submit]');
  ok((await text(guest, '[role=alert]')).includes('stemmer ikke'), 'wrong email is announced as an alert');
  await guest.fill('#lp-verify-email', 'KUNDE@example.com');
  await submit(guest, '.lp-portal-verify-form button[type=submit]');
  ok(await guest.locator('fieldset.lp-portal-item').count() === 2, 'after confirming: one card (fieldset) per missing line');
  ok(await guest.locator('fieldset.lp-portal-item legend').count() === 2, 'each card has a legend');
  ok(await guest.locator('select').count() === 0 && await guest.locator('.lp-option__input[type=radio]').count() >= 4, 'answers are radio cards, not a select');
  ok(await guest.locator(`input[value="alt:${fx.out}"]`).count() === 0, 'sold-out alternative is hidden');
  ok((await text(guest, `.lp-option:has(input[value="alt:${fx.low}"])`)).includes('Få igjen'), 'low-stock alternative is marked "Få igjen"');
  const premiumCard = await text(guest, `.lp-option:has(input[value="alt:${fx.premium}"])`);
  ok(premiumCard.includes('per stk') && premiumCard.includes('/kg'), 'unit price and price per kg shown: ' + premiumCard.slice(0, 80));
  ok(await guest.locator(`.lp-option:has(input[value="alt:${fx.premium}"]) img`).evaluate(el => el.complete && el.naturalWidth > 0), 'product image is shown');
  ok(await guest.locator(`input[name="lp_choice[${fx.item1b}]"]`).count() === 2, 'line without alternatives offers only Nei takk / Fjern');

  const qty = guest.locator('#lp-qty-' + fx.item1a);
  ok(await qty.getAttribute('inputmode') === 'numeric', 'quantity uses the numeric keyboard');
  ok(parseFloat(await qty.evaluate(el => getComputedStyle(el).fontSize)) >= 16 && parseFloat(await guest.locator('#lp-qty-' + fx.item1a).evaluate(el => getComputedStyle(el).fontSize)) >= 16, 'inputs are at least 16px (no zoom on iOS)');
  const heights = await guest.$$eval('.lp-option__label, .lp-portal-actions button, .lp-option__link', els => els.map(e => e.getBoundingClientRect().height));
  ok(Math.min(...heights) >= 44, 'touch targets are at least 44px high (min ' + Math.min(...heights) + ')');
  ok(await guest.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal scrolling at 375px');
  ok(await guest.locator('.lp-portal-summary [aria-live=polite]').count() === 1, 'summary is an aria-live region');
  ok(await guest.locator('.lp-missing-portal [aria-hidden=true]').count() >= 3, 'decorative emoji are aria-hidden');
  ok(await qty.isDisabled(), 'quantity waits until an alternative is chosen');

  await guest.focus(`#lp-opt-${fx.item1a}-${fx.premium}`);
  await guest.keyboard.press('ArrowDown');
  const outline = await guest.locator(`label[for="lp-opt-${fx.item1a}-${fx.low}"]`).evaluate(el => getComputedStyle(el).outlineStyle);
  ok(outline && outline !== 'none', 'keyboard focus is visible on the card (' + outline + ')');

  await guest.click(`label[for="lp-opt-${fx.item1a}-${fx.premium}"]`);
  ok(!(await qty.isDisabled()), 'quantity enabled for an alternative');
  await qty.fill('1');
  let summary = await text(guest, '.lp-portal-summary__body');
  ok(summary.includes('Mellomlegget på') && summary.includes('Resten (1 stk)') && summary.includes('Mellomlegg som faktureres'), 'live summary: surcharge and remaining quantity: ' + summary);
  ok(summary.includes('Ikke valgt ennå'), 'live summary lists the unanswered line');
  await guest.click(`label[for="lp-opt-${fx.item1b}-delete"]`);
  summary = await text(guest, '.lp-portal-summary__body');
  ok(summary.includes('Fjernes fra ordren') && !summary.includes('Ikke valgt ennå'), 'live summary follows every change');

  const [saved] = await submit(guest, '.lp-portal-actions button[type=submit]');
  const posted = saved.request().redirectedFrom();
  ok(posted && posted.method() === 'POST' && saved.request().method() === 'GET', 'POST is answered with a redirect (PRG)');
  ok(guest.url().includes('lp_msg=saved') && !guest.url().includes('lpk='), 'redirected to the clean URL with a saved message');
  ok((await text(guest, '.lp-portal-notices')).includes('Takk! Valget ditt er lagret'), 'saved message shown');
  ok(await guest.evaluate(() => !!document.activeElement && document.activeElement.classList.contains('lp-portal-notice')), 'status message receives focus');
  const reloaded = await guest.reload();
  ok(reloaded.request().method() === 'GET', 'reloading does not resubmit the form');
  const card = await text(guest, `fieldset[data-lp-item="${fx.item1a}"]`);
  ok(card.includes('Ditt valg nå:') && card.includes('E2E Bleier premium × 1') && card.includes('Pris låst ved valg'), 'current decision and locked price shown');

  await guest.click(`label[for="lp-opt-${fx.item1a}-decline"]`);
  ok(await qty.isDisabled(), 'quantity disabled again for Nei takk');
  await submit(guest, '.lp-portal-actions button[type=submit]');
  ok(guest.url().includes('lp_msg=saved'), 'changed decision saved');
  ok((await text(guest, `fieldset[data-lp-item="${fx.item1a}"] .lp-portal-decision`)).includes('Nei takk'), 'the decision was changed to Nei takk');
  ok(await guest.locator(`fieldset[data-lp-item="${fx.item1a}"] input[value="decline"]`).isChecked(), 'the new choice is pre-selected');

  await guest.goto(base + '/');
  ok(await guest.locator('link[href*="assets/css/portal.css"]').count() === 0, 'portal CSS is not loaded on other pages');
  await guestCtx.close();

  // ------------------------------------------------------------------------------------------------------------
  console.log('[Browser] Guest customer portal without JavaScript');
  const plainCtx = await browser.newContext({ javaScriptEnabled: false });
  const plain = await plainCtx.newPage();
  await plain.goto(fx.portal3);
  await plain.fill('#lp-verify-email', 'kunde@example.com');
  await submit(plain, '.lp-portal-verify-form button[type=submit]');
  ok(await plain.locator('fieldset.lp-portal-item').count() === 1, 'portal works without JavaScript');
  await plain.click(`label[for="lp-opt-${fx.item3}-${fx.premium}"]`);
  await plain.fill('#lp-qty-' + fx.item3, '2');
  await submit(plain, '.lp-portal-actions button[type=submit]');
  ok(plain.url().includes('lp_msg=saved'), 'choice saved without JavaScript');
  const plainCard = await text(plain, `fieldset[data-lp-item="${fx.item3}"]`);
  ok(plainCard.includes('E2E Bleier premium × 2'), 'decision shown without JavaScript');
  ok((await text(plain, '.lp-portal-summary__body')).includes('Mellomlegget på'), 'server-rendered summary without JavaScript');
  await plainCtx.close();

  // ------------------------------------------------------------------------------------------------------------
  console.log('[Browser] Staff preview (read-only)');
  const staffCtx = await browser.newContext();
  await staffCtx.addCookies([{ name: fx.previewCookie.name, value: fx.previewCookie.value, domain: new URL(base).hostname, path: fx.previewCookie.path }]);
  const staff = await staffCtx.newPage();
  const previewResponse = await staff.goto(fx.preview);
  ok((await text(staff, '.lp-portal-banner')).includes('Staff preview'), 'preview banner shown');
  ok(await staff.locator('form.lp-portal-form').count() === 0 && await staff.locator('.lp-portal-actions button').count() === 0, 'no form and no save button in the preview');
  ok(await staff.locator('.lp-option__input:not([disabled])').count() === 0, 'all answers are disabled');
  ok((await previewResponse.allHeaders())['referrer-policy'] === 'no-referrer', 'preview is sent with private headers');
  ok(!(await staffCtx.cookies()).some(c => c.name.startsWith('lp_missing_portal_')), 'preview creates no customer session');
  await staffCtx.close();

  // ------------------------------------------------------------------------------------------------------------
  console.log('[Browser] Admin order screen (' + (fx.edit2.includes('wc-orders') ? 'HPOS' : 'legacy') + ')');
  const admin = await browser.newPage();
  admin.on('dialog', d => d.accept());
  await admin.goto(base + '/wp-login.php');
  await admin.fill('#user_login', 'admin');
  await admin.fill('#user_pass', 'admin');
  await Promise.all([admin.waitForNavigation(), admin.click('#wp-submit')]);
  await admin.goto(fx.edit2);
  ok(await admin.locator('#lp_missing_metabox').count() === 1, 'missing-items box is shown on the order screen');
  ok(await admin.locator('#lp_missing_metabox form').count() === 0, 'box contains no nested form');
  // Click the order's own Update/Save button while a customer decision is pending.
  const saveBtn = admin.locator('button[name=save], input[name=save]').first();
  await Promise.all([admin.waitForNavigation(), saveBtn.click()]);
  const url = admin.url();
  ok(url.includes('action=edit') && (url.includes('id=' + fx.order2) || url.includes('post=' + fx.order2)), 'order Update stays on the order (not hijacked): ' + url);
  const upd = await admin.locator('#message, .notice').allInnerTexts();
  ok(upd.join(' ').match(/Order updated|updated/i), 'WooCommerce reports the order as updated');
  const link = admin.locator('#lp_missing_metabox a.lp-missing-confirm', { hasText: 'Replace the missing item' });
  ok(await link.count() === 1, 'apply link is present');
  await Promise.all([admin.waitForNavigation(), link.click()]);
  const notices = (await admin.locator('.notice').allInnerTexts()).join(' ');
  ok(/Replaced 1 × E2E Bleier with E2E Bleier premium\./.test(notices), 'apply link applies the decision and says what it did: ' + notices.slice(0, 160));
  const status = await admin.locator('#lp_missing_metabox').innerText();
  ok(!status.includes('pending staff'), 'box no longer shows a pending decision');

  await browser.close();
  console.log(`BROWSER RESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
