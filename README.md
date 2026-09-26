# Missing Product WooCommerce Alternative

Handles order lines that turn out to be missing when an order is picked: staff mark the line, suggest up to three alternatives, the customer chooses in a secure portal (alternative, removal or "no thanks"), and staff apply the decision to the order with correct totals, VAT, refunds, surcharges and stock.

Requires WordPress 6.5+, WooCommerce (HPOS and legacy order storage are both supported) and PHP 7.4+.

## Installation

Upload the whole plugin folder (it contains `missing-product-woocommerce-alternative.php`, `includes/`, `templates/`, `assets/`) to `wp-content/plugins/` and activate it. On activation a "Velg erstatning" page with the `[lp_missing_items]` portal is created if no portal page is configured.

Settings: **WooCommerce → Missing Items Settings**. Email texts: **WooCommerce → Settings → Emails**. The order list has the views «Missing items», «Customer answered» and «Needs follow-up».

The admin screens, staff emails and order notes are in English with a Norwegian (bokmål) translation in `languages/` (used when the site or user language is Norsk bokmål); customer texts are written in Norwegian.

## How it works

1. **Mark missing** – in the order's "Missing items" box, press «Missing» on the line, set how many are missing, pick replacements (WooCommerce product search; other sizes/variants are suggested automatically) and optionally a message (one-click texts). «Save» in the box saves the order and emails the customer a link to the portal.
2. **Customer chooses** – the portal shows each missing line with the alternatives, prices and the price difference (frozen when the customer chooses). The customer confirms their billing email once per browser, and can change the choice until staff apply it. Cancelled, refunded, failed or trashed orders cannot be changed from the portal.
3. **Staff apply** – each line shows where it stands (waiting, answered, needs follow-up, done) and one button that says what happens, e.g. «Replace the missing item» with the price consequence. Options: replace the missing quantity, add the alternative as an extra line, remove the quantity from the order, or record a refund. A more expensive alternative creates a separate surcharge order (with VAT) unless the store covers the difference. Apply buttons are tied to the choice shown on the screen: if the customer changes their mind after the page was loaded, nothing is applied and staff are asked to check the new choice. Saving an order screen that is out of date (e.g. the deadline job or another user already resolved a line) leaves those lines alone and says so.
4. **Reminders, deadline and cleanup** – reminders (one per order, within the configured hours), escalation to staff, an optional automatic action when the customer does not answer by the deadline, and automatic cleanup of resolved data. The deadline starts when the customer is emailed; a line whose missing quantity or stock changed after that is left to staff. Scheduling uses WooCommerce's Action Scheduler (WooCommerce → Status → Scheduled Actions, group `lp-missing`).

### Money and VAT

