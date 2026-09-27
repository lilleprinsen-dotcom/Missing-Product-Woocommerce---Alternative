<?php
/**
 * Builds what the portal templates show: cards per missing line, alternatives with prices and stock,
 * the customer's current decision, the summary and the settings for the live summary script.
 *
 * Templates (overridable in yourtheme/lp-missing/portal/): portal.php, item.php, verify.php, message.php.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Portal_View {
    const MAX_COVERS_FLAGS = 100;

    // ---------------------------------------------------------------------------------------------------------------
    // Renderers.
    // ---------------------------------------------------------------------------------------------------------------

    public static function render_message( $type, $message, $emoji = '', $links = array() ) {
        return LP_Missing_Util::get_template_html(
            'portal/message.php',
            array(
                'type'    => $type,
                'message' => $message,
                'emoji'   => $emoji,
                'links'   => $links,
            )
        );
    }

    /**
     * The portal page opened without an order: explain, and list a logged-in customer's open cases.
     */
    public static function render_landing() {
        $links = array();
        if ( is_user_logged_in() ) {
            $orders = wc_get_orders(
                array(
                    'type'        => 'shop_order',
                    'customer_id' => get_current_user_id(),
                    'limit'       => 10,
                    'return'      => 'objects',
                    'meta_key'    => LP_Missing_Plugin::ORDER_META_HAS_OPEN, // phpcs:ignore WordPress.DB.SlowDBQuery
                    'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
                )
            );
            foreach ( $orders as $order ) {
                $links[] = array(
                    'url'   => add_query_arg( LP_Missing_Magic_Link::PARAM_ORDER, $order->get_id(), LP_Missing_Portal::get_clean_url() ),
                    /* translators: %s: order number */
                    'label' => sprintf( __( 'Ordre %s', 'lp-missing' ), $order->get_order_number() ),
                );
            }
        }
        if ( $links && ! defined( 'DONOTCACHEPAGE' ) ) {
            // A personal list: never store it in a page cache.
            define( 'DONOTCACHEPAGE', true );
        }
        $message = $links
            ? __( 'Disse bestillingene har varer som mangler. Velg en bestilling for å se hva du kan velge.', 'lp-missing' )
            : __( 'Her velger du hva som skal skje når en vare i bestillingen din mangler. Åpne lenken i e-posten fra oss for å komme til dine valg.', 'lp-missing' );
        return self::render_message( 'info', $message, '', $links );
    }

    public static function render_verify( $ctx ) {
        $error = '';
        if ( $ctx['result'] && 'error' === $ctx['result']['status'] ) {
            $error = $ctx['result']['message'];
        }
        return LP_Missing_Util::get_template_html(
            'portal/verify.php',
            array(
                'order'      => $ctx['order'],
                'error'      => $error,
                'action_url' => LP_Missing_Portal::get_clean_url( $ctx['order_id'] ),
                'fields'     => array(
                    LP_Missing_Portal::ORDER_FIELD  => $ctx['order_id'],
                    LP_Missing_Portal::ACTION_FIELD => 'verify',
                    LP_Missing_Portal::TOKEN_FIELD  => LP_Missing_Portal_Access::get_form_token( $ctx ),
                ),
            )
        );
    }

    public static function render_portal( $ctx ) {
        $view = self::build( $ctx );
        if ( empty( $view['lines'] ) ) {
            return self::render_message( 'success', __( 'Alt er i orden! Ingen valg venter på deg nå.', 'lp-missing' ), '🎉' );
        }
        return LP_Missing_Util::get_template_html(
            'portal/portal.php',
            array(
                'order' => $ctx['order'],
                'view'  => $view,
            )
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // View model.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Everything portal.php needs. See the template for the keys.
     */
    public static function build( $ctx ) {
        $order    = $ctx['order'];
        $readonly = 'preview' === $ctx['mode'];
        $result   = is_array( $ctx['result'] ) ? $ctx['result'] : array();
        $posted   = isset( $result['posted'] ) ? $result['posted'] : array();
        $errors   = isset( $result['line_errors'] ) ? $result['line_errors'] : array();
        $cache    = array();
        $lines    = array();

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! LP_Missing_Portal_Decisions::is_editable( $data ) ) {
                continue;
            }
            $lines[] = self::build_line(
                $order,
                $item_id,
                $item,
                $data,
                isset( $posted[ $item_id ] ) ? $posted[ $item_id ] : null,
                isset( $errors[ $item_id ] ) ? $errors[ $item_id ] : '',
                $readonly,
                $cache
            );
        }

        $deadline = LP_Missing_Deadline::get_for_order( $order );
        $summary  = self::build_summary( $order, $lines );
        $awaiting = 0;
        foreach ( $lines as $line ) {
            if ( '' === $line['current_choice'] ) {
                $awaiting++;
            }
        }

        return array(
            'readonly'      => $readonly,
            'readonly_text' => __( 'Staff preview: this is what the customer sees. Nothing can be saved from here.', 'lp-missing' ),
            /* translators: %s: customer first name */
            'greeting'      => sprintf( __( 'Hei %s', 'lp-missing' ), LP_Missing_Util::get_customer_first_name( $order ) ),
            /* translators: %s: order number */
            'order_label'   => sprintf( __( 'Ordre %s', 'lp-missing' ), $order->get_order_number() ),
            'intro'         => __( 'Noen varer i bestillingen din mangler dessverre. Velg hva du vil at vi skal gjøre med hver av dem, og trykk «Lagre valgene». Prisen låses når du lagrer.', 'lp-missing' ),
            'change_hint'   => __( 'Du kan endre valget ditt helt til vi har oppdatert ordren.', 'lp-missing' ),
            'deadline'      => $deadline ? LP_Missing_Deadline::describe( $deadline, $order ) : '',
            'notice'        => self::get_notice( $ctx, $awaiting ),
            'lines'         => $lines,
            'summary'       => $summary,
            'form'          => array(
                'action' => LP_Missing_Portal::get_clean_url( $ctx['order_id'] ),
                'fields' => $readonly ? array() : array(
                    LP_Missing_Portal::ORDER_FIELD  => $ctx['order_id'],
                    LP_Missing_Portal::ACTION_FIELD => 'save',
                    LP_Missing_Portal::TOKEN_FIELD  => LP_Missing_Portal_Access::get_form_token( $ctx ),
                ),
                'config' => wp_json_encode( self::get_script_config( $order ) ),
            ),
            'submit_label'  => __( 'Lagre valgene', 'lp-missing' ),
        );
    }

    /**
     * Status message: the outcome of this request's POST, or of the one before the redirect (?lp_msg=).
     */
    public static function get_notice( $ctx, $awaiting ) {
        if ( 'preview' === $ctx['mode'] ) {
            return null;
        }
        $result = is_array( $ctx['result'] ) ? $ctx['result'] : null;
        if ( $result && 'error' === $result['status'] ) {
            return array(
                'type'  => 'error',
                'text'  => $result['message'],
                'emoji' => '',
            );
        }
        $code = $result ? $result['code'] : ( isset( $_GET[ LP_Missing_Portal::MESSAGE_PARAM ] ) && is_string( $_GET[ LP_Missing_Portal::MESSAGE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ LP_Missing_Portal::MESSAGE_PARAM ] ) ) : '' );
        $remaining = $awaiting ? ' ' . sprintf(
            /* translators: %d: number of lines without an answer */
            _n( '%d vare venter fortsatt på svaret ditt.', '%d varer venter fortsatt på svaret ditt.', $awaiting, 'lp-missing' ),
            $awaiting
        ) : '';
        switch ( $code ) {
            case 'saved':
                return array( 'type' => 'success', 'text' => __( 'Takk! Valget ditt er lagret', 'lp-missing' ), 'emoji' => '💛', 'after' => $remaining );
            case 'saved_partial':
                return array( 'type' => 'success', 'text' => __( 'Takk! Valget ditt er lagret. Noen varer var allerede behandlet av oss og ble ikke endret.', 'lp-missing' ), 'emoji' => '💛', 'after' => $remaining );
            case 'handled':
                return array( 'type' => 'info', 'text' => __( 'Varen er allerede behandlet av oss, så valget kan ikke endres her.', 'lp-missing' ), 'emoji' => '' );
            case 'nochange':
                return array( 'type' => 'info', 'text' => __( 'Du har ikke endret noen valg.', 'lp-missing' ), 'emoji' => '' );
            case 'verified':
                return array( 'type' => 'success', 'text' => __( 'Takk! E-postadressen er bekreftet.', 'lp-missing' ), 'emoji' => '' );
        }
        return null;
    }

    protected static function get_product_image_url( $product ) {
        $image_id = $product ? $product->get_image_id() : 0;
        $url      = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
        return $url ? $url : wc_placeholder_img_src( 'woocommerce_thumbnail' );
    }

    protected static function price_threshold() {
        return 0.5 * pow( 10, -wc_get_price_decimals() );
    }

    /**
     * Frozen prices when this is the alternative the customer chose (what they were shown), live prices otherwise.
     */
    public static function get_alternative_snapshot( $order, $item, $data, $alt_product, $qty ) {
        $preview_data                    = $data;
        $preview_data['selected_alt_id'] = $alt_product->get_id();
        return LP_Missing_Pricing::get_frozen_pricing_snapshot( $order, $item, $preview_data, $alt_product, $qty );
    }

    /**
     * Customer text for a per-unit difference on $qty units (the texts customers already know from the emails).
     */
    public static function describe_delta( $order, $delta_unit, $qty ) {
        $threshold = self::price_threshold();
        $total     = round( $delta_unit * $qty, wc_get_price_decimals() );
        $unit_text = LP_Missing_Util::plain_price( abs( $delta_unit ), $order );
        if ( $delta_unit >= $threshold ) {
            if ( LP_Missing_Pricing::store_covers_difference( $total ) ) {
                /* translators: %s: price difference per unit */
                return sprintf( __( 'Koster %1$s mer per stk, men butikken dekker mellomlegget. Du betaler ikke noe ekstra.', 'lp-missing' ), $unit_text );
            }
            /* translators: 1: difference per unit, 2: total difference, 3: quantity */
            return sprintf( __( 'Koster %1$s mer per stk (%2$s mer for %3$s stk). Mellomlegget faktureres i en egen ordre.', 'lp-missing' ), $unit_text, LP_Missing_Util::plain_price( abs( $total ), $order ), $qty );
        }
        if ( $delta_unit <= -$threshold ) {
            /* translators: %s: price difference per unit */
            return sprintf( __( 'Rimeligere enn originalen (%1$s mindre per stk). Ordren beholder opprinnelig pris.', 'lp-missing' ), $unit_text );
        }
        return __( 'Samme pris som original vare', 'lp-missing' );
    }

    /**
     * The customer's saved decision with the price that was locked when it was made.
     */
    protected static function describe_decision( $order, $item, $data, &$cache ) {
        if ( 'declined' === $data['status'] ) {
            return array(
                'text'  => __( 'Nei takk – du betaler ikke for det som mangler.', 'lp-missing' ),
                'price' => '',
            );
        }
        if ( 'delete_pending' === $data['status'] ) {
            return array(
                'text'  => __( 'Fjern varen fra ordren.', 'lp-missing' ),
                'price' => '',
            );
        }
        $alt_product = LP_Missing_Util::get_cached_product( $data['selected_alt_id'], $cache );
        if ( 'alt_pending' !== $data['status'] || ! $alt_product ) {
            return null;
        }
        $qty      = $data['qty_alt'] ? $data['qty_alt'] : max( 1, $data['qty_missing'] );
        $snapshot = LP_Missing_Pricing::scale_pricing_snapshot( self::get_alternative_snapshot( $order, $item, $data, $alt_product, $qty ), $qty );
        $delta    = (float) $snapshot['delta_per_unit_incl'];
        $total    = abs( (float) $snapshot['delta_total_incl'] );
        if ( $delta >= self::price_threshold() ) {
            $price = LP_Missing_Pricing::store_covers_difference( (float) $snapshot['delta_total_incl'] )
                /* translators: %s: total difference */
                ? sprintf( __( 'Pris låst ved valg: %s mer totalt, som butikken dekker.', 'lp-missing' ), LP_Missing_Util::plain_price( $total, $order ) )
                /* translators: %s: total difference */
                : sprintf( __( 'Pris låst ved valg: %s mer totalt. Mellomlegget faktureres i en egen ordre.', 'lp-missing' ), LP_Missing_Util::plain_price( $total, $order ) );
        } elseif ( $delta <= -self::price_threshold() ) {
            /* translators: %s: total difference */
            $price = sprintf( __( 'Rimeligere enn originalen (%s mindre totalt). Ordren beholder opprinnelig pris.', 'lp-missing' ), LP_Missing_Util::plain_price( $total, $order ) );
        } else {
            $price = __( 'Pris: ingen forskjell.', 'lp-missing' );
        }
        return array(
            /* translators: 1: product name, 2: quantity */
            'text'  => sprintf( __( '%1$s × %2$s', 'lp-missing' ), $alt_product->get_name(), $qty ),
            'price' => $price,
        );
    }

    protected static function build_line( $order, $item_id, $item, $data, $posted, $error, $readonly, &$cache ) {
        $product        = $item->get_product();
        $name           = $product ? $product->get_name() : $item->get_name();
        $qty_missing    = max( 1, $data['qty_missing'] );
        $current_choice = LP_Missing_Portal_Decisions::get_current_choice( $data );
        $selected       = $posted ? $posted['choice'] : $current_choice;
        $current_qty    = 'alt_pending' === $data['status'] && $data['qty_alt'] ? $data['qty_alt'] : $qty_missing;
        $qty_value      = $posted && null !== $posted['qty'] ? (int) $posted['qty'] : $current_qty;
        $options        = array();
        $offered        = 0;
        $unavailable    = false;
        $max_by_stock   = 0;

        foreach ( $data['alternatives'] as $alt_id ) {
            $alt_product = LP_Missing_Util::get_cached_product( $alt_id, $cache );
            if ( ! $alt_product || 'trash' === $alt_product->get_status() ) {
                continue;
            }
            $offered++;
            $value = 'alt:' . $alt_id;
            // S7: out-of-stock alternatives are not offered.
            if ( ! $alt_product->is_in_stock() ) {
                if ( $value === $current_choice ) {
                    $unavailable = true;
                }
                continue;
            }
            $options[] = self::build_alternative_option( $order, $item, $item_id, $data, $alt_product, $value === $selected );
        }
        foreach ( $options as $option ) {
            $max_by_stock = max( $max_by_stock, $option['max_qty'] );
        }

        $options[] = array(
            'type'        => 'decline',
            'value'       => 'decline',
            'id'          => 'lp-opt-' . $item_id . '-decline',
            'label'       => __( 'Nei takk', 'lp-missing' ),
            'emoji'       => '❌',
            'description' => __( 'Jeg vil ikke ha noe alternativ. Du betaler ikke for det som mangler.', 'lp-missing' ),
            'checked'     => 'decline' === $selected,
        );
        if ( ! empty( $data['propose_delete'] ) ) {
            $options[] = array(
                'type'        => 'delete',
                'value'       => 'delete',
                'id'          => 'lp-opt-' . $item_id . '-delete',
                'label'       => __( 'Fjern varen fra ordren', 'lp-missing' ),
                'emoji'       => '🗑️',
                'description' => __( 'Varen tas ut av bestillingen, og du betaler ikke for den.', 'lp-missing' ),
                'checked'     => 'delete' === $selected,
            );
        }

        $has_alternatives = $max_by_stock > 0;
        $decision         = LP_Missing_Line::has_customer_decision( $data ) ? self::describe_decision( $order, $item, $data, $cache ) : null;

        return array(
            'item_id'           => $item_id,
            'name'              => $name,
            'image'             => self::get_product_image_url( $product ),
            'qty_missing'       => $qty_missing,
            /* translators: 1: ordered quantity, 2: missing quantity */
            'meta_text'         => sprintf( __( 'Bestilt: %1$s stk · Mangler: %2$s stk', 'lp-missing' ), $item->get_quantity(), $qty_missing ),
            /* translators: %s: unit price */
            'paid_text'         => sprintf( __( 'Du betalte %s per stk', 'lp-missing' ), LP_Missing_Util::plain_price( LP_Missing_Pricing::get_item_unit_price_incl_tax( $item ), $order ) ),
            'note'              => (string) $data['notes'],
            'current_choice'    => $current_choice,
            'selected'          => $selected,
            'decision'          => $decision,
            'decision_label'    => __( 'Ditt valg nå:', 'lp-missing' ),
            'unavailable_text'  => $unavailable ? __( 'Alternativet du valgte er dessverre utsolgt nå. Velg gjerne noe annet.', 'lp-missing' ) : '',
            'sold_out_text'     => $offered && ! $has_alternatives ? __( 'Alternativene vi foreslo er dessverre utsolgt nå.', 'lp-missing' ) : '',
            'options_title'     => __( 'Hva vil du at vi skal gjøre?', 'lp-missing' ),
            'options'           => $options,
            'qty'               => array(
                'show'  => $has_alternatives && $qty_missing > 1,
                'id'    => 'lp-qty-' . $item_id,
                'name'  => 'lp_qty[' . $item_id . ']',
                'value' => max( 1, min( $qty_missing, $qty_value ) ),
                'max'   => $qty_missing,
                /* translators: %d: highest quantity */
                'label' => sprintf( __( 'Antall du vil ha av alternativet (maks %d)', 'lp-missing' ), $qty_missing ),
                'help'  => __( 'Du kan velge deler av manglende antall nå. Resten blir stående åpen til vi får nytt valg.', 'lp-missing' ),
            ),
            'choice_name'       => 'lp_choice[' . $item_id . ']',
            'error'             => $error,
            'error_id'          => 'lp-err-' . $item_id,
            'readonly'          => $readonly,
        );
    }

    protected static function build_alternative_option( $order, $item, $item_id, $data, $alt_product, $checked ) {
        $qty_missing = max( 1, $data['qty_missing'] );
        $snapshot    = self::get_alternative_snapshot( $order, $item, $data, $alt_product, $qty_missing );
        $unit        = (float) $snapshot['alternative_unit_incl'];
        $delta_unit  = (float) $snapshot['delta_per_unit_incl'];
        $enough      = $alt_product->has_enough_stock( $qty_missing );
        $stock_qty   = $alt_product->managing_stock() ? max( 0, (int) $alt_product->get_stock_quantity() ) : 0;
        $max_qty     = $enough ? $qty_missing : max( 1, min( $qty_missing, $stock_qty ) );

        $per_kg = '';
        $weight = (float) $alt_product->get_weight();
        if ( $weight > 0 ) {
            $kg = (float) wc_get_weight( $weight, 'kg' );
            if ( $kg > 0 ) {
                /* translators: %s: price per kilogram */
                $per_kg = sprintf( __( '%s/kg', 'lp-missing' ), LP_Missing_Util::plain_price( $unit / $kg, $order ) );
            }
        }

        $covers = '';
        if ( $delta_unit >= self::price_threshold() ) {
            $limit = min( $qty_missing, self::MAX_COVERS_FLAGS );
            for ( $q = 1; $q <= $limit; $q++ ) {
                $covers .= LP_Missing_Pricing::store_covers_difference( round( $delta_unit * $q, wc_get_price_decimals() ) ) ? '1' : '0';
            }
        }

        return array(
            'type'       => 'alt',
            'value'      => 'alt:' . $alt_product->get_id(),
            'id'         => 'lp-opt-' . $item_id . '-' . $alt_product->get_id(),
            'label'      => $alt_product->get_name(),
            'image'      => self::get_product_image_url( $alt_product ),
            'link'       => $alt_product->is_visible() ? $alt_product->get_permalink() : '',
            /* translators: %s: product name */
            'link_text'  => sprintf( __( 'Se %s (åpnes i ny fane)', 'lp-missing' ), $alt_product->get_name() ),
            /* translators: %s: unit price */
            'price_text' => sprintf( __( '%s per stk', 'lp-missing' ), LP_Missing_Util::plain_price( $unit, $order ) ),
            'per_kg'     => $per_kg,
            'diff_text'  => self::describe_delta( $order, $delta_unit, $qty_missing ),
            'badge'      => $enough ? '' : __( 'Få igjen', 'lp-missing' ),
            /* translators: %d: units in stock */
            'stock_text' => $enough || ! $alt_product->managing_stock() ? '' : sprintf( __( 'Kun %d igjen på lager', 'lp-missing' ), $stock_qty ),
            'max_qty'    => $max_qty,
            'checked'    => $checked,
            'data'       => array(
                'label'      => $alt_product->get_name(),
                'delta-unit' => wc_format_decimal( $delta_unit, 6 ),
                'covers'     => $covers,
                'max-qty'    => $max_qty,
            ),
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Summary (rendered here for the saved/posted choices; assets/js/portal.js keeps it live while choosing).
    // ---------------------------------------------------------------------------------------------------------------

    public static function get_summary_strings() {
        return array(
            /* translators: %1$s: product name */
            'none'           => __( '%1$s: Ikke valgt ennå.', 'lp-missing' ),
            /* translators: 1: missing product, 2: quantity, 3: alternative, 4: total difference */
            'alt_up_charged' => __( '%1$s: %2$s × %3$s. Mellomlegget på %4$s faktureres i en egen ordre.', 'lp-missing' ),
            /* translators: 1: missing product, 2: quantity, 3: alternative, 4: total difference */
            'alt_up_covered' => __( '%1$s: %2$s × %3$s. Koster %4$s mer, men butikken dekker mellomlegget.', 'lp-missing' ),
            /* translators: 1: missing product, 2: quantity, 3: alternative */
            'alt_same'       => __( '%1$s: %2$s × %3$s. Samme pris som varen du bestilte.', 'lp-missing' ),
            /* translators: 1: missing product, 2: quantity, 3: alternative */
            'alt_down'       => __( '%1$s: %2$s × %3$s. Rimeligere, men ordren beholder opprinnelig pris.', 'lp-missing' ),
            /* translators: %1$s: remaining quantity */
            'partial'        => __( 'Resten (%1$s stk) blir stående åpen til vi får nytt valg.', 'lp-missing' ),
            /* translators: 1: product name, 2: missing quantity */
            'decline'        => __( '%1$s: Nei takk. Du betaler ikke for det som mangler (%2$s stk).', 'lp-missing' ),
            /* translators: 1: product name, 2: missing quantity */
            'delete'         => __( '%1$s: Fjernes fra ordren. Du betaler ikke for det som mangler (%2$s stk).', 'lp-missing' ),
            /* translators: %1$s: total surcharge */
            'total_charged'  => __( 'Mellomlegg som faktureres i en egen ordre: %1$s.', 'lp-missing' ),
            'total_none'     => __( 'Ingen ekstra kostnad for deg.', 'lp-missing' ),
        );
    }

    protected static function fill( $template, $args ) {
        return vsprintf( $template, $args );
    }

    protected static function build_summary( $order, $lines ) {
        $strings = self::get_summary_strings();
        $texts   = array();
        $charged = 0.0;
        foreach ( $lines as $line ) {
            $option = null;
            foreach ( $line['options'] as $candidate ) {
                if ( $candidate['value'] === $line['selected'] ) {
                    $option = $candidate;
                }
            }
            if ( ! $option ) {
                $texts[] = self::fill( $strings['none'], array( $line['name'] ) );
                continue;
            }
            if ( 'decline' === $option['type'] || 'delete' === $option['type'] ) {
                $texts[] = self::fill( $strings[ $option['type'] ], array( $line['name'], $line['qty_missing'] ) );
                continue;
            }
            $qty   = max( 1, min( $line['qty']['value'], $option['max_qty'] ) );
            $delta = (float) $option['data']['delta-unit'];
            $total = round( $delta * $qty, wc_get_price_decimals() );
            $args  = array( $line['name'], $qty, $option['label'], LP_Missing_Util::plain_price( abs( $total ), $order ) );
            if ( $delta >= self::price_threshold() ) {
                if ( LP_Missing_Pricing::store_covers_difference( $total ) ) {
                    $text = self::fill( $strings['alt_up_covered'], $args );
                } else {
                    $text     = self::fill( $strings['alt_up_charged'], $args );
                    $charged += $total;
                }
            } elseif ( $delta <= -self::price_threshold() ) {
                $text = self::fill( $strings['alt_down'], $args );
            } else {
                $text = self::fill( $strings['alt_same'], $args );
            }
            if ( $qty < $line['qty_missing'] ) {
                $text .= ' ' . self::fill( $strings['partial'], array( $line['qty_missing'] - $qty ) );
            }
            $texts[] = $text;
        }
        return array(
            'title' => __( 'Oppsummering', 'lp-missing' ),
            'lines' => $texts,
            'total' => $charged > 0 ? self::fill( $strings['total_charged'], array( LP_Missing_Util::plain_price( $charged, $order ) ) ) : $strings['total_none'],
        );
    }

    /**
     * Settings for assets/js/portal.js (price format, price rules and texts), passed in a data attribute.
     */
    public static function get_script_config( $order ) {
        return array(
            'currency'    => array(
                'symbol'      => html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ), ENT_QUOTES, 'UTF-8' ),
                'format'      => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
                'decimals'    => wc_get_price_decimals(),
                'decimalSep'  => wc_get_price_decimal_separator(),
                'thousandSep' => wc_get_price_thousand_separator(),
            ),
            'priceMode'   => LP_Missing_Settings::get( 'alt_price_handling' ),
            'coversBelow' => (float) LP_Missing_Settings::get( 'store_covers_below' ),
            'i18n'        => self::get_summary_strings(),
        );
    }
}
