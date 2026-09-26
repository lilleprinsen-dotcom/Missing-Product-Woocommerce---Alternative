<?php
/**
 * Alternatives on the order screen: price/stock preview per alternative and "same product, other variant"
 * suggestions, with their AJAX endpoints.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Admin_Alternatives {
    /** Nonce action prefix for the metabox AJAX calls (suffixed with the order ID). */
    const NONCE_ACTION = 'lp_missing_admin_ajax_';

    /** An alternative costing more than this share above the original unit price is highlighted. */
    const PRICEY_SHARE = 0.2;

    /** Upper bound for IDs a client may ask to exclude from the variant suggestions. */
    const MAX_EXCLUDE = 50;

    public static function register() {
        add_action( 'wp_ajax_lp_missing_preview_alternatives', array( __CLASS__, 'ajax_preview_alternatives' ) );
        add_action( 'wp_ajax_lp_missing_variant_suggestions', array( __CLASS__, 'ajax_variant_suggestions' ) );
    }

    public static function create_nonce( $order_id ) {
        return wp_create_nonce( self::NONCE_ACTION . absint( $order_id ) );
    }

    /**
     * Quantity the preview and the suggestions are for: the requested (form) value, else the line's missing
     * quantity, else every unit still available; never more than the available units.
     */
    public static function get_preview_qty( $item, $data, $requested = 0 ) {
        $available = max( 1, LP_Missing_Line::get_item_available_qty( $item ) );
        $qty       = absint( $requested ) ? absint( $requested ) : absint( $data['qty_missing'] );
        return $qty < 1 ? $available : min( $qty, $available );
    }

    /**
     * Price, difference and stock facts for showing one alternative against an order line.
     *
     * @return array unit_price (incl. VAT for the order), delta (LP_Missing_Pricing::get_alternative_price_delta()),
     *               percent (unit difference vs. the original unit price, null when the original was free),
     *               pricey, stock ('ok', 'low' or 'out') and frozen (difference agreed with the customer).
     */
    public static function get_preview_facts( $order, $item, $alt_product, $qty ) {
        $qty           = max( 1, absint( $qty ) );
        $prices        = LP_Missing_Pricing::get_product_unit_prices_for_order( $order, $alt_product );
        $delta         = LP_Missing_Pricing::get_alternative_price_delta( $order, $item, $alt_product, $qty );
        $original_unit = LP_Missing_Pricing::get_item_unit_price_incl_tax( $item );
        $percent       = $original_unit > 0 ? $delta['unit_delta_raw'] / $original_unit * 100 : null;
        $pricey        = 'up' === $delta['direction'] && ( null === $percent || $percent > self::PRICEY_SHARE * 100 );

        if ( ! $alt_product->is_in_stock() ) {
            $stock = 'out';
        } elseif ( ! $alt_product->has_enough_stock( $qty ) ) {
            $stock = 'low';
        } else {
            $stock = 'ok';
        }

        // Same test as LP_Missing_Pricing::get_frozen_pricing_snapshot(): a customer choice froze this alternative's price.
        $snapshot = LP_Missing_Line::get_item_data( $item )['pricing_snapshot'];
        $frozen   = ! empty( $snapshot['comparison_model'] ) && ! empty( $snapshot['selected_qty'] ) && isset( $snapshot['selected_alt_id'] ) && absint( $snapshot['selected_alt_id'] ) === $alt_product->get_id();

        return array(
            'unit_price' => $prices['incl'],
            'delta'      => $delta,
            'percent'    => $percent,
            'pricey'     => $pricey,
            'stock'      => $stock,
            'frozen'     => $frozen,
        );
    }

    /**
     * The <li> rows of the alternatives preview list.
     *
     * @param WC_Order              $order
     * @param WC_Order_Item_Product $item
     * @param array                 $alternatives Product IDs or WC_Product objects.
     * @param int                   $qty          Quantity the difference is shown for.
     * @param bool|null             $show_stock   Show stock quantities (defaults to the setting).
     */
    public static function render_preview_items( $order, $item, $alternatives, $qty, $show_stock = null ) {
        if ( null === $show_stock ) {
            $show_stock = 'yes' === LP_Missing_Settings::get( 'show_stock_preview' );
        }
        $html = '';
        foreach ( (array) $alternatives as $alternative ) {
            $product = $alternative instanceof WC_Product ? $alternative : wc_get_product( absint( $alternative ) );
            if ( $product ) {
                $html .= self::render_preview_item( $order, $item, $product, $qty, $show_stock );
            }
        }
        return $html;
    }

    public static function render_preview_item( $order, $item, $product, $qty, $show_stock ) {
        $facts   = self::get_preview_facts( $order, $item, $product, $qty );
        $classes = array( 'lp-alt-preview' );
        if ( 'ok' !== $facts['stock'] ) {
            $classes[] = 'lp-alt-preview--nostock';
        } elseif ( $facts['pricey'] ) {
            $classes[] = 'lp-alt-preview--pricey';
        }

        $html  = '<li class="' . esc_attr( implode( ' ', $classes ) ) . '" data-product-id="' . absint( $product->get_id() ) . '">';
        $thumb = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
        if ( $thumb ) {
            $html .= '<img class="lp-alt-preview__thumb" src="' . esc_url( $thumb ) . '" alt="" />';
        }
        $html .= '<span class="lp-alt-preview__name">' . esc_html( $product->get_name() ) . '</span>';

        $meta = array();
        if ( $product->get_sku() ) {
            /* translators: %s: SKU */
            $meta[] = sprintf( __( 'SKU: %s', 'lp-missing' ), $product->get_sku() );
        }
        if ( $show_stock ) {
            $stock = $product->is_in_stock() ? __( 'In stock', 'lp-missing' ) : __( 'Out of stock', 'lp-missing' );
            if ( $product->managing_stock() ) {
                $stock .= ' (' . wc_stock_amount( $product->get_stock_quantity() ) . ')';
            }
            $meta[] = $stock;
        }
        if ( $meta ) {
            $html .= ' <span class="lp-alt-preview__meta">' . esc_html( implode( ' | ', $meta ) ) . '</span>';
        }

        $price = LP_Missing_Util::plain_price( $facts['unit_price'], $order );
        /* translators: %s: unit price */
        $price_label = wc_tax_enabled() ? sprintf( __( '%s/unit incl. VAT', 'lp-missing' ), $price ) : sprintf( __( '%s/unit', 'lp-missing' ), $price );
        $html .= '<br /><span class="lp-alt-preview__price">' . esc_html( $price_label ) . '</span>';
        $html .= ' <span class="lp-alt-preview__delta lp-alt-preview__delta--' . esc_attr( $facts['delta']['direction'] ) . '">' . esc_html( self::describe_delta( $facts, $qty ) ) . '</span>';

        $warning = '';
        if ( 'out' === $facts['stock'] ) {
            $warning = __( 'Out of stock', 'lp-missing' );
        } elseif ( 'low' === $facts['stock'] ) {
            /* translators: %d: quantity */
            $warning = sprintf( __( 'Not enough stock for %d', 'lp-missing' ), $qty );
        } elseif ( $facts['pricey'] ) {
            /* translators: %d: percent */
            $warning = sprintf( __( 'More than %d%% dearer', 'lp-missing' ), self::PRICEY_SHARE * 100 );
        }
        if ( $warning ) {
            $html .= ' <span class="lp-alt-preview__warning">' . esc_html( $warning ) . '</span>';
        }
        return $html . '</li>';
    }

    /**
     * E.g. "+kr 100,00 for 2 (+50%)", "−kr 20,00 for 1 (−20%)" or "Same price".
     */
    public static function describe_delta( $facts, $qty ) {
        $delta = $facts['delta'];
        if ( 'same' === $delta['direction'] ) {
            $text = __( 'Same price', 'lp-missing' );
        } else {
            $sign = 'up' === $delta['direction'] ? '+' : '−';
            /* translators: 1: sign, 2: price difference for the quantity, 3: quantity */
            $text = sprintf( __( '%1$s%2$s for %3$d', 'lp-missing' ), $sign, $delta['total_delta'], $qty );
            if ( null !== $facts['percent'] ) {
                $text .= ' (' . $sign . number_format_i18n( abs( $facts['percent'] ) ) . '%)';
            }
        }
        if ( $facts['frozen'] ) {
            $text .= ' ' . __( '(agreed with the customer)', 'lp-missing' );
        }
        return $text;
    }

    /**
     * Label of a product as the WooCommerce product search shows it (used for new <option> elements).
     */
    public static function get_option_label( $product ) {
        return rawurldecode( wp_strip_all_tags( $product->get_formatted_name() ) );
    }

    /**
     * Whether the line's product is a variation (the "Same product, other variant" button is offered).
     */
    public static function line_has_variants( $item ) {
        $product = $item->get_product();
        return $product && $product->is_type( 'variation' ) && $product->get_parent_id();
    }

    /**
     * Sibling variations of the line's variation that can replace $qty units: published, purchasable, in stock with
     * enough stock. Sorted by closeness (differing attributes, then relative price difference), at most
     * MAX_ALTERNATIVES.
     *
     * @param WC_Order              $order
     * @param WC_Order_Item_Product $item
     * @param int                   $qty
     * @param int[]                 $exclude Product IDs to leave out (e.g. alternatives already chosen).
     * @return WC_Product[]
     */
    public static function get_variant_suggestions( $order, $item, $qty, $exclude = array() ) {
        if ( ! self::line_has_variants( $item ) ) {
            return array();
        }
        $variation = $item->get_product();
        $parent    = wc_get_product( $variation->get_parent_id() );
        if ( ! $parent || ! is_callable( array( $parent, 'get_visible_children' ) ) ) {
            return array();
        }

        $qty       = max( 1, absint( $qty ) );
        $exclude   = array_map( 'absint', (array) $exclude );
        $exclude[] = $variation->get_id();

        $original_attributes = self::get_line_attributes( $item, $variation );
        $original_price      = LP_Missing_Pricing::get_item_unit_price_incl_tax( $item );

        $candidates = array();
        foreach ( $parent->get_visible_children() as $child_id ) {
            $child_id = absint( $child_id );
            if ( in_array( $child_id, $exclude, true ) ) {
                continue;
            }
            $candidate = wc_get_product( $child_id );
            if ( ! self::can_cover( $candidate, $qty ) ) {
                continue;
            }
            $prices         = LP_Missing_Pricing::get_product_unit_prices_for_order( $order, $candidate );
            $price_distance = $original_price > 0 ? abs( $prices['incl'] - $original_price ) / $original_price : ( $prices['incl'] > 0 ? 1 : 0 );
            $candidates[]   = array(
                'product'        => $candidate,
                // Each differing attribute counts 1; the price difference (capped at 100%) breaks ties within that.
                'score'          => self::attribute_distance( $original_attributes, $candidate->get_attributes() ) + min( 1, $price_distance ),
                'price_distance' => $price_distance,
            );
        }

        usort(
            $candidates,
            function( $a, $b ) {
                if ( $a['score'] !== $b['score'] ) {
                    return $a['score'] < $b['score'] ? -1 : 1;
                }
                if ( $a['price_distance'] !== $b['price_distance'] ) {
                    return $a['price_distance'] < $b['price_distance'] ? -1 : 1;
                }
                return $a['product']->get_id() - $b['product']->get_id();
            }
        );

        /**
         * Filter the variant suggestions for a missing line (best match first; the first ones up to the
         * alternatives limit are offered). Entries may be WC_Product objects or product IDs.
         *
         * @param WC_Product[]          $suggestions Sibling variations that can cover the quantity, best first.
         * @param WC_Order_Item_Product $item        The missing order line.
         * @param WC_Order              $order       The order.
         * @param int                   $qty         Quantity to cover.
         */
        $suggestions = apply_filters( 'lp_missing_variant_suggestions', wp_list_pluck( $candidates, 'product' ), $item, $order, $qty );

        $result = array();
        foreach ( (array) $suggestions as $suggestion ) {
            $product = $suggestion instanceof WC_Product ? $suggestion : ( is_numeric( $suggestion ) ? wc_get_product( absint( $suggestion ) ) : null );
            if ( ! $product || in_array( $product->get_id(), $exclude, true ) || isset( $result[ $product->get_id() ] ) ) {
                continue;
            }
            $result[ $product->get_id() ] = $product;
            if ( count( $result ) >= LP_Missing_Plugin::MAX_ALTERNATIVES ) {
                break;
            }
        }
        return array_values( $result );
    }

    /**
     * Whether a product can replace $qty units right now: published, purchasable, in stock (not on backorder) and,
     * when stock is managed, with at least $qty units.
     */
    public static function can_cover( $product, $qty ) {
        if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
            return false;
        }
        if ( 'instock' !== $product->get_stock_status() ) {
            return false;
        }
        return ! $product->managing_stock() || (float) $product->get_stock_quantity() >= $qty;
    }

    /**
     * Attributes of the ordered variation (name => value). "Any" attributes take the value stored on the order line.
     */
    public static function get_line_attributes( $item, $variation ) {
        $attributes = array();
        foreach ( (array) $variation->get_attributes() as $name => $value ) {
            if ( '' === (string) $value ) {
                $value = (string) $item->get_meta( $name, true );
            }
            $attributes[ $name ] = (string) $value;
        }
        return $attributes;
    }

    /**
     * Number of attributes whose values differ. An empty ("any") value matches everything.
     */
    public static function attribute_distance( $a, $b ) {
        $distance = 0;
        foreach ( array_unique( array_merge( array_keys( (array) $a ), array_keys( (array) $b ) ) ) as $name ) {
            $va = isset( $a[ $name ] ) ? (string) $a[ $name ] : '';
            $vb = isset( $b[ $name ] ) ? (string) $b[ $name ] : '';
            if ( '' !== $va && '' !== $vb && 0 !== strcasecmp( $va, $vb ) ) {
                $distance++;
            }
        }
        return $distance;
    }

    /**
     * Checks nonce and capability of a metabox AJAX request and loads its order line. Ends the request on failure.
     *
     * @return array array( WC_Order, WC_Order_Item_Product ).
     */
    protected static function verify_ajax_request() {
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! $order_id || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $order_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the order and try again.', 'lp-missing' ) ), 403 );
        }
        if ( ! LP_Missing_Util::current_user_can_edit_order( $order_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this order.', 'lp-missing' ) ), 403 );
        }
        $order   = wc_get_order( $order_id );
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        // get_item() without a DB load only finds lines of this order.
        $item = $order instanceof WC_Order && $item_id ? $order->get_item( $item_id, false ) : false;
        if ( ! $item instanceof WC_Order_Item_Product ) {
            wp_send_json_error( array( 'message' => __( 'Order line not found.', 'lp-missing' ) ), 404 );
        }
        return array( $order, $item );
    }

    /**
     * AJAX: preview rows for the alternatives currently in the select (not saved).
     */
    public static function ajax_preview_alternatives() {
        list( $order, $item ) = self::verify_ajax_request();
        $alt_ids = isset( $_POST['alt_ids'] ) ? LP_Missing_Util::sanitize_alt_ids( wp_unslash( $_POST['alt_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized to IDs.
        $qty     = self::get_preview_qty( $item, LP_Missing_Line::get_item_data( $item ), isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 0 );

        wp_send_json_success(
            array(
                'html' => self::render_preview_items( $order, $item, $alt_ids, $qty ),
                'qty'  => $qty,
            )
        );
    }

    /**
     * AJAX: sibling variations to add to the alternatives select.
     */
    public static function ajax_variant_suggestions() {
        list( $order, $item ) = self::verify_ajax_request();
        if ( ! self::line_has_variants( $item ) ) {
            wp_send_json_error( array( 'message' => __( 'This line is not a product variation.', 'lp-missing' ) ), 400 );
        }
        $exclude = isset( $_POST['exclude'] ) ? wp_unslash( $_POST['exclude'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to IDs below.
        $exclude = is_array( $exclude ) ? $exclude : preg_split( '/[,\s]+/', (string) $exclude );
        $exclude = array_slice( array_filter( array_map( 'absint', $exclude ) ), 0, self::MAX_EXCLUDE );
        $qty     = self::get_preview_qty( $item, LP_Missing_Line::get_item_data( $item ), isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 0 );

        $suggestions = array();
        foreach ( self::get_variant_suggestions( $order, $item, $qty, $exclude ) as $product ) {
            $suggestions[] = array(
                'id'   => $product->get_id(),
                'text' => self::get_option_label( $product ),
            );
        }

        wp_send_json_success(
            array(
                'suggestions' => $suggestions,
                'qty'         => $qty,
                /* translators: %d: quantity */
                'message'     => $suggestions ? '' : sprintf( __( 'No other variant is in stock for %d unit(s).', 'lp-missing' ), $qty ),
            )
        );
    }
}
