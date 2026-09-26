# Tests

Integration and browser tests run against a real WordPress + WooCommerce install (SQLite, no MySQL needed).

## Set up a test site

```bash
tests/bin/setup-env.sh /tmp/lpm-env      # WordPress 7.1.2 + WooCommerce 11.1.2 by default
```

## Integration suite

```bash
WP="php /tmp/lpm-env/wp-cli.phar --allow-root --path=/tmp/lpm-env/site"
$WP eval-file tests/integration/test-plugin.php
```

Run it with HPOS both off and on:

```bash
$WP wc hpos sync && $WP wc hpos enable     # or: $WP wc hpos disable
```

Each check prints `PASS`/`FAIL`, and the last line is `RESULT: <passed> passed, <failed> failed`.
PHP notices end up in `/tmp/lpm-env/site/wp-content/debug.log`; a clean run leaves none from this plugin.

## Browser (E2E) tests

```bash
php -S 127.0.0.1:8899 -t /tmp/lpm-env &                         # serves http://127.0.0.1:8899/site/
$WP eval-file tests/e2e/fixture.php | tail -1 > tests/e2e/fixture.json
node tests/e2e/e2e.cjs                                          # Playwright + Chromium
```
