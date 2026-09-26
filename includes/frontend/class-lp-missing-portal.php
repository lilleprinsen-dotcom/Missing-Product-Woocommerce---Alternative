<?php
/**
 * Customer portal (shortcode, magic link verification, customer choices).
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Portal {
    public static function register() {
        add_shortcode( LP_Missing_Plugin::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
        add_filter( 'the_content', array( __CLASS__, 'maybe_inject_portal' ), 1 );
    }

    public static function get_order_for_shortcode( $atts ) {
        $order_id = isset( $atts['order_id'] ) ? absint( $atts['order_id'] ) : 0;
        $email    = isset( $atts['email'] ) ? sanitize_email( $atts['email'] ) : '';
        if ( ! $order_id || ! $email ) {
            return array( null, '' );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return array( null, '' );
        }
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return array( null, '' );
        }
        // Attributes are written by whoever edits the page: only staff or the order's own customer may act through them.
        $user = wp_get_current_user();
        $is_owner = $user && $user->exists() && ( ( $order->get_customer_id() && $order->get_customer_id() === $user->ID ) || strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) );
        if ( ! $is_owner && ! current_user_can( 'edit_shop_orders' ) ) {
            return array( null, '' );
        }
        return array( $order, $email );
    }

    public static function get_order_from_magic_link() {
        $order_id = isset( $_GET['oid'] ) ? absint( $_GET['oid'] ) : 0;
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( ! $order_id || ! $key ) {
            return array( null, '', '' );
        }
        $order = wc_get_order( $order_id );
        // wc_get_order() also returns refunds, which have no billing email.
        if ( ! $order instanceof WC_Order ) {
            return array( null, '', __( 'Lenken er ugyldig. Bruk lenken i den nyeste e-posten fra oss, eller kontakt oss.', 'lp-missing' ) );
        }
        if ( ! LP_Missing_Magic_Link::validate_signature( $order_id, $order->get_billing_email(), $key ) ) {
            return array( null, '', __( 'Lenken er ugyldig. Bruk lenken i den nyeste e-posten fra oss, eller kontakt oss.', 'lp-missing' ) );
        }
        return array( $order, $order->get_billing_email(), '' );
    }

    public static function verify_customer_email( $order, &$error ) {
        $error         = '';
        $billing_email = $order->get_billing_email();
        if ( ! $billing_email ) {
            return false;
        }
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user && strtolower( $user->user_email ) === strtolower( $billing_email ) ) {
                return true;
            }
        }
        // Portal forms carry a signed token after a successful verification, so choices are not bounced back to this step.
        if ( isset( $_POST['lp_missing_verified'] ) && LP_Missing_Magic_Link::validate_verification_token( $order->get_id(), $billing_email, sanitize_text_field( wp_unslash( $_POST['lp_missing_verified'] ) ) ) ) {
            return true;
        }
        if ( isset( $_POST['lp_missing_verify_email'] ) ) {
            $nonce = isset( $_POST['lp_missing_verify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lp_missing_verify_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'lp_missing_verify_' . $order->get_id() ) ) {
                $error = __( 'Sikkerhetssjekk feilet. Prøv igjen.', 'lp-missing' );
                return false;
            }
            $submitted = sanitize_email( wp_unslash( $_POST['lp_missing_verify_email'] ) );
            if ( $submitted && strtolower( $submitted ) === strtolower( $billing_email ) ) {
                return true;
            }
            $error = __( 'E-postadressen stemmer ikke med denne ordren.', 'lp-missing' );
        }
        return false;
    }

    public static function rate_limit_key( $order_id, $email ) {
        return 'lp_missing_rate_' . $order_id . '_' . md5( strtolower( $email ) );
    }

    public static function handle_portal_action( $order, $email ) {
        if ( empty( $_POST['lp_missing_action'] ) || empty( $_POST['lp_missing_nonce'] ) ) {
            return array( 'message' => '', 'status' => '' );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_missing_nonce'] ) ), 'lp_missing_portal_' . $order->get_id() ) ) {
            return array( 'message' => __( 'Sikkerhetssjekk feilet.', 'lp-missing' ), 'status' => 'error' );
        }
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return array( 'message' => __( 'E-postadressen stemmer ikke med denne ordren.', 'lp-missing' ), 'status' => 'error' );
        }
        $key = self::rate_limit_key( $order->get_id(), $email );
        $count = (int) get_transient( $key );
        if ( $count >= 5 ) {
            return array( 'message' => __( 'Du gjorde mange valg på kort tid. Vent litt og prøv igjen.', 'lp-missing' ), 'status' => 'error' );
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

        $action    = sanitize_text_field( wp_unslash( $_POST['lp_missing_action'] ) );
        $item_id   = isset( $_POST['lp_missing_item_id'] ) ? absint( $_POST['lp_missing_item_id'] ) : 0;
        $items     = $order->get_items( 'line_item' );
        $item      = isset( $items[ $item_id ] ) ? $items[ $item_id ] : null;
        if ( ! $item ) {
            return array( 'message' => __( 'Ugyldig vare valgt.', 'lp-missing' ), 'status' => 'error' );
        }

        $existing = LP_Missing_Line::get_item_data( $item );
        if ( empty( $existing['missing'] ) || LP_Missing_Line::is_line_resolved( $existing ) ) {
            return array( 'message' => __( 'Denne varen er ikke markert som manglende.', 'lp-missing' ), 'status' => 'error' );
        }
        if ( ! LP_Missing_Line::is_awaiting_customer( $existing ) ) {
            // Re-submitted form (e.g. page refresh): keep the first decision and its frozen price.
            return array( 'message' => __( 'Valget ditt for denne varen er allerede registrert.', 'lp-missing' ), 'status' => 'success' );
        }

        $new = $existing;
        $new['last_updated'] = time();

        if ( 'accept_alt' === $action ) {
            $alt_id = isset( $_POST['lp_missing_alt_id'] ) ? absint( $_POST['lp_missing_alt_id'] ) : 0;
            $qty_alt = isset( $_POST['lp_missing_alt_qty'] ) ? absint( $_POST['lp_missing_alt_qty'] ) : 0;
            if ( $qty_alt < 1 || $qty_alt > $existing['qty_missing'] ) {
                return array( 'message' => __( 'Ugyldig antall valgt.', 'lp-missing' ), 'status' => 'error' );
            }
            if ( ! in_array( $alt_id, $existing['alternatives'], true ) ) {
                return array( 'message' => __( 'Dette alternativet kan ikke velges.', 'lp-missing' ), 'status' => 'error' );
            }
            $alt_product = wc_get_product( $alt_id );
            if ( ! $alt_product ) {
                return array( 'message' => __( 'Dette alternativet er ikke lenger tilgjengelig.', 'lp-missing' ), 'status' => 'error' );
            }
            $new['status'] = 'alt_pending';
            $new['selected_alt_id'] = $alt_id;
            $new['qty_alt'] = $qty_alt;
            $new['pricing_snapshot'] = LP_Missing_Pricing::get_frozen_pricing_snapshot( $order, $item, $new, $alt_product, $qty_alt );
        } elseif ( 'decline_all' === $action ) {
            $new['status'] = 'declined';
            $new['selected_alt_id'] = 0;
            $new['qty_alt'] = 0;
            $new['pricing_snapshot'] = array();
        } elseif ( 'accept_delete' === $action ) {
            if ( empty( $existing['propose_delete'] ) ) {
                return array( 'message' => __( 'Sletting er ikke tilgjengelig for denne varen.', 'lp-missing' ), 'status' => 'error' );
            }
            $new['status'] = 'delete_pending';
            $new['pricing_snapshot'] = array();
        } else {
            return array( 'message' => __( 'Ukjent handling.', 'lp-missing' ), 'status' => 'error' );
        }

        if ( in_array( $new['status'], array( 'alt_pending', 'delete_pending' ), true ) ) {
            $new['needs_attention'] = false;
            $new['reminder_scheduled_for'] = 0;
            $new['decision_made_at'] = time();
            $new['resolved_at'] = 0;
        }

        if ( ! LP_Missing_Line::is_line_resolved( $new ) ) {
            $new['resolved_at'] = 0;
        }

        if ( serialize( $existing ) !== serialize( $new ) ) {
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new );
            $item->save();
            do_action( 'lp_missing_item_updated', $order, $item_id, $new, $existing );
        }

        return array( 'message' => __( 'Takk! Valget ditt er lagret 💛', 'lp-missing' ), 'status' => 'success' );
    }

    /**
     * The default portal URL is the My Account page, which does not carry the shortcode by itself:
     * show the portal there when a magic link is opened.
     */
    public static function maybe_inject_portal( $content ) {
        if ( is_admin() || empty( $_GET['oid'] ) || empty( $_GET['key'] ) || ! is_main_query() || ! in_the_loop() ) {
            return $content;
        }
        if ( has_shortcode( $content, LP_Missing_Plugin::SHORTCODE ) || ! function_exists( 'wc_get_page_id' ) || ! is_page( wc_get_page_id( 'myaccount' ) ) ) {
            return $content;
        }
        return '[' . LP_Missing_Plugin::SHORTCODE . ']' . "\n\n" . $content;
    }

    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'order_id' => 0,
            'email'    => '',
        ), $atts, LP_Missing_Plugin::SHORTCODE );

        list( $order, $email, $link_error ) = self::get_order_from_magic_link();
        $using_magic   = $order instanceof WC_Order;
        $verify_error  = '';
        $response      = array( 'message' => '', 'status' => '' );

        if ( $link_error ) {
            return '<div class="lp-missing-portal-error">' . esc_html( $link_error ) . '</div>';
        }

        if ( ! $using_magic ) {
            list( $order, $email ) = self::get_order_for_shortcode( $atts );
            if ( ! $order ) {
                return '<div class="lp-missing-portal-error">' . esc_html__( 'Fant ikke ordren, eller e-postadressen stemmer ikke.', 'lp-missing' ) . '</div>';
            }
            $email_verified = true;
        } else {
            $email_verified = self::verify_customer_email( $order, $verify_error );
        }

        if ( $using_magic && ! $email_verified ) {
            ob_start();
            if ( $verify_error ) {
                echo '<div class="lp-missing-portal-error">' . esc_html( $verify_error ) . '</div>';
            }
            echo '<div class="lp-missing-portal-verify">';
            echo '<p>' . esc_html__( 'Bekreft e-postadressen du brukte i kassen for å fortsette.', 'lp-missing' ) . '</p>';
            echo '<form method="post">';
            wp_nonce_field( 'lp_missing_verify_' . $order->get_id(), 'lp_missing_verify_nonce' );
            echo '<label>' . esc_html__( 'E-postadresse', 'lp-missing' ) . ' <input type="email" name="lp_missing_verify_email" required /></label> ';
            echo '<button type="submit">' . esc_html__( 'Fortsett', 'lp-missing' ) . '</button>';
            echo '</form>';
            echo '</div>';
            return ob_get_clean();
        }

        $email    = $order->get_billing_email();
        $verification_token = $using_magic ? LP_Missing_Magic_Link::generate_verification_token( $order->get_id(), $email ) : '';
        $response = self::handle_portal_action( $order, $email );
        $items    = $order->get_items( 'line_item' );
        $missing_items = array();
        $alt_ids = array();
        foreach ( $items as $item_id => $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) ) {
                $missing_items[ $item_id ] = array( 'item' => $item, 'data' => $data );
                if ( ! empty( $data['alternatives'] ) ) {
                    $alt_ids = array_merge( $alt_ids, $data['alternatives'] );
                }
            }
        }
        if ( empty( $missing_items ) ) {
            return '<div class="lp-missing-portal-empty">' . esc_html__( 'Alt er i orden 🎉 Ingen valg venter på deg nå.', 'lp-missing' ) . '</div>';
        }

        $alt_products = array();
        if ( $alt_ids ) {
            $products = wc_get_products( array( 'include' => array_values( array_unique( $alt_ids ) ), 'limit' => -1, 'type' => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ) ) );
            foreach ( $products as $product_obj ) {
                $alt_products[ $product_obj->get_id() ] = $product_obj;
            }
        }

        ob_start();
        if ( ! empty( $response['message'] ) ) {
            $class = 'success' === $response['status'] ? 'lp-message-success' : 'lp-message-error';
            echo '<div class="' . esc_attr( $class ) . '">' . esc_html( $response['message'] ) . '</div>';
        }
        $customer_name = LP_Missing_Util::get_customer_first_name( $order );
        echo '<style>
            .lp-missing-portal{font-family:inherit;display:grid;gap:12px}
            .lp-portal-card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff}
            .lp-portal-muted{color:#6b7280;font-size:14px}
            .lp-pill{display:inline-block;background:#f3f4f6;border-radius:999px;padding:3px 10px;font-size:12px;margin-top:6px}
            .lp-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
            .lp-btn{border:1px solid #d1d5db;background:#111827;color:#fff;border-radius:8px;padding:8px 12px;cursor:pointer}
            .lp-btn--secondary{background:#fff;color:#111827}
            .lp-message-success,.lp-message-error{border-radius:10px;padding:10px 12px;margin-bottom:10px}
            .lp-message-success{background:#ecfdf5;border:1px solid #10b981}
            .lp-message-error{background:#fef2f2;border:1px solid #ef4444}
        </style>';
        echo '<div class="lp-missing-portal">';
        echo '<div class="lp-portal-card"><strong>' . esc_html( sprintf( __( 'Hei %s 👋', 'lp-missing' ), $customer_name ) ) . '</strong><div class="lp-portal-muted">' . esc_html__( 'Vi hjelper deg å velge hva som skal skje med varer som mangler. Prisforskjell fryses når du velger, slik at den ikke endres senere.', 'lp-missing' ) . '</div></div>';
        foreach ( $missing_items as $item_id => $payload ) {
            $item = $payload['item'];
            $data = $payload['data'];
            $product = $item->get_product();
            $product_name = $product ? $product->get_name() : $item->get_name();
            echo '<div class="lp-portal-card lp-portal-item">';
            echo '<strong>' . esc_html( $product_name ) . '</strong>';
            echo '<div class="lp-portal-muted">' . sprintf( esc_html__( 'Bestilt: %1$s stk · Mangler: %2$s stk', 'lp-missing' ), esc_html( $item->get_quantity() ), esc_html( $data['qty_missing'] ) ) . '</div>';
            if ( ! empty( $data['notes'] ) ) {
                echo '<div class="lp-pill">' . esc_html( $data['notes'] ) . '</div>';
            }
            if ( ! empty( $data['alternatives'] ) ) {
                echo '<div style="margin-top:8px;"><em>' . esc_html__( 'Forslag til alternativ:', 'lp-missing' ) . '</em><ul style="margin:6px 0 0; padding-left:18px;">';
                foreach ( $data['alternatives'] as $alt_id ) {
                    $alt_product = isset( $alt_products[ $alt_id ] ) ? $alt_products[ $alt_id ] : wc_get_product( $alt_id );
                    if ( ! $alt_product ) {
                        continue;
                    }
                    $delta = LP_Missing_Pricing::get_alternative_price_delta( $order, $item, $alt_product, $data['qty_missing'] );
                    $delta_text = __( 'Samme pris som original vare', 'lp-missing' );
                    if ( 'up' === $delta['direction'] ) {
                        $delta_text = LP_Missing_Pricing::store_covers_difference( $delta['total_delta_raw'] )
                            ? sprintf( __( 'Koster %1$s mer per stk, men butikken dekker mellomlegget. Du betaler ikke noe ekstra.', 'lp-missing' ), $delta['unit_delta'] )
                            : sprintf( __( 'Koster %1$s mer per stk (%2$s mer for %3$s stk). Mellomlegget faktureres i en egen ordre.', 'lp-missing' ), $delta['unit_delta'], $delta['total_delta'], $data['qty_missing'] );
                    } elseif ( 'down' === $delta['direction'] ) {
                        $delta_text = sprintf( __( 'Rimeligere enn originalen (%1$s mindre per stk). Ordren beholder opprinnelig pris.', 'lp-missing' ), $delta['unit_delta'] );
                    }
                    echo '<li>';
                    echo '<a href="' . esc_url( $alt_product->get_permalink() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $alt_product->get_name() ) . '</a>';
                    $sku = $alt_product->get_sku();
                    if ( $sku ) {
                        echo ' <small>(' . esc_html( $sku ) . ')</small>';
                    }
                    echo '<div class="lp-portal-muted">' . esc_html( $delta_text ) . '</div>';
                    echo '</li>';
                }
                echo '</ul></div>';
            }

            if ( LP_Missing_Line::has_customer_decision( $data ) ) {
                echo '<div style="margin-top:8px;">' . esc_html__( 'Tusen takk! Valget ditt er registrert 💚 Teamet vårt oppdaterer ordren snart.', 'lp-missing' ) . '</div>';
                if ( 'alt_pending' === $data['status'] && ! empty( $data['selected_alt_id'] ) ) {
                    $alt_product = isset( $alt_products[ $data['selected_alt_id'] ] ) ? $alt_products[ $data['selected_alt_id'] ] : wc_get_product( $data['selected_alt_id'] );
                    if ( $alt_product ) {
                        $selected_qty = $data['qty_alt'] ? $data['qty_alt'] : $data['qty_missing'];
                        $delta = LP_Missing_Pricing::get_alternative_price_delta( $order, $item, $alt_product, $selected_qty );
                        echo '<div><em>' . esc_html__( 'Du valgte:', 'lp-missing' ) . '</em> ' . esc_html( $alt_product->get_name() ) . ' &times; ' . esc_html( $selected_qty ) . '</div>';
                        if ( 'up' === $delta['direction'] ) {
                            $locked_text = LP_Missing_Pricing::store_covers_difference( $delta['total_delta_raw'] )
                                ? __( 'Pris låst ved valg: %s mer totalt, som butikken dekker.', 'lp-missing' )
                                : __( 'Pris låst ved valg: %s mer totalt. Mellomlegget faktureres i en egen ordre.', 'lp-missing' );
                            echo '<div class="lp-portal-muted">' . esc_html( sprintf( $locked_text, $delta['total_delta'] ) ) . '</div>';
                        } elseif ( 'down' === $delta['direction'] ) {
                            echo '<div class="lp-portal-muted">' . esc_html( sprintf( __( 'Rimeligere enn originalen (%s mindre totalt). Ordren beholder opprinnelig pris.', 'lp-missing' ), $delta['total_delta'] ) ) . '</div>';
                        } else {
                            echo '<div class="lp-portal-muted">' . esc_html__( 'Pris: ingen forskjell.', 'lp-missing' ) . '</div>';
                        }
                    }
                } elseif ( 'delete_pending' === $data['status'] ) {
                    echo '<div><em>' . esc_html__( 'Du godkjente å fjerne denne varen.', 'lp-missing' ) . '</em></div>';
                }
            } else {
                echo '<form method="post" class="lp-actions">';
                wp_nonce_field( 'lp_missing_portal_' . $order->get_id(), 'lp_missing_nonce' );
                echo '<input type="hidden" name="lp_missing_item_id" value="' . absint( $item_id ) . '" />';
                if ( $verification_token ) {
                    echo '<input type="hidden" name="lp_missing_verified" value="' . esc_attr( $verification_token ) . '" />';
                }

                if ( ! empty( $data['alternatives'] ) ) {
                    echo '<div>';
                    echo '<label>' . esc_html__( 'Velg alternativ:', 'lp-missing' ) . ' ';
                    echo '<select name="lp_missing_alt_id">';
                    foreach ( $data['alternatives'] as $alt_id ) {
                        $alt_product = isset( $alt_products[ $alt_id ] ) ? $alt_products[ $alt_id ] : wc_get_product( $alt_id );
                        if ( ! $alt_product ) {
                            continue;
                        }
                        $delta = LP_Missing_Pricing::get_alternative_price_delta( $order, $item, $alt_product, $data['qty_missing'] );
                        $option_label = $alt_product->get_name();
                        if ( 'up' === $delta['direction'] ) {
                            $option_label .= ' (+' . $delta['unit_delta'] . ' /stk)';
                        } elseif ( 'down' === $delta['direction'] ) {
                            $option_label .= ' (-' . $delta['unit_delta'] . ' /stk)';
                        } else {
                            $option_label .= ' (' . __( 'samme pris', 'lp-missing' ) . ')';
                        }
                        echo '<option value="' . absint( $alt_id ) . '">' . esc_html( $option_label ) . '</option>';
                    }
                    echo '</select></label> ';
                    echo '<label>' . esc_html__( 'Antall', 'lp-missing' ) . ' <input type="number" min="1" max="' . esc_attr( $data['qty_missing'] ) . '" name="lp_missing_alt_qty" value="' . esc_attr( $data['qty_missing'] ) . '" style="width:70px;" /></label> ';
                    echo '<div class="lp-portal-muted">' . esc_html__( 'Du kan velge deler av manglende antall nå. Resten blir stående åpen til vi får nytt valg.', 'lp-missing' ) . '</div>';
                    echo '<button class="lp-btn" type="submit" name="lp_missing_action" value="accept_alt">' . esc_html__( '✅ Velg dette alternativet', 'lp-missing' ) . '</button>';
                    echo '</div>';
                }

                echo '<div>';
                echo '<button class="lp-btn lp-btn--secondary" type="submit" name="lp_missing_action" value="decline_all" formnovalidate>' . esc_html__( '❌ Nei takk til alternativer', 'lp-missing' ) . '</button>';
                echo '</div>';

                if ( ! empty( $data['propose_delete'] ) ) {
                    echo '<div>';
                    echo '<button class="lp-btn lp-btn--secondary" type="submit" name="lp_missing_action" value="accept_delete" formnovalidate>' . esc_html__( '🗑️ Fjern denne varen fra ordren', 'lp-missing' ) . '</button>';
                    echo '</div>';
                }
                echo '</form>';
            }

            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
