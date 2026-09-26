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
        add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_internal_item_meta' ) );
    }

    /**
     * The plugin's bookkeeping on order lines is not for display on the order screen.
     */
    public static function hide_internal_item_meta( $hidden ) {
        return array_merge( (array) $hidden, array( LP_Missing_Plugin::META_KEY, LP_Missing_Plugin::MOVED_QTY_META, '_lp_missing_alt_pricing_source', '_lp_missing_alt_original_item_id' ) );
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
        $items         = $order->get_items( 'line_item' );
        $product_cache = array();
        $alt_ids       = array();
        $states        = array();
        foreach ( $items as $item_id => $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! empty( $data['alternatives'] ) ) {
                $alt_ids = array_merge( $alt_ids, $data['alternatives'] );
            }
            if ( ! empty( $data['selected_alt_id'] ) ) {
                $alt_ids[] = absint( $data['selected_alt_id'] );
            }
            $states[ $item_id ] = self::get_line_state( $data );
        }
        if ( $alt_ids ) {
            $products = wc_get_products( array( 'include' => array_values( array_unique( $alt_ids ) ), 'limit' => -1, 'type' => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ) ) );
            foreach ( $products as $product_obj ) {
                $product_cache[ $product_obj->get_id() ] = $product_obj;
            }
        }
        $context = array(
            'show_stock'    => 'yes' === LP_Missing_Settings::get( 'show_stock_preview' ),
            'next_reminder' => LP_Missing_Lifecycle::get_next_reminder_timestamp( $order ),
            'replacements'  => self::get_replacement_map( $items ),
        );
        $has_case = false;
        foreach ( $items as $item ) {
            $has_case = $has_case || $item->meta_exists( LP_Missing_Plugin::META_KEY );
        }

        echo '<div class="lp-missing-metabox" data-order-id="' . absint( $order->get_id() ) . '" data-nonce="' . esc_attr( LP_Missing_Admin_Alternatives::create_nonce( $order->get_id() ) ) . '">';
        self::render_summary( $states );
        echo '<div class="lp-lines">';
        foreach ( $items as $item_id => $item ) {
            self::render_line( $order, $item_id, $item, $states[ $item_id ], $product_cache, $context );
        }
        echo '</div>';
        echo '<div class="lp-savebar" hidden="hidden" role="status">';
        echo '<span class="lp-savebar__text" data-new="' . esc_attr__( 'Save the order to email the customer about the missing items.', 'lp-missing' ) . '" data-changed="' . esc_attr__( 'Save the order to keep your changes.', 'lp-missing' ) . '"></span>';
        echo '<button type="button" class="button button-primary lp-save">' . esc_html__( 'Save', 'lp-missing' ) . '</button>';
        echo '</div>';
        if ( $has_case ) {
            self::render_order_actions( $order );
        }
        echo '</div>';
    }

    /**
     * Where a line stands, for display: none (not missing), waiting (for the customer), attention (no answer,
     * escalated), chosen_alt / chosen_delete / declined (the customer answered; staff act next) or done (applied).
     */
    public static function get_line_state( $data ) {
        if ( self::is_applied( $data ) ) {
            return 'done';
        }
        if ( empty( $data['missing'] ) || LP_Missing_Line::is_line_resolved( $data ) ) {
            return 'none';
        }
        if ( 'alt_pending' === $data['status'] && ! empty( $data['selected_alt_id'] ) ) {
            return 'chosen_alt';
        }
        if ( 'delete_pending' === $data['status'] ) {
            return 'chosen_delete';
        }
        if ( 'declined' === $data['status'] ) {
            return 'declined';
        }
        return ! empty( $data['needs_attention'] ) ? 'attention' : 'waiting';
    }

    protected static function is_open_state( $state ) {
        return in_array( $state, array( 'waiting', 'attention', 'chosen_alt', 'chosen_delete', 'declined' ), true );
    }

    /**
     * Lines the plugin added as replacements: original item ID => replacement names, and replacement item ID =>
     * original name.
     */
    public static function get_replacement_map( $items ) {
        $map = array(
            'by_original'    => array(),
            'by_replacement' => array(),
        );
        foreach ( $items as $item_id => $item ) {
            $original_id = absint( $item->get_meta( '_lp_missing_alt_original_item_id', true ) );
            if ( $original_id && isset( $items[ $original_id ] ) ) {
                $map['by_original'][ $original_id ][] = sprintf( '%d × %s', $item->get_quantity(), $item->get_name() );
                $map['by_replacement'][ $item_id ]    = $items[ $original_id ]->get_name();
            }
        }
        return $map;
    }

    /**
     * One sentence on top of the box: how many lines wait for what.
     */
    protected static function render_summary( $states ) {
        $counts = array_count_values( $states );
        $parts  = array();
        $ready  = ( isset( $counts['chosen_alt'] ) ? $counts['chosen_alt'] : 0 ) + ( isset( $counts['chosen_delete'] ) ? $counts['chosen_delete'] : 0 ) + ( isset( $counts['declined'] ) ? $counts['declined'] : 0 );
        if ( $ready ) {
            /* translators: %d: number of lines */
            $parts[] = array( 'ready', sprintf( _n( '%d answered – ready to apply', '%d answered – ready to apply', $ready, 'lp-missing' ), $ready ) );
        }
        if ( ! empty( $counts['attention'] ) ) {
            /* translators: %d: number of lines */
            $parts[] = array( 'attention', sprintf( _n( '%d needs follow-up', '%d need follow-up', $counts['attention'], 'lp-missing' ), $counts['attention'] ) );
        }
        if ( ! empty( $counts['waiting'] ) ) {
            /* translators: %d: number of lines */
            $parts[] = array( 'waiting', sprintf( _n( '%d waiting for the customer', '%d waiting for the customer', $counts['waiting'], 'lp-missing' ), $counts['waiting'] ) );
        }
        if ( ! empty( $counts['done'] ) ) {
            /* translators: %d: number of lines */
            $parts[] = array( 'done', sprintf( _n( '%d done', '%d done', $counts['done'], 'lp-missing' ), $counts['done'] ) );
        }

        echo '<div class="lp-summary">';
        if ( ! $parts ) {
            echo '<p class="lp-summary__empty">' . esc_html__( 'Nothing is missing. If an item cannot be picked, press «Missing» on its line.', 'lp-missing' ) . '</p>';
        } else {
            foreach ( $parts as $part ) {
                echo '<span class="lp-pill lp-pill--' . esc_attr( $part[0] ) . '">' . esc_html( $part[1] ) . '</span>';
            }
        }
        echo '</div>';
    }

    protected static function get_state_label( $state ) {
        $labels = array(
            'waiting'       => __( 'Waiting for the customer', 'lp-missing' ),
            'attention'     => __( 'No answer – follow up', 'lp-missing' ),
            'chosen_alt'    => __( 'Customer chose a replacement', 'lp-missing' ),
            'chosen_delete' => __( 'Customer wants it removed', 'lp-missing' ),
            'declined'      => __( 'Customer said no thanks', 'lp-missing' ),
            'done'          => __( 'Done', 'lp-missing' ),
        );
        return isset( $labels[ $state ] ) ? $labels[ $state ] : '';
    }

    protected static function render_line( $order, $item_id, $item, $state, &$product_cache, $context ) {
        $item_id = absint( $item_id );
        $data    = LP_Missing_Line::get_item_data( $item );
        $product = $item->get_product();
        $name    = $product ? $product->get_name() : $item->get_name();
        $field   = 'lp_missing_items[' . $item_id . ']';
        $open    = self::is_open_state( $state );

        echo '<div class="lp-missing-item lp-line lp-line--' . esc_attr( $state ) . '" data-item-id="' . $item_id . '" data-state="' . esc_attr( $state ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        // What this screen was built from: a save from a stale screen must not overwrite a line changed meanwhile.
        echo '<input type="hidden" name="' . esc_attr( $field . '[rev]' ) . '" value="' . esc_attr( LP_Missing_Line::get_revision( $item ) ) . '" />';

        echo '<div class="lp-line__head">';
        echo self::get_thumbnail_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_url().
        echo '<div class="lp-line__title"><span class="lp-line__name">' . esc_html( $name ) . '</span> <span class="lp-line__qty">× ' . esc_html( $item->get_quantity() ) . '</span>';
        if ( isset( $context['replacements']['by_replacement'][ $item_id ] ) ) {
            /* translators: %s: product name */
            echo '<span class="lp-line__sub">' . esc_html( sprintf( __( 'Replacement for %s', 'lp-missing' ), $context['replacements']['by_replacement'][ $item_id ] ) ) . '</span>';
        }
        echo '</div>';
        echo '<div class="lp-line__side">';
        if ( $open ) {
            echo '<span class="lp-badge lp-badge--' . esc_attr( $state ) . '">' . esc_html( self::get_state_label( $state ) ) . '</span>';
            // Stays checked while the case is open; «Cancel the case» unchecks it.
            echo '<input type="checkbox" class="lp-missing-toggle" name="' . esc_attr( $field . '[missing]' ) . '" value="1" checked="checked" hidden="hidden" />';
        } else {
            echo '<label class="button lp-mark"><input type="checkbox" class="lp-missing-toggle" name="' . esc_attr( $field . '[missing]' ) . '" value="1" /> ';
            echo '<span class="lp-mark__text">' . esc_html( 'done' === $state ? __( 'Missing again', 'lp-missing' ) : __( 'Missing', 'lp-missing' ) ) . '</span></label>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div class="lp-line__body">';
        switch ( $state ) {
            case 'chosen_alt':
                self::render_alternative_decision( $order, $item_id, $item, $data, $product_cache );
                break;
            case 'chosen_delete':
            case 'declined':
            case 'attention':
                self::render_removal_decision( $order, $item_id, $item, $data, $state );
                break;
            case 'done':
                self::render_done( $order, $item_id, $item, $data, $context );
                break;
        }
        if ( $open ) {
            $facts = self::get_history_parts( $data, $context['next_reminder'] );
            /* translators: 1: missing quantity, 2: ordered quantity */
            array_unshift( $facts, sprintf( __( 'Missing %1$d of %2$d', 'lp-missing' ), $data['qty_missing'], $item->get_quantity() ) );
            echo '<p class="lp-missing-history lp-line__facts">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
            echo '<details class="lp-edit"><summary>' . esc_html__( 'Change or cancel', 'lp-missing' ) . '</summary>';
            self::render_editor( $order, $item_id, $item, $data, true, $product, $product_cache, $context );
            echo '<p class="lp-line__cancel"><button type="button" class="button-link lp-cancel-case">' . esc_html__( 'Cancel the case (the item is not missing after all)', 'lp-missing' ) . '</button>';
            echo '<span class="lp-cancel-note" hidden="hidden">' . esc_html__( 'The case is closed when you save: the customer is not asked, and the stock lock is released.', 'lp-missing' ) . ' <button type="button" class="button-link lp-undo-cancel">' . esc_html__( 'Undo', 'lp-missing' ) . '</button></span></p>';
            echo '</details>';
        } else {
            echo '<div class="lp-new-case" hidden="hidden">';
            self::render_editor( $order, $item_id, $item, $data, false, $product, $product_cache, $context );
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    protected static function get_thumbnail_html( $product ) {
        $url = $product && $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '';
        if ( ! $url && function_exists( 'wc_placeholder_img_src' ) ) {
            $url = wc_placeholder_img_src( 'thumbnail' );
        }
        return $url ? '<img class="lp-line__thumb" src="' . esc_url( $url ) . '" alt="" width="40" height="40" />' : '<span class="lp-line__thumb"></span>';
    }

    /**
     * The fields of a case: quantity, replacements, removal option, customer message and internal note.
     *
     * @param bool $open Whether the case is open (otherwise the fields start a new case, with defaults).
     */
    protected static function render_editor( $order, $item_id, $item, $data, $open, $product, &$product_cache, $context ) {
        $field     = 'lp_missing_items[' . absint( $item_id ) . ']';
        $available = max( 1, LP_Missing_Line::get_item_available_qty( $item ) );
        $qty       = $open && $data['qty_missing'] ? absint( $data['qty_missing'] ) : 1;
        $qty_id    = 'lp-missing-qty-' . absint( $item_id );
        $notes_id  = 'lp-missing-notes-' . absint( $item_id );

        echo '<div class="lp-editor">';
        echo '<div class="lp-editor__row lp-editor__row--qty"><label class="lp-editor__label" for="' . esc_attr( $qty_id ) . '">' . esc_html__( 'How many are missing?', 'lp-missing' ) . '</label>';
        echo '<span class="lp-stepper"><button type="button" class="button lp-step" data-step="-1" aria-label="' . esc_attr__( 'One fewer', 'lp-missing' ) . '">−</button>';
        echo '<input type="number" id="' . esc_attr( $qty_id ) . '" class="lp-missing-qty" min="1" max="' . esc_attr( $available ) . '" inputmode="numeric" name="' . esc_attr( $field . '[qty_missing]' ) . '" value="' . esc_attr( $qty ) . '" />';
        echo '<button type="button" class="button lp-step" data-step="1" aria-label="' . esc_attr__( 'One more', 'lp-missing' ) . '">+</button></span>';
        /* translators: %d: quantity on the order line */
        echo ' <span class="lp-editor__hint">' . esc_html( sprintf( __( 'of %d', 'lp-missing' ), $available ) ) . '</span></div>';

        self::render_alternatives_field( $order, $item_id, $item, $data, $product, $product_cache, $context['show_stock'], $open );

        $propose = $open ? ! empty( $data['propose_delete'] ) : true;
        echo '<label class="lp-editor__check"><input type="checkbox" name="' . esc_attr( $field . '[propose_delete]' ) . '" value="1" ' . checked( true, $propose, false ) . ' /> ' . esc_html__( 'The customer may also choose to have the item removed', 'lp-missing' ) . '</label>';

        echo '<div class="lp-editor__row"><label class="lp-editor__label" for="' . esc_attr( $notes_id ) . '">' . esc_html__( 'Message to the customer', 'lp-missing' ) . ' <span class="lp-optional">' . esc_html__( '(optional)', 'lp-missing' ) . '</span></label>';
        echo '<textarea id="' . esc_attr( $notes_id ) . '" class="lp-missing-textarea" name="' . esc_attr( $field . '[notes]' ) . '" rows="2">' . esc_textarea( $open ? $data['notes'] : '' ) . '</textarea>';
        $presets = self::get_note_presets();
        if ( $presets ) {
            echo '<div class="lp-presets"><span class="lp-presets__label">' . esc_html__( 'Quick text:', 'lp-missing' ) . '</span> ';
            foreach ( $presets as $preset ) {
                echo '<button type="button" class="button-link lp-preset" data-text="' . esc_attr( $preset ) . '">' . esc_html( $preset ) . '</button> ';
            }
            echo '</div>';
        }
        echo '</div>';

        $internal = $open ? (string) $data['internal_notes'] : '';
        echo '<details class="lp-internal"' . ( '' !== $internal ? ' open="open"' : '' ) . '><summary>' . esc_html__( 'Internal note (only staff see it)', 'lp-missing' ) . '</summary>';
        echo '<textarea class="lp-missing-textarea" name="' . esc_attr( $field . '[internal_notes]' ) . '" rows="2" aria-label="' . esc_attr__( 'Internal note', 'lp-missing' ) . '">' . esc_textarea( $internal ) . '</textarea></details>';
        echo '</div>';
    }

    /**
     * Ready-made customer messages offered as one-click texts under the message field.
     *
     * @return string[]
     */
    public static function get_note_presets() {
        $presets = array(
            __( 'Utsolgt hos leverandøren.', 'lp-missing' ),
            __( 'Midlertidig utsolgt – kommer tilbake om ca. 2 uker.', 'lp-missing' ),
            __( 'Varen var skadet.', 'lp-missing' ),
            __( 'Varen er utgått og kommer ikke tilbake.', 'lp-missing' ),
        );
        /**
         * Filter the one-click customer messages in the order screen box.
         *
         * @param string[] $presets Messages (shown to the customer as written).
         */
        return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'lp_missing_note_presets', $presets ) ) ) );
    }

    /**
     * Alternatives select (WooCommerce's product search, so staff pick from a result list instead of auto-added
     * first hits), the "other variant" button and the price/stock preview of the chosen alternatives.
     */
    public static function render_alternatives_field( $order, $item_id, $item, $data, $product, &$product_cache, $show_stock, $open = true ) {
        $item_id = absint( $item_id );
        echo '<div class="lp-editor__row lp-missing-field lp-missing-alternatives" data-item-id="' . $item_id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        /* translators: %d: maximum number of alternatives */
        echo '<label class="lp-editor__label" for="lp-missing-alt-' . $item_id . '">' . esc_html( sprintf( __( 'Suggest replacements (up to %d)', 'lp-missing' ), LP_Missing_Plugin::MAX_ALTERNATIVES ) ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        echo '<select id="lp-missing-alt-' . $item_id . '" class="wc-product-search lp-alt-select" multiple="multiple" name="lp_missing_items[' . $item_id . '][alternatives][]"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
        echo ' data-placeholder="' . esc_attr__( 'Search for a product (name or SKU)', 'lp-missing' ) . '"';
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
        foreach ( $open ? $data['alternatives'] : array() as $alt_id ) {
            $alt_product = LP_Missing_Util::get_cached_product( $alt_id, $product_cache );
            if ( $alt_product ) {
                $alternatives[] = $alt_product;
                echo '<option value="' . absint( $alt_id ) . '" selected="selected">' . esc_html( LP_Missing_Admin_Alternatives::get_option_label( $alt_product ) ) . '</option>';
            }
        }
        echo '</select>';

        if ( LP_Missing_Admin_Alternatives::line_has_variants( $item ) ) {
            echo '<p class="lp-missing-variants-row">';
            echo '<button type="button" class="button lp-missing-variants" data-item-id="' . $item_id . '">' . esc_html__( 'Find other sizes/variants', 'lp-missing' ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint.
            echo '<span class="lp-missing-variants-status description" aria-live="polite"></span>';
            echo '</p>';
        }

        // Always rendered (also empty) so the script can fill it when alternatives are picked.
        $qty = LP_Missing_Admin_Alternatives::get_preview_qty( $item, $data );
        echo '<ul class="lp-alt-list" aria-live="polite">' . LP_Missing_Admin_Alternatives::render_preview_items( $order, $item, $alternatives, $qty, $show_stock ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
        echo '</div>';
    }

    /**
     * The customer chose an alternative: what they chose, what it means for the price, and the apply buttons.
     */
    protected static function render_alternative_decision( $order, $item_id, $item, $data, &$product_cache ) {
        $alt = LP_Missing_Util::get_cached_product( $data['selected_alt_id'], $product_cache );
        if ( ! $alt ) {
            echo '<p class="lp-decision lp-decision--warning">' . esc_html__( 'The product the customer chose no longer exists. Suggest other replacements.', 'lp-missing' ) . '</p>';
            return;
        }
        $qty      = $data['qty_alt'] ? absint( $data['qty_alt'] ) : absint( $data['qty_missing'] );
        $snapshot = LP_Missing_Pricing::scale_pricing_snapshot( LP_Missing_Pricing::get_frozen_pricing_snapshot( $order, $item, $data, $alt, $qty ), $qty );
        $delta    = (float) $snapshot['delta_total_incl'];
        $amount   = LP_Missing_Util::plain_price( abs( $delta ), $order );
        $epsilon  = 0.5 * pow( 10, -wc_get_price_decimals() );
        $invoice  = false;
        if ( $delta >= $epsilon ) {
            if ( LP_Missing_Pricing::store_covers_difference( $delta ) ) {
                /* translators: %s: amount */
                $money = sprintf( __( 'Costs %s more – the store covers it.', 'lp-missing' ), $amount );
            } else {
                $invoice = true;
                /* translators: %s: amount */
                $money = sprintf( __( 'Costs %s more – the customer gets a separate invoice for it.', 'lp-missing' ), $amount );
            }
        } elseif ( $delta <= -$epsilon ) {
            /* translators: %s: amount */
            $money = sprintf( __( 'Costs %s less – the order total stays the same.', 'lp-missing' ), $amount );
        } else {
            $money = __( 'Same price.', 'lp-missing' );
        }

        echo '<div class="lp-decision">';
        echo self::get_thumbnail_html( $alt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_url().
        echo '<div class="lp-decision__text"><span class="lp-decision__what">' . esc_html( sprintf( '%d × %s', $qty, $alt->get_name() ) ) . '</span>';
        echo '<span class="lp-decision__money' . ( $invoice ? ' lp-decision__money--invoice' : '' ) . '">' . esc_html( $money ) . '</span>';
        if ( ! LP_Missing_Admin_Alternatives::can_cover( $alt, $qty ) ) {
            $left = $alt->managing_stock() ? max( 0, (int) $alt->get_stock_quantity() ) : 0;
            /* translators: %d: units in stock */
            echo '<span class="lp-decision__warning">' . esc_html( $left ? sprintf( __( 'Only %d in stock – check before you replace.', 'lp-missing' ), $left ) : __( 'Out of stock – check before you replace.', 'lp-missing' ) ) . '</span>';
        }
        echo '</div></div>';

        /* translators: 1: quantity, 2: ordered product, 3: replacement product */
        $confirm = sprintf( __( 'Replace %1$d × %2$s with %3$s on the order?', 'lp-missing' ), $qty, $item->get_name(), $alt->get_name() );
        if ( $invoice ) {
            /* translators: %s: amount */
            $confirm .= ' ' . sprintf( __( 'The customer is sent an invoice of %s.', 'lp-missing' ), $amount );
        }
        echo '<div class="lp-actions">';
        self::render_apply_link( $order, $item_id, 'alternative', 'replace', __( 'Replace the missing item', 'lp-missing' ), true, $confirm );
        self::render_apply_link( $order, $item_id, 'alternative', 'add', __( 'or add it as a separate line', 'lp-missing' ), 'link', $confirm );
        echo '</div>';
    }

    /**
     * Removal: the customer asked for it, said no thanks, or never answered. Remove from the order or refund.
     */
    protected static function render_removal_decision( $order, $item_id, $item, $data, $state ) {
        $texts = array(
            'chosen_delete' => __( 'The customer wants the missing item removed from the order.', 'lp-missing' ),
            'declined'      => __( 'The customer said no thanks to the replacements. Remove the item, or suggest other replacements.', 'lp-missing' ),
            'attention'     => __( 'The customer has not answered. Remove the item, or remind them by email.', 'lp-missing' ),
        );
        echo '<p class="lp-decision lp-decision--' . esc_attr( $state ) . '">' . esc_html( $texts[ $state ] ) . '</p>';
        if ( ! LP_Missing_Line::can_apply_deletion( $data ) ) {
            return;
        }
        $available = LP_Missing_Line::get_item_available_qty( $item );
        $qty       = max( 1, min( $data['qty_missing'] ? absint( $data['qty_missing'] ) : $available, max( 1, $available ) ) );
        $share     = LP_Missing_Pricing::get_item_share( $item, $qty );
        $gross     = (float) $share['total'] + array_sum( array_map( 'floatval', $share['taxes']['total'] ) );
        $amount    = LP_Missing_Util::plain_price( $gross, $order );

        echo '<div class="lp-actions">';
        /* translators: 1: amount */
        $label = sprintf( __( 'Remove from the order (−%s)', 'lp-missing' ), $amount );
        /* translators: 1: quantity, 2: product, 3: amount */
        self::render_apply_link( $order, $item_id, 'delete', 'reduce', $label, true, sprintf( __( 'Remove %1$d × %2$s from the order? The order total goes down by %3$s.', 'lp-missing' ), $qty, $item->get_name(), $amount ) );
        /* translators: 1: amount, 2: quantity, 3: product */
        self::render_apply_link( $order, $item_id, 'delete', 'refund', __( 'or record a refund instead', 'lp-missing' ), 'link', sprintf( __( 'Record a refund of %1$s for %2$d × %3$s? The order total stays the same; pay the money back in the payment provider.', 'lp-missing' ), $amount, $qty, $item->get_name() ) );
        if ( 'declined' === $state ) {
            echo '<button type="button" class="button-link lp-open-edit">' . esc_html__( 'Suggest other replacements', 'lp-missing' ) . '</button>';
        } elseif ( 'attention' === $state ) {
            $send_url = wp_nonce_url( add_query_arg( array( 'action' => 'lp_missing_send_email', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'lp_missing_send_email_' . $order->get_id() );
            echo '<a class="button-link" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Email the customer again', 'lp-missing' ) . '</a>';
        }
        echo '</div>';
    }

    /**
     * An applied case: one line saying what was done.
     */
    protected static function render_done( $order, $item_id, $item, $data, $context ) {
        $when = $data['resolved_at'] ? self::format_short_date( $data['resolved_at'] ) : '';
        if ( 'alt_applied' === $data['status'] ) {
            $with = isset( $context['replacements']['by_original'][ $item_id ] ) ? implode( ', ', $context['replacements']['by_original'][ $item_id ] ) : '';
            /* translators: %s: replacement products */
            $text = $with ? sprintf( __( 'Replaced with %s', 'lp-missing' ), $with ) : __( 'Replacement applied', 'lp-missing' );
        } else {
            $refunded = absint( $order->get_qty_refunded_for_item( $item_id ) );
            /* translators: %d: quantity */
            $text = $refunded ? sprintf( __( 'Refund recorded for %d', 'lp-missing' ), $refunded ) : __( 'Removed from the order', 'lp-missing' );
        }
        echo '<p class="lp-line__done"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html( $text . ( $when ? ' · ' . $when : '' ) ) . '</p>';
    }

    /**
     * Customer link tools below the lines: email again, copy the link, view as the customer, revoke.
     */
    public static function render_order_actions( $order ) {
        $order_id = $order->get_id();
        echo '<div class="lp-missing-admin-actions">';
        echo '<span class="lp-tools__label">' . esc_html__( 'Customer page:', 'lp-missing' ) . '</span>';
        if ( LP_Missing_Orders::order_has_missing_items( $order ) ) {
            $send_url = wp_nonce_url( add_query_arg( array( 'action' => 'lp_missing_send_email', 'order_id' => $order_id ), admin_url( 'admin-post.php' ) ), 'lp_missing_send_email_' . $order_id );
            echo '<a class="button lp-missing-send-email" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Email the customer again', 'lp-missing' ) . '</a>';
        }

        $link = LP_Missing_Magic_Link::get_magic_link_for_order( $order );
        if ( $link ) {
            echo '<button type="button" class="button lp-missing-copy-link" data-link="' . esc_attr( $link ) . '">' . esc_html__( 'Copy customer link', 'lp-missing' ) . '</button>';
        }
        $preview = LP_Missing_Magic_Link::get_staff_preview_url( $order );
        if ( $preview ) {
            echo '<a class="button lp-missing-preview" href="' . esc_url( $preview ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View as customer', 'lp-missing' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'lp-missing' ) . '</span></a>';
        }
        echo '<details class="lp-more"><summary>' . esc_html__( 'More', 'lp-missing' ) . '</summary>';
        echo '<a class="button lp-missing-revoke lp-missing-confirm" href="' . esc_url( self::get_revoke_url( $order ) ) . '" data-confirm="' . esc_attr__( 'Revoke all customer links for this order? Links in emails already sent stop working; send a new email to give the customer a working link.', 'lp-missing' ) . '">' . esc_html__( 'Revoke customer links', 'lp-missing' ) . '</a>';
        echo '</details>';
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

    /**
     * Status text of a line as shown in the box (kept for integrations; the box renders it per state).
     */
    public static function render_admin_line_status( $order, $item_id, $item, $data, &$product_cache, $next_reminder = null ) {
        $state = self::get_line_state( $data );
        echo '<div class="lp-missing-status"><strong>' . esc_html( self::get_state_label( $state ) ? self::get_state_label( $state ) : __( 'Not missing', 'lp-missing' ) ) . '</strong>';
        $history = self::get_history_parts( $data, null === $next_reminder ? LP_Missing_Lifecycle::get_next_reminder_timestamp( $order ) : $next_reminder );
        if ( $history ) {
            echo '<p class="lp-missing-history">' . esc_html( implode( ' · ', $history ) ) . '</p>';
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
        $parts[] = $data['notified_at'] ? sprintf( __( 'emailed %s', 'lp-missing' ), self::format_short_date( $data['notified_at'] ) ) : __( 'not emailed yet', 'lp-missing' );
        if ( $awaiting ) {
            /* translators: 1: reminders sent, 2: maximum number of reminders */
            $parts[] = sprintf( __( '%1$d/%2$d reminders', 'lp-missing' ), $data['reminder_count'], LP_Missing_Settings::get( 'reminder_max_count' ) );
            if ( $next_reminder ) {
                /* translators: %s: date and time */
                $parts[] = sprintf( __( 'next %s', 'lp-missing' ), self::format_short_date( $next_reminder, true ) );
            }
        }

        $decided = $data['decision_made_at'] ? self::format_short_date( $data['decision_made_at'] ) : '';
        if ( in_array( $data['status'], array( 'alt_pending', 'delete_pending', 'declined' ), true ) ) {
            /* translators: %s: date */
            $parts[] = $decided ? sprintf( __( 'answered %s', 'lp-missing' ), $decided ) : __( 'answered', 'lp-missing' );
        } elseif ( self::is_applied( $data ) && $data['resolved_at'] ) {
            /* translators: %s: date */
            $parts[] = sprintf( __( 'applied %s', 'lp-missing' ), self::format_short_date( $data['resolved_at'] ) );
        }

        $deadline = $awaiting ? LP_Missing_Deadline::get_for_line( $data ) : 0;
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

    /**
     * @param bool|string $style   true: primary button, false: button, 'link': plain link.
     * @param string      $confirm Question shown before the order is changed.
     */
    public static function render_apply_link( $order, $item_id, $type, $mode, $label, $style = false, $confirm = '' ) {
        $class   = 'link' === $style ? 'button-link' : ( $style ? 'button button-primary' : 'button' );
        $confirm = '' !== $confirm ? $confirm : __( 'This changes the order now. Continue?', 'lp-missing' );
        echo '<a class="' . esc_attr( $class ) . ' lp-missing-apply lp-missing-confirm" href="' . esc_url( self::get_apply_url( $order, $item_id, $type, $mode ) ) . '" data-confirm="' . esc_attr( $confirm ) . '">' . esc_html( $label ) . '</a>';
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
                    'inserted'   => __( 'Text added to the message.', 'lp-missing' ),
                    'copyManual' => __( 'Copy the link from the field (Ctrl+C / Cmd+C).', 'lp-missing' ),
                    'searching'  => __( 'Looking for variants…', 'lp-missing' ),
                    /* translators: %d: number of variants */
                    'added'      => __( 'Added %d variant(s).', 'lp-missing' ),
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
