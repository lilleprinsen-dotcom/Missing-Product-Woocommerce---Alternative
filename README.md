# Missing Product WooCommerce Alternative

Handles order lines that turn out to be missing when an order is picked: staff mark the line, suggest up to three alternatives, the customer chooses in a secure portal (alternative, removal or "no thanks"), and staff apply the decision to the order with correct totals, VAT, refunds, surcharges and stock.

Requires WordPress 6.5+, WooCommerce (HPOS and legacy order storage are both supported) and PHP 7.4+.

## Installation

Upload the whole plugin folder (it contains `missing-product-woocommerce-alternative.php`, `includes/`, `templates/`, `assets/`) to `wp-content/plugins/` and activate it. On activation a "Velg erstatning" page with the `[lp_missing_items]` portal is created if no portal page is configured.

Settings: **WooCommerce → Missing Items Settings**. Email texts: **WooCommerce → Settings → Emails**.

## How it works

1. **Mark missing** – in the order's "Missing / Problem Items" box: tick the line, set the missing quantity (0 = whole line), write a note for the customer and pick alternatives (WooCommerce product search). Saving the order emails the customer a link to the portal.
2. **Customer chooses** – the portal shows each missing line with the alternatives, prices and the price difference (frozen when the customer chooses). The customer can change the choice until staff apply it.
3. **Staff apply** – from the box: replace the missing quantity, add the alternative as an extra line, remove the quantity from the order, or record a refund. A more expensive alternative creates a separate surcharge order (with VAT) unless the store covers the difference.
4. **Reminders, deadline and cleanup** – reminders (one per order, within the configured hours), escalation to staff, an optional automatic action when the customer does not answer by the deadline, and automatic cleanup of resolved data. Scheduling uses WooCommerce's Action Scheduler (WooCommerce → Status → Scheduled Actions, group `lp-missing`).

### Money and VAT

- The alternative line takes over exactly the original line's share (net, per-rate VAT, discounts), so the order total does not change; the price difference is handled separately (surcharge order or covered by the store).
- Prices are compared including VAT for the order's own tax address, before coupon discounts. Alternatives in another tax class or tax status keep the paid gross amount, re-split at their own rate.
- Refunds are recorded on the order line (quantity, net and VAT). Money is paid back through the payment provider manually.

### Stock

- While a case is open, the missing quantity is locked (stock reduced by that amount) if "Lock stock for missing quantities" is on. The lock is released when the case is resolved, cleared, the line is deleted, or the order is cancelled, refunded, failed or deleted.
- Units confirmed missing never come back into stock: when a line is reduced, only its stock record follows the new quantity, so WooCommerce's own stock sync does not restock them later. Alternatives reduce stock like any added line (for orders whose stock is already reduced; unpaid orders reduce stock at payment).

## Extending

Templates can be overridden in `yourtheme/lp-missing/` (see `templates/`). See [Hooks](#hooks) for actions and filters.

## Hooks

See the list below; every hook is prefixed `lp_missing_`.

| Hook | Type | Arguments | When |
|---|---|---|---|
| `lp_missing_item_updated` | action | `$order, $item_id, $new_data, $old_data` | A line's missing-item data changed (used internally for notifications and reminders). |
| `lp_missing_settings_fields` | filter | `$fields` | Settings schema (add or change settings). |
| `lp_missing_settings_saved` | action | `$settings` | After the settings page was saved. |
| `lp_missing_upgrade_steps` | filter | `$steps` | Versioned one-time upgrade steps. |
| `lp_missing_store_covers_difference` | filter | `$covers, $gross_delta` | Whether the store absorbs a price difference. |
| `lp_missing_portal_base_url` | filter | `$url` | Base URL of customer links. |
| `lp_missing_enable_stock_log` | filter | `$enabled` | Whether stock locks are used. |
| `lp_missing_daily_cleanup_limit` | filter | `$limit` | Orders cleaned per daily run. |

## Uninstall

Deleting the plugin removes its settings, secrets, transients, email settings and scheduled actions. Order data and the portal page are kept unless `define( 'LP_MISSING_REMOVE_ALL_DATA', true );` is set in `wp-config.php`.

## Development

See [tests/README.md](tests/README.md) for the integration and browser tests (real WordPress + WooCommerce on SQLite).
