# Tests

Integration and browser tests run against a real WordPress + WooCommerce install (SQLite, no MySQL needed).

## Set up a test site

```bash
tests/bin/setup-env.sh /tmp/lpm-env      # WordPress 7.1.2 + WooCommerce 11.1.2 by default
```

## Integration suites

```bash
WP="php /tmp/lpm-env/wp-cli.phar --allow-root --path=/tmp/lpm-env/site"
for t in test-plugin test-admin test-portal test-notify test-review; do $WP eval-file tests/integration/$t.php | tail -1; done
```

| File | Covers |
|---|---|
| `test-plugin.php` | Core flows: marking, portal choice, apply (replace/add/reduce/refund), totals, VAT, surcharges, stock, cleanup |
| `test-admin.php` | Order screen, order list views/column, AJAX endpoints, link tools |
| `test-portal.php` | Customer links (v1/v2, expiry, revocation, cookie), portal form, decision changes, headers, page setup |
| `test-notify.php` | Action Scheduler, reminders, deadline action, emails, customer notes, hooks |
| `test-review.php` | Regressions from code review: stale order screens and apply links, concurrent writes, deadline safety, portal hardening |

Shared helpers live in `bootstrap.php` (and `portal-helpers.php` for portal requests).

Run them with HPOS both off and on:

```bash
$WP wc hpos sync && $WP wc hpos enable     # or: $WP wc hpos disable
```

Each check prints `PASS`/`FAIL`, and the last line is `RESULT: <passed> passed, <failed> failed`.
PHP notices end up in `/tmp/lpm-env/site/wp-content/debug.log`; a clean run leaves none from this plugin.

## Browser (E2E) tests

```bash
php -S 127.0.0.1:8899 -t /tmp/lpm-env &                                  # serves http://127.0.0.1:8899/site/
$WP eval-file tests/e2e/fixture.php | tail -1 > /tmp/fx-portal.json
LP_FIXTURE=/tmp/fx-portal.json node tests/e2e/e2e.cjs                      # customer portal + order screen basics
$WP eval-file tests/e2e/admin-fixture.php | tail -1 > /tmp/fx-admin.json
node tests/e2e/admin.e2e.cjs /tmp/fx-admin.json                           # order screen tools and order list
```

Both need Playwright with Chromium.
