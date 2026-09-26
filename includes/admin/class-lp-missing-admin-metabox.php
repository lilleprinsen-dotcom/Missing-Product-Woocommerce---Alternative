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
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_metabox' ), 60, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
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
        $show_stock = 'yes' === LP_Missing_Settings::get_settings()['show_stock_preview'];

        echo '<div class="lp-missing-metabox">';
        foreach ( $items as $item_id => $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            $product = $item->get_product();
            $product_name = $product ? $product->get_name() : $item->get_name();
            echo '<div class="lp-missing-item" style="border-bottom:1px solid #ddd;padding:10px 0;">';
            echo '<strong>' . esc_html( $product_name ) . '</strong> (' . sprintf( __( 'Qty: %s', 'lp-missing' ), esc_html( $item->get_quantity() ) ) . ')';
            echo '<div style="margin-top:8px;">';
            echo '<label><input type="checkbox" name="lp_missing_items[' . absint( $item_id ) . '][missing]" value="1" ' . checked( true, $data['missing'], false ) . ' /> ' . esc_html__( 'Mark as missing', 'lp-missing' ) . '</label> ';
            echo '<label style="margin-left:12px;"><input type="checkbox" name="lp_missing_items[' . absint( $item_id ) . '][propose_delete]" value="1" ' . checked( true, $data['propose_delete'], false ) . ' /> ' . esc_html__( 'Propose deleting this item instead', 'lp-missing' ) . '</label>';
            echo '</div>';

            echo '<div style="margin-top:8px;">';
            echo '<label>' . esc_html__( 'Quantity missing', 'lp-missing' ) . ': <input type="number" min="0" max="' . esc_attr( LP_Missing_Line::get_item_available_qty( $item ) ) . '" name="lp_missing_items[' . absint( $item_id ) . '][qty_missing]" value="' . esc_attr( $data['qty_missing'] ) . '" style="width:80px;" /></label>';
            echo ' <span class="description">' . esc_html__( 'Leave at 0 to use the full line quantity.', 'lp-missing' ) . '</span>';
            echo '</div>';

            echo '<div style="margin-top:8px;">';
            echo '<label>' . esc_html__( 'Notes (customer visible)', 'lp-missing' ) . '<br /><textarea name="lp_missing_items[' . absint( $item_id ) . '][notes]" rows="2" style="width:100%;">' . esc_textarea( $data['notes'] ) . '</textarea></label>';
            echo '</div>';

            echo '<div style="margin-top:8px;">';
            echo '<label>' . esc_html__( 'Internal notes (staff only)', 'lp-missing' ) . '<br /><textarea name="lp_missing_items[' . absint( $item_id ) . '][internal_notes]" rows="2" style="width:100%;">' . esc_textarea( $data['internal_notes'] ) . '</textarea></label>';
            echo '</div>';

            // WooCommerce's own product search (selectWoo) so staff pick from a result list instead of auto-added first hits.
            echo '<div style="margin-top:8px;" class="lp-missing-alternatives" data-item-id="' . absint( $item_id ) . '">';
            echo '<label for="lp-missing-alt-' . absint( $item_id ) . '">' . esc_html( sprintf( __( 'Suggested alternatives (up to %d)', 'lp-missing' ), LP_Missing_Plugin::MAX_ALTERNATIVES ) ) . '</label><br />';
            echo '<select id="lp-missing-alt-' . absint( $item_id ) . '" class="wc-product-search lp-alt-select" multiple="multiple" style="width:100%;" name="lp_missing_items[' . absint( $item_id ) . '][alternatives][]"';
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
            foreach ( $data['alternatives'] as $alt_id ) {
                $alt_product = LP_Missing_Util::get_cached_product( $alt_id, $product_cache );
                if ( $alt_product ) {
                    echo '<option value="' . absint( $alt_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $alt_product->get_formatted_name() ) ) . '</option>';
                }
            }
            echo '</select>';
            if ( ! empty( $data['alternatives'] ) ) {
                echo '<ul class="lp-alt-list" style="margin:8px 0 0; padding-left:18px;">';
                foreach ( $data['alternatives'] as $alt_id ) {
                    $alt_product = LP_Missing_Util::get_cached_product( $alt_id, $product_cache );
                    if ( $alt_product ) {
                        echo '<li style="margin-bottom:6px;">' . self::get_alternative_preview_html( $alt_product, $show_stock ) . '</li>';
                    }
                }
                echo '</ul>';
            }
            echo '</div>';
            self::render_admin_line_status( $order, $item_id, $item, $data, $product_cache );
            echo '</div>';
        }
        if ( LP_Missing_Orders::order_has_missing_items( $order ) ) {
            $send_url = wp_nonce_url( add_query_arg( array( 'action' => 'lp_missing_send_email', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'lp_missing_send_email_' . $order->get_id() );
            echo '<div class="lp-missing-admin-actions" style="margin-top:12px;">';
            echo '<a class="button" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Send customer portal email', 'lp-missing' ) . '</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    public static function render_admin_line_status( $order, $item_id, $item, $data, &$product_cache ) {
        echo '<div class="lp-missing-status" style="margin-top:8px;padding:10px;background:#f8f8f8;border:1px solid #e2e2e2;">';
        if ( empty( $data['missing'] ) ) {
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

        // Plain nonce links instead of forms: this box is rendered inside the order edit <form>, and nested forms are dropped by browsers.
        if ( 'alt_pending' === $data['status'] && $selected_alt ) {
            echo '<p style="margin:8px 0 4px 0;">' . esc_html__( 'Apply this alternative to the order:', 'lp-missing' ) . '</p>';
            self::render_apply_link( $order, $item_id, 'alternative', 'replace', __( 'Replace missing quantity on this line', 'lp-missing' ), true );
            self::render_apply_link( $order, $item_id, 'alternative', 'add', __( 'Add as an extra line item', 'lp-missing' ) );
        } elseif ( LP_Missing_Line::can_apply_deletion( $data ) ) {
            $intro = 'delete_pending' === $data['status'] ? __( 'Customer approved deletion of this line.', 'lp-missing' ) : __( 'Remove the missing quantity without a customer choice:', 'lp-missing' );
            echo '<p style="margin:8px 0 4px 0;">' . esc_html( $intro ) . '</p>';
            self::render_apply_link( $order, $item_id, 'delete', 'reduce', __( 'Remove the missing quantity from the order totals', 'lp-missing' ), 'delete_pending' === $data['status'] );
            self::render_apply_link( $order, $item_id, 'delete', 'refund', __( 'Record a refund for the missing quantity (pay it back manually via the payment provider)', 'lp-missing' ) );
        }

        echo '</div>';
    }

    public static function get_apply_url( $order, $item_id, $type, $mode ) {
        $url = add_query_arg(
            array(
                'action'     => 'lp_missing_apply_decision',
                'order_id'   => $order->get_id(),
                'item_id'    => absint( $item_id ),
                'apply_type' => $type,
                'apply_mode' => $mode,
            ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, 'lp_missing_apply_' . $order->get_id() . '_' . absint( $item_id ) );
    }

    public static function render_apply_link( $order, $item_id, $type, $mode, $label, $primary = false ) {
        echo '<a class="button' . ( $primary ? ' button-primary' : '' ) . ' lp-missing-confirm" style="margin:4px 4px 0 0;" href="' . esc_url( self::get_apply_url( $order, $item_id, $type, $mode ) ) . '" data-confirm="' . esc_attr__( 'This changes the order now. Continue?', 'lp-missing' ) . '">' . esc_html( $label ) . '</a>';
    }

    public static function get_alternative_preview_html( $product, $show_stock ) {
        $html  = '';
        $thumb = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
        if ( $thumb ) {
            $html .= '<img src="' . esc_url( $thumb ) . '" alt="" style="width:20px;height:20px;object-fit:cover;margin-right:4px;vertical-align:middle;" />';
        }
        $parts = array( $product->get_name() );
        if ( $product->get_sku() ) {
            $parts[] = sprintf( __( 'SKU: %s', 'lp-missing' ), $product->get_sku() );
        }
        if ( $show_stock ) {
            $stock = $product->is_in_stock() ? __( 'In stock', 'lp-missing' ) : __( 'Out of stock', 'lp-missing' );
            if ( $product->managing_stock() ) {
                $stock .= ' (' . wc_stock_amount( $product->get_stock_quantity() ) . ')';
            }
            $parts[] = $stock;
        }
        return $html . '<span style="font-size:12px;color:#555;">' . esc_html( implode( ' | ', $parts ) ) . '</span>';
    }

    public static function enqueue_admin_scripts( $hook ) {
        if ( ! LP_Missing_Util::is_order_screen() ) {
            return;
        }

        // WooCommerce registers the selectWoo product search used by the alternatives field.
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_register_script( 'lp-missing-admin', false, array( 'jquery' ), '1.1.0', true );
        $inline = <<<'JS'
(function($){
    $(document.body).on('select2:selecting', '.lp-alt-select', function(e){
        var max = parseInt($(this).data('max'), 10) || 3;
        if (($(this).val() || []).length >= max) { e.preventDefault(); }
    });
    $(document).on('click', '.lp-missing-confirm', function(){
        return window.confirm($(this).data('confirm'));
    });
})(jQuery);
JS;
        wp_add_inline_script( 'lp-missing-admin', $inline );
        wp_enqueue_script( 'lp-missing-admin' );
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

        $items = $order->get_items( 'line_item' );
        foreach ( $items as $item_id => $item ) {
            // Lines added after the page was rendered have no fields in this request; leave them untouched.
            if ( ! isset( $_POST['lp_missing_items'][ $item_id ] ) || ! is_array( $_POST['lp_missing_items'][ $item_id ] ) ) {
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
