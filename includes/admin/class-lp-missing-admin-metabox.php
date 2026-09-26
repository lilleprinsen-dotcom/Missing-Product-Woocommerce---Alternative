<?php
/**
 * The "Missing / Problem Items" box on the order screen.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Admin_Metabox {
    public static function register() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
        // Fired by WooCommerce for both legacy (post) and HPOS order edit screens, after its own item/data saves.
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'protect_stale_lines' ), 5, 1 );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_metabox' ), 60, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_stale_notice' ) );
    }

    public static function add_metabox() {
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
        add_meta_box(
            'lp_missing_metabox',
            __( 'Missing / Problem Items', 'lp-missing' ),
            array( __CLASS__, 'render_metabox' ),
            $screen ? $screen : 'shop_order',
            'normal',
            'high'
        );
    }

    public static function render_metabox( $post_or_order ) {
        // Legacy screens pass a WP_Post, HPOS screens pass the WC_Order itself.
        $order = LP_Missing_Util::resolve_order( $post_or_order );
        if ( ! $order ) {
            return;
        }

        wp_nonce_field( 'lp_missing_metabox', 'lp_missing_nonce' );
        $items = $order->get_items( 'line_item' );
        $product_cache = array();
        $alt_ids = array();
        foreach ( $items as $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! empty( $data['alternatives'] ) ) {
                $alt_ids = array_merge( $alt_ids, $data['alternatives'] );
            }
            if ( ! empty( $data['selected_alt_id'] ) ) {
                $alt_ids[] = absint( $data['selected_alt_id'] );
            }
        }
        if ( $alt_ids ) {
            $products = wc_get_products( array( 'include' => array_values( array_unique( $alt_ids ) ), 'limit' => -1, 'type' => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ) ) );
            foreach ( $products as $product_obj ) {
                $product_cache[ $product_obj->get_id() ] = $product_obj;
            }
        }
        $show_stock    = 'yes' === LP_Missing_Settings::get( 'show_stock_preview' );
        $next_reminder = LP_Missing_Lifecycle::get_next_reminder_timestamp( $order );
        $has_case      = false;

        echo '<div class="lp-missing-metabox" data-order-id="' . absint( $order->get_id() ) . '" data-nonce="' . esc_attr( LP_Missing_Admin_Alternatives::create_nonce( $order->get_id() ) ) . '">';
        foreach ( $items as $item_id => $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            $has_case = $has_case || ! empty( $data['missing'] ) || $item->meta_exists( LP_Missing_Plugin::META_KEY );
            $product = $item->get_product();
            $product_name = $product ? $product->get_name() : $item->get_name();
            $field = 'lp_missing_items[' . absint( $item_id ) . ']';
            echo '<div class="lp-missing-item" data-item-id="' . absint( $item_id ) . '">';
            /* translators: %s: quantity */
            echo '<strong>' . esc_html( $product_name ) . '</strong> (' . esc_html( sprintf( __( 'Qty: %s', 'lp-missing' ), $item->get_quantity() ) ) . ')';
            echo '<div class="lp-missing-field">';
            // What this screen was built from: a save from a stale screen must not overwrite a line changed meanwhile.
            echo '<input type="hidden" name="' . esc_attr( $field . '[rev]' ) . '" value="' . esc_attr( LP_Missing_Line::get_revision( $item ) ) . '" />';
            echo '<label class="lp-missing-inline"><input type="checkbox" name="' . esc_attr( $field . '[missing]' ) . '" value="1" ' . checked( true, $data['missing'], false ) . ' /> ' . esc_html__( 'Mark as missing', 'lp-missing' ) . '</label> ';
            echo '<label class="lp-missing-inline"><input type="checkbox" name="' . esc_attr( $field . '[propose_delete]' ) . '" value="1" ' . checked( true, $data['propose_delete'], false ) . ' /> ' . esc_html__( 'Propose deleting this item instead', 'lp-missing' ) . '</label>';
            echo '</div>';

            echo '<div class="lp-missing-field">';
            echo '<label>' . esc_html__( 'Quantity missing', 'lp-missing' ) . ': <input type="number" class="lp-missing-qty" min="0" max="' . esc_attr( LP_Missing_Line::get_item_available_qty( $item ) ) . '" name="' . esc_attr( $field . '[qty_missing]' ) . '" value="' . esc_attr( $data['qty_missing'] ) . '" /></label>';
            echo ' <span class="description">' . esc_html__( 'Leave at 0 to use the full line quantity.', 'lp-missing' ) . '</span>';
            echo '</div>';

            echo '<div class="lp-missing-field">';
            echo '<label>' . esc_html__( 'Notes (customer visible)', 'lp-missing' ) . '<br /><textarea class="lp-missing-textarea" name="' . esc_attr( $field . '[notes]' ) . '" rows="2">' . esc_textarea( $data['notes'] ) . '</textarea></label>';
            echo '</div>';

            echo '<div class="lp-missing-field">';
            echo '<label>' . esc_html__( 'Internal notes (staff only)', 'lp-missing' ) . '<br /><textarea class="lp-missing-textarea" name="' . esc_attr( $field . '[internal_notes]' ) . '" rows="2">' . esc_textarea( $data['internal_notes'] ) . '</textarea></label>';
            echo '</div>';

            self::render_alternatives_field( $order, $item_id, $item, $data, $product, $product_cache, $show_stock );
            self::render_admin_line_status( $order, $item_id, $item, $data, $product_cache, $next_reminder );
            echo '</div>';
        }
        if ( $has_case ) {
            self::render_order_actions( $order );
        }
        echo '</div>';
    }

    /**
     * Alternatives select (WooCommerce's product search, so staff pick from a result list instead of auto-added
     * first hits), the "other variant" button and the price/stock preview of the chosen alternatives.
     */
    public static function render_alternatives_field( $order, $item_id, $item, $data, $product, &$product_cache, $show_stock ) {
        $item_id = absint( $item_id );
        echo '<div class="lp-missing-field lp-missing-alternatives" data-item-id="' . $item_id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        /* translators: %d: maximum number of alternatives */
        echo '<label for="lp-missing-alt-' . $item_id . '">' . esc_html( sprintf( __( 'Suggested alternatives (up to %d)', 'lp-missing' ), LP_Missing_Plugin::MAX_ALTERNATIVES ) ) . '</label><br />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        echo '<select id="lp-missing-alt-' . $item_id . '" class="wc-product-search lp-alt-select" multiple="multiple" name="lp_missing_items[' . $item_id . '][alternatives][]"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        echo ' data-placeholder="' . esc_attr__( 'Search by SKU or title', 'lp-missing' ) . '"';
        echo ' data-action="woocommerce_json_search_products_and_variations"';
        echo ' data-max="' . esc_attr( LP_Missing_Plugin::MAX_ALTERNATIVES ) . '"';
        if ( $product ) {
            echo ' data-exclude="' . esc_attr( $product->get_id() ) . '"';
        }
        if ( $show_stock ) {
            echo ' data-display_stock="true"';
        }
        echo '>';
        $alternatives = array();
        foreach ( $data['alternatives'] as $alt_id ) {
            $alt_product = LP_Missing_Util::get_cached_product( $alt_id, $product_cache );
            if ( $alt_product ) {
                $alternatives[] = $alt_product;
                echo '<option value="' . absint( $alt_id ) . '" selected="selected">' . esc_html( LP_Missing_Admin_Alternatives::get_option_label( $alt_product ) ) . '</option>';
            }
        }
        echo '</select>';

        if ( LP_Missing_Admin_Alternatives::line_has_variants( $item ) ) {
            echo '<p class="lp-missing-variants-row">';
            echo '<button type="button" class="button lp-missing-variants" data-item-id="' . $item_id . '">' . esc_html__( 'Same product, other variant', 'lp-missing' ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
            echo '<span class="lp-missing-variants-status description" aria-live="polite"></span>';
            echo '</p>';
        }

        // Always rendered (also empty) so the script can fill it when alternatives are picked.
        $qty = LP_Missing_Admin_Alternatives::get_preview_qty( $item, $data );
        echo '<ul class="lp-alt-list" aria-live="polite">' . LP_Missing_Admin_Alternatives::render_preview_items( $order, $item, $alternatives, $qty, $show_stock ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
        echo '</div>';
    }

    /**
     * Order-level tools below the lines: portal email and the customer link (copy, preview, revoke).
     */
    public static function render_order_actions( $order ) {
        $order_id = $order->get_id();
        echo '<div class="lp-missing-admin-actions">';
        if ( LP_Missing_Orders::order_has_missing_items( $order ) ) {
            $send_url = wp_nonce_url( add_query_arg( array( 'action' => 'lp_missing_send_email', 'order_id' => $order_id ), admin_url( 'admin-post.php' ) ), 'lp_missing_send_email_' . $order_id );
            echo '<a class="button lp-missing-send-email" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Send customer portal email', 'lp-missing' ) . '</a>';
        }

        $link = LP_Missing_Magic_Link::get_magic_link_for_order( $order );
        if ( $link ) {
            echo '<button type="button" class="button lp-missing-copy-link" data-link="' . esc_attr( $link ) . '">' . esc_html__( 'Copy customer link', 'lp-missing' ) . '</button>';
        }
        $preview = LP_Missing_Magic_Link::get_staff_preview_url( $order );
        if ( $preview ) {
            echo '<a class="button lp-missing-preview" href="' . esc_url( $preview ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View as customer', 'lp-missing' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'lp-missing' ) . '</span></a>';
        }
        echo '<a class="button lp-missing-revoke lp-missing-confirm" href="' . esc_url( self::get_revoke_url( $order ) ) . '" data-confirm="' . esc_attr__( 'Revoke all customer links for this order? Links in emails already sent stop working; send a new email to give the customer a working link.', 'lp-missing' ) . '">' . esc_html__( 'Revoke customer links', 'lp-missing' ) . '</a>';
        echo '<span class="lp-missing-copy-status" aria-live="polite"></span>';
        if ( $link ) {
            // Shown by the script when the clipboard cannot be used, so the link can be copied by hand.
            echo '<input type="text" class="lp-missing-link-field large-text" readonly="readonly" hidden="hidden" aria-label="' . esc_attr__( 'Customer link', 'lp-missing' ) . '" value="' . esc_attr( $link ) . '" />';
        }
        echo '</div>';
    }

    public static function get_revoke_url( $order ) {
        $order_id = $order instanceof WC_Order ? $order->get_id() : absint( $order );
        return wp_nonce_url( add_query_arg( array( 'action' => 'lp_missing_revoke_links', 'order_id' => $order_id ), admin_url( 'admin-post.php' ) ), 'lp_missing_revoke_links_' . $order_id );
    }

    public static function render_admin_line_status( $order, $item_id, $item, $data, &$product_cache, $next_reminder = null ) {
        echo '<div class="lp-missing-status">';
        // Applied lines are no longer "missing" but keep their status and history until the cleanup.
        if ( empty( $data['missing'] ) && ! self::is_applied( $data ) ) {
            echo '<strong>' . esc_html__( 'Status:', 'lp-missing' ) . '</strong> ' . esc_html__( 'Not marked as missing.', 'lp-missing' );
            echo '</div>';
            return;
        }

        $status_label = __( 'Awaiting customer choice.', 'lp-missing' );
        $decision_text = '';
        $selected_alt = ! empty( $data['selected_alt_id'] ) ? LP_Missing_Util::get_cached_product( $data['selected_alt_id'], $product_cache ) : null;

        if ( LP_Missing_Line::is_line_resolved( $data ) ) {
            if ( 'alt_applied' === $data['status'] ) {
                $status_label = __( 'Applied: alternative added.', 'lp-missing' );
            } elseif ( 'delete_applied' === $data['status'] ) {
                $status_label = __( 'Applied: line removed/refunded.', 'lp-missing' );
            } else {
                $status_label = __( 'Applied.', 'lp-missing' );
            }
        } elseif ( 'alt_pending' === $data['status'] && $selected_alt ) {
            $status_label = __( 'Customer chose an alternative (pending staff).', 'lp-missing' );
            $decision_text = sprintf(
                /* translators: 1: product name, 2: quantity */
                __( 'Alternative: %1$s (Qty %2$d)', 'lp-missing' ),
                $selected_alt->get_name(),
                $data['qty_alt'] ? absint( $data['qty_alt'] ) : absint( $data['qty_missing'] )
            );
        } elseif ( 'delete_pending' === $data['status'] ) {
            $status_label = __( 'Customer approved deletion (pending staff).', 'lp-missing' );
            /* translators: %s: item name */
            $decision_text = sprintf( __( 'Agreed to delete %s.', 'lp-missing' ), $item->get_name() );
        } elseif ( 'declined' === $data['status'] ) {
            $status_label = __( 'Customer declined the listed alternatives. Suggest new alternatives (the customer is notified again) or remove/refund the missing quantity.', 'lp-missing' );
        } elseif ( $data['needs_attention'] ) {
            $status_label = __( 'Needs manual attention.', 'lp-missing' );
        }

        echo '<strong>' . esc_html__( 'Status:', 'lp-missing' ) . '</strong> ' . esc_html( $status_label );
        if ( $decision_text ) {
            echo '<br /><span>' . esc_html( $decision_text ) . '</span>';
        }

        if ( null === $next_reminder ) {
            $next_reminder = LP_Missing_Lifecycle::get_next_reminder_timestamp( $order );
        }
        $history = self::get_history_parts( $data, $next_reminder );
        if ( $history ) {
            echo '<p class="lp-missing-history">' . esc_html( implode( ' · ', $history ) ) . '</p>';
        }

        // Plain nonce links instead of forms: this box is rendered inside the order edit <form>, and nested forms are dropped by browsers.
        if ( 'alt_pending' === $data['status'] && $selected_alt ) {
            echo '<p class="lp-missing-status__intro">' . esc_html__( 'Apply this alternative to the order:', 'lp-missing' ) . '</p>';
            self::render_apply_link( $order, $item_id, 'alternative', 'replace', __( 'Replace missing quantity on this line', 'lp-missing' ), true );
            self::render_apply_link( $order, $item_id, 'alternative', 'add', __( 'Add as an extra line item', 'lp-missing' ) );
        } elseif ( LP_Missing_Line::can_apply_deletion( $data ) ) {
            $intro = 'delete_pending' === $data['status'] ? __( 'Customer approved deletion of this line.', 'lp-missing' ) : __( 'Remove the missing quantity without a customer choice:', 'lp-missing' );
            echo '<p class="lp-missing-status__intro">' . esc_html( $intro ) . '</p>';
            self::render_apply_link( $order, $item_id, 'delete', 'reduce', __( 'Remove the missing quantity from the order totals', 'lp-missing' ), 'delete_pending' === $data['status'] );
            self::render_apply_link( $order, $item_id, 'delete', 'refund', __( 'Record a refund for the missing quantity (pay it back manually via the payment provider)', 'lp-missing' ) );
        }

        echo '</div>';
    }

    /**
     * Notification history of a missing line in short parts, e.g. "Notified 24.09", "2/3 reminders",
     * "next 27.09 09:00", "customer chose 25.09", "deadline 29.09 12:00" (site time zone).
     *
     * @param array $data          Line data.
     * @param int   $next_reminder Next reminder for the order (LP_Missing_Lifecycle::get_next_reminder_timestamp()).
     * @return string[]
     */
    public static function get_history_parts( $data, $next_reminder = 0 ) {
        if ( empty( $data['missing'] ) && ! self::is_applied( $data ) ) {
            return array();
        }
        $parts    = array();
        $awaiting = LP_Missing_Line::is_awaiting_customer( $data );

        /* translators: %s: date */
        $parts[] = $data['notified_at'] ? sprintf( __( 'Notified %s', 'lp-missing' ), self::format_short_date( $data['notified_at'] ) ) : __( 'No notification recorded', 'lp-missing' );
        /* translators: 1: reminders sent, 2: maximum number of reminders */
        $parts[] = sprintf( __( '%1$d/%2$d reminders', 'lp-missing' ), $data['reminder_count'], LP_Missing_Settings::get( 'reminder_max_count' ) );
        if ( $awaiting && $next_reminder ) {
            /* translators: %s: date and time */
            $parts[] = sprintf( __( 'next %s', 'lp-missing' ), self::format_short_date( $next_reminder, true ) );
        }

        $decided = $data['decision_made_at'] ? self::format_short_date( $data['decision_made_at'] ) : '';
        if ( in_array( $data['status'], array( 'alt_pending', 'delete_pending' ), true ) ) {
            /* translators: %s: date */
            $parts[] = $decided ? sprintf( __( 'customer chose %s', 'lp-missing' ), $decided ) : __( 'customer chose', 'lp-missing' );
        } elseif ( 'declined' === $data['status'] ) {
            /* translators: %s: date */
            $parts[] = $decided ? sprintf( __( 'customer declined %s', 'lp-missing' ), $decided ) : __( 'customer declined', 'lp-missing' );
        } elseif ( self::is_applied( $data ) && $data['resolved_at'] ) {
            /* translators: %s: date */
            $parts[] = sprintf( __( 'applied %s', 'lp-missing' ), self::format_short_date( $data['resolved_at'] ) );
        }

        if ( $awaiting && $data['needs_attention'] ) {
            $parts[] = __( 'escalated to staff', 'lp-missing' );
        }

        $deadline = LP_Missing_Deadline::get_for_line( $data );
        if ( $deadline ) {
            /* translators: %s: date and time */
            $parts[] = sprintf( __( 'deadline %s', 'lp-missing' ), self::format_short_date( $deadline, true ) );
        }
        return $parts;
    }

    /**
     * Whether a customer decision (or a staff removal) was applied to the line.
     */
    public static function is_applied( $data ) {
        return in_array( $data['status'], array( 'alt_applied', 'delete_applied' ), true );
    }

    /**
     * "24.09" / "27.09 09:00" in the site time zone; the year is added when it is not the current one.
     */
    public static function format_short_date( $timestamp, $with_time = false ) {
        $format = wp_date( 'Y', $timestamp ) === wp_date( 'Y' ) ? 'd.m' : 'd.m.Y';
        return wp_date( $with_time ? $format . ' H:i' : $format, $timestamp );
    }

    public static function get_apply_url( $order, $item_id, $type, $mode ) {
        $item     = $order->get_item( $item_id, false );
        $decision = $item ? LP_Missing_Line::get_decision_key( LP_Missing_Line::get_item_data( $item ) ) : '';
        $url      = add_query_arg(
            array(
                'action'     => 'lp_missing_apply_decision',
                'order_id'   => $order->get_id(),
                'item_id'    => absint( $item_id ),
                'apply_type' => $type,
                'apply_mode' => $mode,
                'decision'   => $decision,
            ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, LP_Missing_Admin_Actions::apply_nonce_action( $order->get_id(), $item_id, $decision ) );
    }

    public static function render_apply_link( $order, $item_id, $type, $mode, $label, $primary = false ) {
        echo '<a class="button' . ( $primary ? ' button-primary' : '' ) . ' lp-missing-apply lp-missing-confirm" href="' . esc_url( self::get_apply_url( $order, $item_id, $type, $mode ) ) . '" data-confirm="' . esc_attr__( 'This changes the order now. Continue?', 'lp-missing' ) . '">' . esc_html( $label ) . '</a>';
    }

    /**
     * Styles on the order screens and the legacy order list; the script only where the box is shown.
     */
    public static function enqueue_admin_scripts( $hook ) {
        $screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $order_page = LP_Missing_Util::is_order_screen();
        if ( ! $order_page && ! ( $screen && 'edit-shop_order' === $screen->id ) ) {
            return;
        }

        wp_enqueue_style( 'lp-missing-admin', plugins_url( 'assets/css/admin.css', LP_MISSING_FILE ), array(), LP_Missing_Plugin::VERSION );
        if ( ! $order_page ) {
            return;
        }

        // WooCommerce registers the selectWoo product search used by the alternatives field.
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_script( 'lp-missing-admin', plugins_url( 'assets/js/admin.js', LP_MISSING_FILE ), array( 'jquery' ), LP_Missing_Plugin::VERSION, true );
        wp_localize_script(
            'lp-missing-admin',
            'lpMissingAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => array(
                    'copied'     => __( 'Customer link copied.', 'lp-missing' ),
                    'copyManual' => __( 'Copy the link from the field (Ctrl+C / Cmd+C).', 'lp-missing' ),
                    'searching'  => __( 'Looking for variants…', 'lp-missing' ),
                    /* translators: %d: number of variants */
                    'added'      => __( 'Added %d variant(s). Click Update to save.', 'lp-missing' ),
                    'listFull'   => __( 'The list already has the maximum number of alternatives.', 'lp-missing' ),
                    'noVariants' => __( 'No other variant is in stock for the missing quantity.', 'lp-missing' ),
                    'error'      => __( 'Something went wrong. Reload the order and try again.', 'lp-missing' ),
                ),
            )
        );
    }

    public static function save_metabox( $order_id, $post_or_order = null ) {
        if ( ! isset( $_POST['lp_missing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_missing_nonce'] ) ), 'lp_missing_metabox' ) ) {
            return;
        }
        if ( ! LP_Missing_Util::current_user_can_edit_order( $order_id ) ) {
            return;
        }
        if ( empty( $_POST['lp_missing_items'] ) || ! is_array( $_POST['lp_missing_items'] ) ) {
            return;
        }

        // Load fresh: WooCommerce saved the order form (billing email etc.) through its own order instance.
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Same lock as applies, customer saves and the background jobs: never write lines while one of them runs.
        if ( ! LP_Missing_Admin_Actions::acquire_apply_lock( $order_id ) ) {
            self::add_stale_notice( array( __( 'all lines (the order was being updated at the same moment)', 'lp-missing' ) ) );
            return;
        }
        // One customer email listing every line marked in this save (also when called outside the order screen).
        LP_Missing_Lifecycle::begin_batch();
        try {
            self::save_lines( $order );
        } finally {
            LP_Missing_Admin_Actions::release_apply_lock( $order_id );
            LP_Missing_Lifecycle::end_batch();
        }
    }

    /**
     * Before WooCommerce saves the posted line items (priority 10): drop the posted item fields of lines the plugin
     * changed after this screen was rendered (e.g. the deadline job reduced the line), so a stale screen cannot put
     * back the old quantity and totals.
     */
    public static function protect_stale_lines( $order_id ) {
        if ( empty( $_POST['lp_missing_items'] ) || ! is_array( $_POST['lp_missing_items'] ) || ! isset( $_POST['lp_missing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_missing_nonce'] ) ), 'lp_missing_metabox' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        // Decided before WooCommerce applies this form's own item edits, which change quantities legitimately.
        $stale              = self::get_stale_line_ids( $order );
        self::$stale_lines = $stale;
        if ( ! $stale || empty( $_POST['order_item_id'] ) || ! is_array( $_POST['order_item_id'] ) ) {
            return;
        }
        $_POST['order_item_id'] = array_values( array_diff( array_map( 'absint', $_POST['order_item_id'] ), $stale ) );
    }

    /**
     * Lines whose case or quantities changed after the order screen was rendered.
     */
    protected static function get_stale_line_ids( $order ) {
        $stale = array();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! isset( $_POST['lp_missing_items'][ $item_id ]['rev'] ) ) {
                continue;
            }
            $posted = sanitize_text_field( wp_unslash( $_POST['lp_missing_items'][ $item_id ]['rev'] ) );
            if ( ! hash_equals( LP_Missing_Line::get_revision( $item ), $posted ) ) {
                $stale[] = $item_id;
            }
        }
        return $stale;
    }

    protected static function add_stale_notice( $names ) {
        $key     = 'lp_missing_stale_' . get_current_user_id();
        $current = get_transient( $key );
        $names   = array_unique( array_merge( is_array( $current ) ? $current : array(), $names ) );
        set_transient( $key, $names, 5 * MINUTE_IN_SECONDS );
    }

    public static function render_stale_notice() {
        if ( ! LP_Missing_Util::is_order_screen() ) {
            return;
        }
        $key   = 'lp_missing_stale_' . get_current_user_id();
        $names = get_transient( $key );
        if ( ! is_array( $names ) || ! $names ) {
            return;
        }
        delete_transient( $key );
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf( __( 'Missing items: these lines changed while the order was open, so your changes to them were not saved: %s. Check them and save again.', 'lp-missing' ), implode( ', ', $names ) ) ) . '</p></div>';
    }

    /** @var array|null Stale line IDs found before WooCommerce saved the posted items. */
    protected static $stale_lines = null;

    protected static function save_lines( $order ) {
        $stale = is_array( self::$stale_lines ) ? self::$stale_lines : self::get_stale_line_ids( $order );
        self::$stale_lines = null;
        $items = $order->get_items( 'line_item' );
        foreach ( $items as $item_id => $item ) {
            // Lines added after the page was rendered have no fields in this request; leave them untouched.
            if ( ! isset( $_POST['lp_missing_items'][ $item_id ] ) || ! is_array( $_POST['lp_missing_items'][ $item_id ] ) ) {
                continue;
            }
            if ( in_array( $item_id, $stale, true ) ) {
                self::add_stale_notice( array( $item->get_name() ) );
                continue;
            }
            $existing = LP_Missing_Line::get_item_data( $item );
            $posted   = $_POST['lp_missing_items'][ $item_id ];

            $missing = ! empty( $posted['missing'] );
            $was_missing = ! empty( $existing['missing'] );

            // Never write tracking data for lines that are not (and were not) missing.
            if ( ! $missing && ! $was_missing ) {
                continue;
            }

            $propose_delete = ! empty( $posted['propose_delete'] );
            $qty_missing = isset( $posted['qty_missing'] ) ? absint( $posted['qty_missing'] ) : 0;
            $notes = isset( $posted['notes'] ) ? wp_kses_post( wp_unslash( $posted['notes'] ) ) : '';
            $internal_notes = isset( $posted['internal_notes'] ) ? wp_kses_post( wp_unslash( $posted['internal_notes'] ) ) : '';
            $alt_ids = isset( $posted['alternatives'] ) ? LP_Missing_Util::sanitize_alt_ids( wp_unslash( $posted['alternatives'] ) ) : array();

            // A missing line always needs a usable quantity: default to (and cap at) the units not yet refunded.
            $billable_qty = max( 1, LP_Missing_Line::get_item_available_qty( $item ) );
            if ( $missing ) {
                $qty_missing = $qty_missing < 1 ? $billable_qty : min( $qty_missing, $billable_qty );
            }

            $was_open = $was_missing && ! LP_Missing_Line::is_line_resolved( $existing );

            $new_data = $existing;
            $new_data['missing'] = $missing;
            $new_data['propose_delete'] = $missing && $propose_delete;
            $new_data['qty_missing'] = $missing ? $qty_missing : 0;
            $new_data['notes'] = $missing ? $notes : '';
            $new_data['internal_notes'] = $missing ? $internal_notes : '';
            $new_data['alternatives'] = $missing ? $alt_ids : array();

            if ( $missing && ! $was_open ) {
                // A new (or re-opened) case starts from a clean lifecycle, whatever status an earlier case left behind.
                $new_data['status'] = 'pending';
                $new_data['selected_alt_id'] = 0;
                $new_data['qty_alt'] = 0;
                $new_data['pricing_snapshot'] = array();
                $new_data['decision_made_at'] = 0;
                $new_data['first_missing_at'] = time();
                $new_data['reminder_count'] = 0;
                $new_data['last_reminder_at'] = 0;
                $new_data['reminder_scheduled_for'] = 0;
                $new_data['needs_attention'] = false;
                $new_data['resolved_at'] = 0;
            } elseif ( $missing && 'declined' === $existing['status'] ) {
                // New suggestions after the customer declined put the line back in the customer's hands.
                $alternatives_changed = $alt_ids !== $existing['alternatives'];
                $delete_newly_offered = $new_data['propose_delete'] && empty( $existing['propose_delete'] );
                if ( $alternatives_changed || $delete_newly_offered ) {
                    $new_data['status'] = 'pending';
                    $new_data['decision_made_at'] = 0;
                    $new_data['needs_attention'] = false;
                    $new_data['first_missing_at'] = time();
                    $new_data['reminder_count'] = 0;
                    $new_data['last_reminder_at'] = 0;
                }
            }

            if ( ! $missing ) {
                $new_data['status'] = 'cleared';
                $new_data['selected_alt_id'] = 0;
                $new_data['qty_alt'] = 0;
                $new_data['pricing_snapshot'] = array();
                $new_data['first_missing_at'] = 0;
                $new_data['reminder_count'] = 0;
                $new_data['last_reminder_at'] = 0;
                $new_data['reminder_scheduled_for'] = 0;
                $new_data['needs_attention'] = false;
                $new_data['resolved_at'] = time();
            } elseif ( ! LP_Missing_Line::is_line_resolved( $new_data ) ) {
                $new_data['resolved_at'] = 0;
            }

            LP_Missing_Stock::maybe_adjust_stock( $item, $existing, $new_data, $order );

            if ( serialize( $existing ) === serialize( $new_data ) ) {
                continue;
            }

            $new_data['last_updated'] = time();
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new_data );
            $item->save();
            do_action( 'lp_missing_item_updated', $order, $item_id, $new_data, $existing );
        }

        LP_Missing_Orders::refresh_order_flags( $order );
    }
}