- The alternative line takes over exactly the original line's share (net, per-rate VAT, discounts), so the order total does not change; the price difference is handled separately (surcharge order or covered by the store).
- Prices are compared including VAT for the order's own tax address, before coupon discounts. Alternatives in another tax class or tax status keep the paid gross amount, re-split at their own rate.
- Shares of a line are split on the amount the customer paid (incl. VAT, rounded to the shop's price decimals), so 3 × 19.99 splits into 19.99 + 39.98 and refunds of every unit add up to the line, also with 0 decimals.
- Refunds are recorded on the order line (quantity, net and VAT). A refund made in WooCommerce on a line with an open case settles that many missing units, so the plugin never refunds them twice.
- A replacement line counts as the original product for coupons, so re-calculating the order's coupons keeps the total.

### Payment (reserve and capture, e.g. Dintero)

What happens to a missing quantity depends on where the customer's money stands:

| Payment | Removing a missing quantity | |
|---|---|---|
| Unpaid (pending, on hold, failed) | lowers the order total | |
| Reserved (Dintero, before the order is completed) | lowers the order total, so Dintero charges less at capture | «Record a refund» is not offered: nothing is charged yet |
| Captured (Dintero, after the order is completed) | is refunded to the customer through Dintero | lowering the total is refused |
| Paid through another gateway | lowers the total (default) or records a refund; staff pay it back in the payment provider | |

- Dintero Checkout captures when the order is set to Completed and can lower the amount, never raise it. Complete orders only when every missing item is settled: completing with open cases adds a warning (order note and notice), and those items are then refunded through Dintero when settled.
- A dearer replacement's difference is invoiced as a separate surcharge order (Dintero cannot charge more than was reserved). Unpaid surcharge orders are cancelled when their main order is cancelled or refunded.
- A replacement that takes over a whole line keeps its Dintero line ID; a partial one gets an ID of its own.
- Other reserve-and-capture gateways can report their state with the `lp_missing_payment_state` filter.
- WooCommerce's own refund email is not sent for refunds the plugin makes: the customer gets the plugin's note instead. Notes about several staff decisions on one order are collected for a few minutes and sent as one email.

### Stock

- While a case is open, the missing quantity is locked (stock reduced by that amount) if "Lock stock for missing quantities" is on. The lock is released when the case is resolved, cleared, the line is deleted, or the order is cancelled, refunded, failed or deleted. A failed order keeps its case, and the lock is taken again if the payment is retried.
- Units confirmed missing never come back into stock: when a line is reduced, only its stock record follows the new quantity, so WooCommerce's own stock sync does not restock them later. The same holds for units moved to a replacement added as a separate line. Alternatives reduce stock like any added line (for orders whose stock is already reduced; unpaid orders reduce stock at payment).

## Extending

Templates can be overridden in `yourtheme/lp-missing/` (see `templates/`). See [Hooks](#hooks) for actions and filters. Customer links expire (setting), can be revoked per order from the order screen, and are exchanged for a short-lived session cookie on first use. Portal pages are sent with no-cache, no-referrer and noindex headers (and `DONOTCACHEPAGE`); a page cache or CDN must not strip the `lp_missing_portal_*` cookie.

## Hooks

Actions:

| Action | Arguments | When |
|---|---|---|
| `lp_missing_item_updated` | `$order, $item_id, $new_data, $old_data` | A line's missing-item data changed (drives notifications, reminders and deadlines). |
| `lp_missing_customer_decision` | `$order, $item_id, $new_data, $old_data` | The customer saved or changed a decision in the portal. |
| `lp_missing_customer_notified` | `$order, $type` | The portal email (`initial`) or a reminder (`reminder`) was sent. |
| `lp_missing_before_apply_decision` | `$order, $item_id, $type, $mode, null, $context` | Before a decision is applied (`$context`: `manual` or `automatic`). |
| `lp_missing_after_apply_decision` | `$order, $item_id, $type, $mode, $result, $context` | After a decision was applied. |
| `lp_missing_surcharge_order_created` | `$surcharge_order, $order, $snapshot` | A surcharge order for a more expensive alternative was created. |
| `lp_missing_deadline_action` | `$order, $item_id, $mode` | The automatic deadline action ran for a line. |
| `lp_missing_settings_saved` | `$settings` | After the settings page was saved. |
| `lp_missing_portal_private_headers` | `$headers` | The portal sent its no-cache / no-referrer / noindex headers. |
| `lp_missing_portal_set_cookie` | `$name, $value, $expires, $params` | The portal set its session cookie. |

Filters:

| Filter | Arguments | Purpose |
|---|---|---|
| `lp_missing_settings_fields` | `$fields` | Settings schema (add or change settings). |
| `lp_missing_upgrade_steps` | `$steps` | Versioned one-time upgrade steps. |
| `lp_missing_store_covers_difference` | `$covers, $gross_delta` | Whether the store absorbs a price difference. |
| `lp_missing_price_delta` | `$delta, $order, $item, $alt_product, $qty, $snapshot` | Price difference (incl. VAT) used for the surcharge. |
| `lp_missing_customer_note` | `$text, $order, $context` | Customer-visible order note written after an apply. |
| `lp_missing_customer_note_delay` | `$seconds, $order` | How long notes about staff decisions are collected before the customer gets one email (default 300; 0 sends each at once). |
| `lp_missing_payment_state` | `$state, $order` | Where the customer's money stands: `unpaid`, `reserved`, `captured` or `paid`. |
| `lp_missing_email_lines` | `$lines, $order, $awaiting_only` | Lines listed in customer emails. |
| `lp_missing_next_reminder_time` | `$time, $from` | When the next reminder goes out (default: pushed into the reminder hours). |
| `lp_missing_variant_suggestions` | `$products, $item, $order, $qty` | "Other sizes/variants" suggestions. |
| `lp_missing_note_presets` | `$presets` | One-click customer messages in the order screen box. |
| `lp_missing_portal_base_url` | `$url` | Base URL of customer links. |
| `lp_missing_portal_rate_limit` | `array( 'max', 'window' )` | Portal save rate limit. |
| `lp_missing_portal_session_ttl` | `$seconds` | Lifetime of the portal session cookie. |
| `lp_missing_portal_page_has_shortcode` | `$has, $page_id` | Whether the portal page is considered set up. |
| `lp_missing_portal_notice_screens` | `$screen_ids` | Screens that show the portal-page notice. |
| `lp_missing_enable_stock_log` | `$enabled` | Whether stock locks are used. |
| `lp_missing_use_action_scheduler` | `$use` | Use Action Scheduler (default) or WP-Cron. |
| `lp_missing_daily_cleanup_limit` | `$limit` | Orders cleaned per daily run. |
| `lp_missing_resync_limit` | `$limit` | Orders re-scheduled per run after a reactivation. |

## Uninstall

Deleting the plugin removes its settings, secrets, transients, email settings and scheduled actions. Order data and the portal page are kept unless `define( 'LP_MISSING_REMOVE_ALL_DATA', true );` is set in `wp-config.php`.

## Development

See [tests/README.md](tests/README.md) for the integration and browser tests (real WordPress + WooCommerce on SQLite).
