const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const fx = require('./fixture.json');
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('  PASS  ' + m); } else { fail++; console.log('  FAIL  ' + m); } };
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  console.log('[Browser] Guest customer portal');
  const guest = await browser.newPage();
  await guest.goto(fx.portal);
  ok(await guest.locator('input[name=lp_missing_verify_email]').count() === 1, 'magic link asks the guest to confirm the email');
  await guest.fill('input[name=lp_missing_verify_email]', 'kunde@example.com');
  await Promise.all([guest.waitForNavigation(), guest.click('.lp-missing-portal-verify button[type=submit]')]);
  ok(await guest.locator('select[name=lp_missing_alt_id]').count() === 1, 'after confirming, the choice form is shown');
  const priceText = await guest.locator('.lp-portal-item').innerText();
  ok(!priceText.includes('<span') && !priceText.includes('&lt;'), 'price text is rendered as text, not markup');
  await guest.fill('input[name=lp_missing_alt_qty]', '2');
  await Promise.all([guest.waitForNavigation(), guest.click('button[value=accept_alt]')]);
  const body = await guest.locator('body').innerText();
  ok(body.includes('Takk! Valget ditt er lagret'), 'choosing an alternative is saved (no verification loop)');
  ok(await guest.locator('input[name=lp_missing_verify_email]').count() === 0, 'guest is not sent back to email verification');
  ok(body.includes('Du valgte:'), 'portal shows the registered choice');
  await guest.reload(); // browser re-POST prompt is auto-accepted by playwright? reload re-submits the POST
  const body2 = await guest.locator('body').innerText();
  ok(!body2.includes('Fatal') && !body2.includes('critical error'), 'reloading the page after choosing does not break anything');

  console.log('[Browser] Admin order screen (' + (fx.edit2.includes('wc-orders') ? 'HPOS' : 'legacy') + ')');
  const admin = await browser.newPage();
  admin.on('dialog', d => d.accept());
  await admin.goto('http://127.0.0.1:8899/site/wp-login.php');
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
  const link = admin.locator('#lp_missing_metabox a.lp-missing-confirm', { hasText: 'Replace missing quantity' });
  ok(await link.count() === 1, 'apply link is present');
  await Promise.all([admin.waitForNavigation(), link.click()]);
  const notices = (await admin.locator('.notice').allInnerTexts()).join(' ');
  ok(notices.includes('Customer decision applied'), 'apply link applies the decision: ' + notices.slice(0, 160));
  const status = await admin.locator('#lp_missing_metabox').innerText();
  ok(!status.includes('pending staff'), 'box no longer shows a pending decision');

  await browser.close();
  console.log(`BROWSER RESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
