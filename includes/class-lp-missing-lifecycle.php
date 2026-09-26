<?php
/**
 * Reacts to line changes: notifications, reminders, escalation and cleanup.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Lifecycle {
    protected static $auto_email_sent = array();

    public static function register() {
        add_action( 'lp_missing_item_updated', array( __CLASS__, 'handle_item_updated' ), 10, 4 );
        add_action( 'lp_missing_send_reminder', array( __CLASS__, 'handle_scheduled_reminder' ), 10, 2 );
        add_action( LP_Missing_Plugin::CLEANUP_HOOK, array( __CLASS__, 'handle_cleanup_order' ) );
        add_action( 'init', array( __CLASS__, 'maybe_schedule_daily_cleanup' ) );
        add_action( LP_Missing_Plugin::DAILY_CLEANUP_HOOK, array( __CLASS__, 'run_daily_cleanup' ) );
    }

    public static function schedule_reminder_for_item( $order, $item_id, $delay_days = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            return;
        }
        $data = LP_Missing_Line::get_item_data( $item );
        if ( LP_Missing_Line::is_line_resolved( $data ) ) {
            return;
        }

        $settings = LP_Missing_Settings::get_settings();
        $delay_days = is_null( $delay_days ) ? $settings['reminder_delay_days'] : absint( $delay_days );
        if ( $delay_days < 1 ) {
            $delay_days = $settings['reminder_delay_days'];
        }

        while ( $scheduled = wp_next_scheduled( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) ) ) {
            wp_unschedule_event( $scheduled, 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
        }

        $timestamp = time() + ( $delay_days * DAY_IN_SECONDS );
        wp_schedule_single_event( $timestamp, 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );

        $data['reminder_scheduled_for'] = $timestamp;
        if ( empty( $data['first_missing_at'] ) ) {
            $data['first_missing_at'] = time();
        }
        $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
        $item->save();
    }

    public static function cancel_reminder_for_item( $order, $item_id ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        while ( $scheduled = wp_next_scheduled( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) ) ) {
            wp_unschedule_event( $scheduled, 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
        }
        $item = $order->get_item( $item_id, false );
        if ( $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            $data['reminder_scheduled_for'] = 0;
            $data['needs_attention'] = false;
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
            $item->save();
        }
    }

    public static function schedule_cleanup_for_order( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings  = LP_Missing_Settings::get_settings();
        $threshold = max( LP_Missing_Plugin::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
        $items     = $order->get_items( 'line_item' );
        $next_time = 0;

        foreach ( $items as $item ) {
            // Lines without plugin data (never missing, already purged, added alternatives) need no cleanup.
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! LP_Missing_Line::is_line_resolved( $data ) ) {
                continue;
            }
            $resolved_at = ! empty( $data['resolved_at'] ) ? $data['resolved_at'] : ( $data['last_updated'] ? $data['last_updated'] : time() );
            $candidate   = $resolved_at + $threshold;
            if ( 0 === $next_time || $candidate < $next_time ) {
                $next_time = $candidate;
            }
        }

        $existing = wp_next_scheduled( LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );

        if ( ! $next_time ) {
            while ( $existing ) {
                wp_unschedule_event( $existing, LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
                $existing = wp_next_scheduled( LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
            }
            return;
        }

        if ( $existing && $existing <= $next_time ) {
            return;
        }

        if ( $existing ) {
            wp_unschedule_event( $existing, LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
        }

        wp_schedule_single_event( $next_time, LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
    }

    public static function maybe_schedule_daily_cleanup() {
        if ( ! wp_next_scheduled( LP_Missing_Plugin::DAILY_CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LP_Missing_Plugin::DAILY_CLEANUP_HOOK );
        }
    }

    public static function purge_missing_data_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }

        $changed = false;

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $item->delete_meta_data( LP_Missing_Plugin::META_KEY );
            $item->save();
            $changed = true;
        }

        wp_clear_scheduled_hook( LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
        // Always drop the order-level flags, also when no line data was left, so the order leaves the cleanup queue.
        $order->delete_meta_data( LP_Missing_Plugin::OPTION_ATTENTION_FLAG );
        $order->delete_meta_data( LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING );
        $order->delete_meta_data( LP_Missing_Plugin::OPTION_HAS_MISSING_DATA );
        $order->save();

        return $changed;
    }

    public static function run_daily_cleanup() {
        $settings  = LP_Missing_Settings::get_settings();
        $threshold = max( LP_Missing_Plugin::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
        $limit     = max( 1, absint( apply_filters( 'lp_missing_daily_cleanup_limit', LP_Missing_Plugin::DAILY_CLEANUP_LIMIT ) ) );
        $cutoff    = time() - $threshold;
        $skipped   = 0;
        $purged    = 0;

        // meta_key/meta_value work for both storages (meta_query is ignored by legacy wc_get_orders()).
        // Purged orders lose the flag and drop out of the result, so the offset only has to step over skipped ones.
        for ( $batch = 0; $batch < 10 && $purged < $limit; $batch++ ) {
            $orders = wc_get_orders( array(
                'type'          => 'shop_order',
                'limit'         => $limit,
                'offset'        => $skipped,
                'return'        => 'objects',
                'orderby'       => 'modified',
                'order'         => 'ASC',
                'meta_key'      => LP_Missing_Plugin::OPTION_HAS_MISSING_DATA,
                'meta_value'    => 'yes',
                'date_modified' => '<' . $cutoff,
            ) );

            if ( empty( $orders ) ) {
                break;
            }

            foreach ( $orders as $order ) {
                if ( LP_Missing_Orders::order_has_open_missing_items( $order ) ) {
                    $skipped++;
                    continue;
                }

                $last_activity = LP_Missing_Orders::get_order_last_activity_timestamp( $order );
                if ( ! $last_activity || ( time() - $last_activity ) < $threshold ) {
                    $skipped++;
                    continue;
                }

                if ( self::purge_missing_data_for_order( $order ) ) {
                    $order->add_order_note( __( 'Missing-item data automatically cleaned up after resolution.', 'lp-missing' ) );
                }
                $purged++;
            }

            if ( count( $orders ) < $limit ) {
                break;
            }
        }
    }

    public static function handle_item_updated( $order, $item_id, $new_data, $old_data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            // The line was removed while applying a decision: drop its reminders and refresh the order-level flags.
            wp_clear_scheduled_hook( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
            LP_Missing_Orders::refresh_order_flags( $order );
            self::schedule_cleanup_for_order( $order );
            return;
        }

        $resolved = LP_Missing_Line::is_line_resolved( $new_data );

        if ( $resolved ) {
            if ( empty( $new_data['resolved_at'] ) ) {
                $new_data['resolved_at'] = time();
                $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new_data );
                $item->save();
            }
            self::cancel_reminder_for_item( $order, $item_id );
            LP_Missing_Orders::refresh_order_flags( $order );
            self::schedule_cleanup_for_order( $order );
            return;
        }

        // The line (again) needs a choice from the customer: newly marked, re-opened, new suggestions after a decline,
        // or quantity left over after a partial apply. Notify once per order per request and start the reminder cycle.
        if ( LP_Missing_Line::is_awaiting_customer( $new_data ) && ! LP_Missing_Line::is_awaiting_customer( $old_data ) ) {
            if ( empty( self::$auto_email_sent[ $order->get_id() ] ) ) {
                LP_Missing_Notifier::send_customer_email( $order );
                self::$auto_email_sent[ $order->get_id() ] = true;
            }
            self::schedule_reminder_for_item( $order, $item_id );
            LP_Missing_Orders::refresh_order_flags( $order );
            return;
        }

        if ( LP_Missing_Line::has_customer_decision( $new_data ) ) {
            self::cancel_reminder_for_item( $order, $item_id );
            LP_Missing_Orders::refresh_order_flags( $order );
            return;
        }

        LP_Missing_Orders::refresh_order_flags( $order );
    }

    public static function handle_scheduled_reminder( $order_id, $item_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            return;
        }
        $data = LP_Missing_Line::get_item_data( $item );

        if ( LP_Missing_Line::is_line_resolved( $data ) ) {
            self::cancel_reminder_for_item( $order, $item_id );
            LP_Missing_Orders::refresh_order_attention_flag( $order );
            return;
        }

        if ( empty( $data['missing'] ) || LP_Missing_Line::has_customer_decision( $data ) || $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
            // Nothing to remind about: the customer already answered and the line waits for staff.
            self::cancel_reminder_for_item( $order, $item_id );
            LP_Missing_Orders::refresh_order_attention_flag( $order );
            return;
        }

        // Lines of one order share a reminder: if one went out for this order within the hour, count it for this line too.
        $recent_key = 'lp_missing_reminded_' . $order->get_id();
        if ( get_transient( $recent_key ) ) {
            $sent = true;
        } else {
            $sent = LP_Missing_Notifier::send_reminder_email( $order, $item_id );
            if ( $sent ) {
                set_transient( $recent_key, 1, HOUR_IN_SECONDS );
            }
        }

        $data['first_missing_at'] = $data['first_missing_at'] ? $data['first_missing_at'] : time();
        if ( $sent ) {
            $data['reminder_count'] = $data['reminder_count'] + 1;
            $data['last_reminder_at'] = time();
        }
        $data['reminder_scheduled_for'] = 0;

        $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
        $item->save();

        // Escalation does not depend on the email going out: an unanswered line that is too old always goes to staff.
        $age_days = $data['first_missing_at'] ? floor( ( time() - $data['first_missing_at'] ) / DAY_IN_SECONDS ) : 0;
        $settings = LP_Missing_Settings::get_settings();
        if ( ! $sent ) {
            LP_Missing_Logger::warning( 'Reminder email could not be sent.', array( 'order_id' => $order->get_id(), 'item_id' => $item_id ) );
        }
        if ( $data['reminder_count'] >= $settings['reminder_max_count'] || $age_days >= $settings['reminder_max_age_days'] ) {
            if ( empty( $data['needs_attention'] ) ) {
                $note = sprintf(
                    __( 'Manual follow-up needed: Item %1$s has %2$d reminder(s) over %3$d day(s).', 'lp-missing' ),
                    $item->get_name(),
                    $data['reminder_count'],
                    max( 0, $age_days )
                );
                $order->add_order_note( $note );
            }
            $data['needs_attention'] = true;
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
            $item->save();
            $order->update_meta_data( LP_Missing_Plugin::OPTION_ATTENTION_FLAG, 'yes' );
            $order->save();
            return;
        }

        self::schedule_reminder_for_item( $order, $item_id );
    }

    public static function handle_cleanup_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $settings  = LP_Missing_Settings::get_settings();
        $threshold = max( LP_Missing_Plugin::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
        $items     = $order->get_items( 'line_item' );
        $next_time = 0;
        $changed   = false;

        foreach ( $items as $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! LP_Missing_Line::is_line_resolved( $data ) ) {
                continue;
            }

            $resolved_at = ! empty( $data['resolved_at'] ) ? $data['resolved_at'] : ( $data['last_updated'] ? $data['last_updated'] : time() );
            $age         = time() - $resolved_at;

            if ( $age >= $threshold ) {
                $item->delete_meta_data( LP_Missing_Plugin::META_KEY );
                $item->save();
                $changed = true;
                continue;
            }

            $candidate = $resolved_at + $threshold;
            if ( 0 === $next_time || $candidate < $next_time ) {
                $next_time = $candidate;
            }
        }

        if ( $changed ) {
            LP_Missing_Orders::refresh_order_attention_flag( $order );
        }

        if ( $next_time ) {
            $existing = wp_next_scheduled( LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
            if ( $existing && $existing > $next_time ) {
                wp_unschedule_event( $existing, LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
            }
        }

        self::schedule_cleanup_for_order( $order );
    }

    /**
     * When the next reminder for this order goes out (0 when none is scheduled).
     * Contract used by the admin screen; the scheduler decides how reminders are stored.
     */
    public static function get_next_reminder_timestamp( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return 0;
        }
        $next = 0;
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $ts = wp_next_scheduled( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
            if ( $ts && ( ! $next || $ts < $next ) ) {
                $next = $ts;
            }
        }
        return (int) $next;
    }
}
