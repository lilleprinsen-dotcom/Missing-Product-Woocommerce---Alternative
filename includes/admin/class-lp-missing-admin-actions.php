<?php
/**
 * admin-post handlers (apply decision, send email, revoke customer links) and admin notices.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Admin_Actions {
    public static function register() {
        add_action( 'admin_post_lp_missing_send_email', array( __CLASS__, 'handle_admin_send_email' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
        add_action( 'admin_post_lp_missing_apply_decision', array( __CLASS__, 'handle_apply_decision' ) );
        add_action( 'admin_post_lp_missing_revoke_links', array( __CLASS__, 'handle_revoke_links' ) );
    }

    public static function handle_admin_send_email() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Invalid order.', 'lp-missing' ) );
        }
        if ( ! LP_Missing_Util::current_user_can_edit_order( $order_id ) ) {
            wp_die( esc_html__( 'You do not have permission to send this email.', 'lp-missing' ) );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'lp_missing_send_email_' . $order_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'lp-missing' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            wp_die( esc_html__( 'Order not found.', 'lp-missing' ) );
        }

        $result = 'missing';
        if ( LP_Missing_Orders::order_has_missing_items( $order ) ) {
            $result = LP_Missing_Notifier::send_customer_email( $order ) ? 'sent' : 'failed';
        }
        LP_Missing_Logger::info( 'Staff sent the portal email.', array( 'order_id' => $order_id, 'user_id' => get_current_user_id(), 'result' => $result ) );

        wp_safe_redirect( add_query_arg( array( 'lp_missing_email_sent' => $result ), LP_Missing_Util::get_order_edit_url( $order ) ) );
        exit;
    }

    public static function handle_apply_decision() {
        $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        $item_id  = isset( $_REQUEST['item_id'] ) ? absint( $_REQUEST['item_id'] ) : 0;
        $nonce    = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        $decision = isset( $_REQUEST['decision'] ) && is_string( $_REQUEST['decision'] ) ? sanitize_key( wp_unslash( $_REQUEST['decision'] ) ) : '';

        if ( ! $order_id || ! $item_id || ! wp_verify_nonce( $nonce, self::apply_nonce_action( $order_id, $item_id, $decision ) ) ) {
            wp_die( esc_html__( 'Security check failed.', 'lp-missing' ) );
        }

        if ( ! LP_Missing_Util::current_user_can_edit_order( $order_id ) ) {
            wp_die( esc_html__( 'You do not have permission to apply decisions.', 'lp-missing' ) );
        }

        $apply_type = isset( $_REQUEST['apply_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['apply_type'] ) ) : '';
        $apply_mode = isset( $_REQUEST['apply_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['apply_mode'] ) ) : '';
        $result     = array( 'status' => 'error', 'message' => __( 'Unknown action.', 'lp-missing' ) );

        // One apply per order at a time: a double click (or two tabs) must not add an alternative or a surcharge twice,
        // or recalculate totals from a stale copy of the order.
        if ( ! self::acquire_apply_lock( $order_id ) ) {
            $result = array( 'status' => 'error', 'message' => __( 'This decision is already being applied. Reload the order in a moment.', 'lp-missing' ) );
        } else {
            try {
                // Load after taking the lock so a request that just finished is seen.
                $order = wc_get_order( $order_id );
                $item  = $order instanceof WC_Order ? $order->get_item( $item_id, false ) : false;
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    $result = array( 'status' => 'error', 'message' => __( 'Item not found.', 'lp-missing' ) );
                } elseif ( ! hash_equals( LP_Missing_Line::get_decision_key( LP_Missing_Line::get_item_data( $item ) ), $decision ) ) {
                    // The page was rendered for another decision (the customer changed their choice, or it was applied).
                    $result = array( 'status' => 'error', 'message' => __( 'The customer\'s choice or the line changed after this page was loaded. Nothing was applied; check the current choice and try again.', 'lp-missing' ) );
                } elseif ( 'alternative' === $apply_type ) {
                    $result = LP_Missing_Apply_Service::apply_alternative_decision( $order, $item, $item_id, LP_Missing_Line::get_item_data( $item ), 'add' === $apply_mode ? 'add' : 'replace' );
                } elseif ( 'delete' === $apply_type ) {
                    $result = LP_Missing_Apply_Service::apply_delete_decision( $order, $item, $item_id, LP_Missing_Line::get_item_data( $item ), 'refund' === $apply_mode ? 'refund' : 'reduce' );
                }
            } finally {
                self::release_apply_lock( $order_id );
            }
        }

        LP_Missing_Logger::log(
            'success' === $result['status'] ? 'info' : 'warning',
            'Staff clicked apply.',
            array(
                'order_id' => $order_id,
                'item_id'  => $item_id,
                'type'     => $apply_type,
                'mode'     => $apply_mode,
                'user_id'  => get_current_user_id(),
                'result'   => $result['status'],
            )
        );

        // The message travels in a per-user transient, not in the URL, so a crafted link cannot show staff arbitrary text.
        set_transient( 'lp_missing_notice_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( 'lp_missing_apply', 'success' === $result['status'] ? 'success' : 'error', LP_Missing_Util::get_order_edit_url( $order_id ) ) );
        exit;
    }

    /**
     * Invalidate every customer link issued for the order (e.g. sent to a wrong address).
     */
    public static function handle_revoke_links() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'lp_missing_revoke_links_' . $order_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'lp-missing' ), '', array( 'response' => 403 ) );
        }
        if ( ! LP_Missing_Util::current_user_can_edit_order( $order_id ) ) {
            wp_die( esc_html__( 'You do not have permission to revoke these links.', 'lp-missing' ), '', array( 'response' => 403 ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            wp_die( esc_html__( 'Order not found.', 'lp-missing' ), '', array( 'response' => 404 ) );
        }

        LP_Missing_Magic_Link::revoke_links( $order );
        $user = wp_get_current_user();
        $order->add_order_note(
            sprintf(
                /* translators: %s: staff user name */
                __( 'Customer portal links revoked by %s. Links sent earlier no longer work.', 'lp-missing' ),
                $user && $user->exists() ? $user->display_name : __( 'staff', 'lp-missing' )
            )
        );
        LP_Missing_Logger::info( 'Staff revoked the customer links.', array( 'order_id' => $order_id, 'user_id' => get_current_user_id() ) );

        wp_safe_redirect( add_query_arg( 'lp_missing_links_revoked', '1', LP_Missing_Util::get_order_edit_url( $order ) ) );
        exit;
    }

    /**
     * Nonce action of an apply link: bound to the order, the line and the decision shown on the page.
     */
    public static function apply_nonce_action( $order_id, $item_id, $decision ) {
        return 'lp_missing_apply_' . absint( $order_id ) . '_' . absint( $item_id ) . '_' . $decision;
    }

    public static function acquire_apply_lock( $order_id ) {
        return LP_Missing_Util::acquire_lock( 'lp_missing_applying_' . absint( $order_id ), 2 * MINUTE_IN_SECONDS );
    }

    public static function release_apply_lock( $order_id ) {
        LP_Missing_Util::release_lock( 'lp_missing_applying_' . absint( $order_id ) );
    }

    public static function admin_notices() {
        if ( ! LP_Missing_Util::is_order_screen() ) {
            return;
        }
        $email_status = isset( $_GET['lp_missing_email_sent'] ) ? sanitize_key( wp_unslash( $_GET['lp_missing_email_sent'] ) ) : '';
        $revoked      = isset( $_GET['lp_missing_links_revoked'] );
        $apply_status = isset( $_GET['lp_missing_apply'] ) ? sanitize_key( wp_unslash( $_GET['lp_missing_apply'] ) ) : '';
        $messages     = array();

        if ( $email_status ) {
            if ( 'sent' === $email_status ) {
                $messages[] = array( 'success', __( 'Missing items email sent to the customer.', 'lp-missing' ) );
            } elseif ( 'missing' === $email_status ) {
                $messages[] = array( 'warning', __( 'No missing items were found for this order.', 'lp-missing' ) );
            } elseif ( 'failed' === $email_status ) {
                $messages[] = array( 'error', __( 'The email could not be sent. Please check email settings.', 'lp-missing' ) );
            }
        }

        if ( $revoked ) {
            $messages[] = array( 'success', __( 'Customer links revoked. Send a new portal email to give the customer a working link.', 'lp-missing' ) );
        }

        $notice_key = 'lp_missing_notice_' . get_current_user_id();
        $notice     = $apply_status ? get_transient( $notice_key ) : false;
        if ( is_array( $notice ) && isset( $notice['status'] ) ) {
            delete_transient( $notice_key );
            $success = 'success' === $notice['status'];
            $human   = $success ? __( 'Customer decision applied.', 'lp-missing' ) : __( 'Could not apply the customer decision.', 'lp-missing' );
            if ( ! empty( $notice['message'] ) ) {
                $human .= ' ' . $notice['message'];
            }
            $messages[] = array( $success ? 'success' : 'error', $human );
        }

        foreach ( $messages as $message ) {
            echo '<div class="notice notice-' . esc_attr( $message[0] ) . ' is-dismissible"><p>' . esc_html( $message[1] ) . '</p></div>';
        }
    }
}
