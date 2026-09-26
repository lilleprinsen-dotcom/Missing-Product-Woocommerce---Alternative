<?php
/**
 * Reacts to line changes: customer and staff notifications, the order reminder, escalation, the decision deadline
 * (default action) and cleanup. Jobs are scheduled through LP_Missing_Scheduler (Action Scheduler).
 *
 * Order-level work caused by line changes (customer email, staff email, re-arming the reminder and the deadline) is
 * collected and run once per order: right away for single changes, or when a batch ends (e.g. the order screen saves
 * several lines at once, so the customer gets one email listing all of them).
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Lifecycle {
    /** @var array Orders that got the customer email in this request (order_id => true). */
    protected static $auto_email_sent = array();
    /** @var int Batch nesting depth; queued order work runs when the outermost batch ends. */
    protected static $batch_depth = 0;
    /** @var array order_id => queued order-level work. */
    protected static $queue = array();
    /** @var bool */
    protected static $flushing = false;
    /** @var array item_id => order_id for lines being deleted in the order editor. */
    protected static $deleted_items = array();

    const LOCK_RETRY_DELAY = 300;

    public static function register() {
        add_action( 'lp_missing_item_updated', array( __CLASS__, 'handle_item_updated' ), 10, 4 );
        add_action( LP_Missing_Scheduler::REMINDER_HOOK, array( __CLASS__, 'handle_order_reminder' ) );
        add_action( LP_Missing_Scheduler::DEADLINE_HOOK, array( __CLASS__, 'handle_deadline' ) );
        add_action( LP_Missing_Scheduler::LEGACY_REMINDER_HOOK, array( __CLASS__, 'handle_scheduled_reminder' ), 10, 2 );
        add_action( LP_Missing_Plugin::CLEANUP_HOOK, array( __CLASS__, 'handle_cleanup_order' ) );
        add_action( LP_Missing_Plugin::DAILY_CLEANUP_HOOK, array( __CLASS__, 'run_daily_cleanup' ) );

        // The order screen saves every line in one request: notify and re-schedule once, after all lines are saved.
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'begin_batch' ), 1, 0 );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'end_batch' ), 1000, 0 );
        add_action( 'shutdown', array( __CLASS__, 'flush_on_shutdown' ) );

        // Orders and lines leaving the flow outside the plugin (LP_Missing_Stock closes the lines at priority 10).
        foreach ( array( 'cancelled', 'refunded', 'failed' ) as $status ) {
            add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'handle_order_status_change' ), 20, 2 );
        }
        add_action( 'woocommerce_trash_order', array( __CLASS__, 'handle_order_status_change' ), 20 );
        add_action( 'woocommerce_untrash_order', array( __CLASS__, 'handle_order_status_change' ), 20 );
        add_action( 'woocommerce_delete_order', array( __CLASS__, 'handle_order_deleted' ), 20 );
        add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'remember_deleted_item' ), 20 );
        add_action( 'woocommerce_delete_order_item', array( __CLASS__, 'handle_order_item_deleted' ), 20 );
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Batching of order-level work.
     * --------------------------------------------------------------------------------------------------------- */

    public static function begin_batch() {
        self::$batch_depth++;
    }

    public static function end_batch() {
        self::$batch_depth = max( 0, self::$batch_depth - 1 );
        if ( 0 === self::$batch_depth ) {
            self::flush();
        }
    }

    public static function flush_on_shutdown() {
        self::$batch_depth = 0;
        self::flush();
    }

    /**
     * Queue order-level work: 'email' (customer email), 'reset_reminder' (restart the reminder cycle), 'cleanup'
     * (re-plan the cleanup job) and 'staff' (item_id => array( 'new' => data, 'old' => data ) decisions to report).
     * The reminder and deadline jobs are always re-synced.
     */
    protected static function queue( $order, $work = array() ) {
        $order_id = $order->get_id();
        if ( ! isset( self::$queue[ $order_id ] ) ) {
            self::$queue[ $order_id ] = array(
                'email'          => false,
                'reset_reminder' => false,
                'cleanup'        => false,
                'staff'          => array(),
            );
        }
        self::$queue[ $order_id ]['order'] = $order;
        foreach ( array( 'email', 'reset_reminder', 'cleanup' ) as $flag ) {
            if ( ! empty( $work[ $flag ] ) ) {
                self::$queue[ $order_id ][ $flag ] = true;
            }
        }
        if ( ! empty( $work['staff'] ) ) {
            self::$queue[ $order_id ]['staff'] = array_replace( self::$queue[ $order_id ]['staff'], $work['staff'] );
        }
        if ( 0 === self::$batch_depth ) {
            self::flush();
        }
    }

    public static function flush() {
        if ( self::$flushing ) {
            return;
        }
        self::$flushing = true;
        try {
            $guard = 0;
            while ( self::$queue && $guard++ < 1000 ) {
                reset( self::$queue );
                $order_id = key( self::$queue );
                $work     = self::$queue[ $order_id ];
                unset( self::$queue[ $order_id ] );
                self::run_order_work( $work['order'], $work );
            }
        } finally {
            self::$flushing = false;
        }
    }

    protected static function run_order_work( $order, $work ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        if ( $work['staff'] ) {
            LP_Missing_Notifier::send_staff_decision_email( $order, $work['staff'] );
        }
        if ( $work['email'] && empty( self::$auto_email_sent[ $order->get_id() ] ) ) {
            self::$auto_email_sent[ $order->get_id() ] = true;
            LP_Missing_Notifier::send_customer_email( $order );
        }
        self::sync_order_schedule( $order, $work['reset_reminder'] );
        if ( $work['cleanup'] ) {
            self::schedule_cleanup_for_order( $order );
        }
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Line changes.
     * --------------------------------------------------------------------------------------------------------- */

    public static function handle_item_updated( $order, $item_id, $new_data, $old_data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $new_data = wp_parse_args( (array) $new_data, LP_Missing_Line::default_item_data() );
        $old_data = wp_parse_args( (array) $old_data, LP_Missing_Line::default_item_data() );
        $item     = $order->get_item( $item_id, false );

        if ( ! $item ) {
            // The line was removed while applying a decision: re-plan the order's jobs and refresh its flags.
            self::clear_legacy_reminders( $order->get_id(), $item_id );
            LP_Missing_Orders::refresh_order_flags( $order );
            self::queue( $order, array( 'cleanup' => true ) );
            return;
        }

        if ( LP_Missing_Line::is_line_resolved( $new_data ) ) {
            self::update_line(
                $item,
                function( $data ) {
                    $data['resolved_at']            = $data['resolved_at'] ? $data['resolved_at'] : time();
                    $data['reminder_scheduled_for'] = 0;
                    $data['needs_attention']        = false;
                    return $data;
                }
            );
            self::clear_legacy_reminders( $order->get_id(), $item_id );
            LP_Missing_Orders::refresh_order_flags( $order );
            self::queue( $order, array( 'cleanup' => true ) );
            return;
        }

        // The line (again) needs a choice from the customer: newly marked, re-opened, new suggestions after a decline,
        // or quantity left over after a partial apply. It gets a deadline, and the customer an email (once per order).
        if ( LP_Missing_Line::is_awaiting_customer( $new_data ) && ! LP_Missing_Line::is_awaiting_customer( $old_data ) ) {
            $now = time();
            self::update_line(
                $item,
                function( $data ) use ( $now ) {
                    $data['first_missing_at']      = $data['first_missing_at'] ? $data['first_missing_at'] : $now;
                    $data['deadline_at']           = LP_Missing_Deadline::enabled() ? LP_Missing_Deadline::calculate( $now ) : 0;
                    $data['auto_action_failed_at'] = 0;
                    return $data;
                }
            );
            LP_Missing_Orders::refresh_order_flags( $order );
            self::queue(
                $order,
                array(
                    'email'          => true,
                    'reset_reminder' => true,
                )
            );
            return;
        }

        if ( LP_Missing_Line::has_customer_decision( $new_data ) ) {
            // The customer answered: no more reminders or deadline for this line; staff take over.
            self::update_line(
                $item,
                function( $data ) {
                    $data['reminder_scheduled_for'] = 0;
                    $data['needs_attention']        = false;
                    return $data;
                }
            );
            self::clear_legacy_reminders( $order->get_id(), $item_id );
            $work = array();
            if ( self::is_new_decision( $new_data, $old_data ) ) {
                $work['staff'] = array(
                    $item_id => array(
                        'new' => $new_data,
                        'old' => $old_data,
                    ),
                );
                LP_Missing_Logger::info(
                    'Customer decision received.',
                    array(
                        'order_id' => $order->get_id(),
                        'item_id'  => absint( $item_id ),
                        'status'   => $new_data['status'],
                        'previous' => $old_data['status'],
                    )
                );
            }
            LP_Missing_Orders::refresh_order_flags( $order );
            self::queue( $order, $work );
            return;
        }

        LP_Missing_Orders::refresh_order_flags( $order );
        self::queue( $order );
    }

    /**
     * Whether a change moves a line into a customer decision, or changes the decision (other alternative/quantity).
     */
    public static function is_new_decision( $new_data, $old_data ) {
        if ( ! in_array( $new_data['status'], array( 'alt_pending', 'delete_pending', 'declined' ), true ) ) {
            return false;
        }
        if ( $new_data['status'] !== $old_data['status'] ) {
            return true;
        }
        return 'alt_pending' === $new_data['status']
            && ( absint( $new_data['selected_alt_id'] ) !== absint( $old_data['selected_alt_id'] ) || absint( $new_data['qty_alt'] ) !== absint( $old_data['qty_alt'] ) );
    }

    /**
     * Change a line's plugin data through $callback( $data ) and save it when something changed.
     */
    protected static function update_line( $item, $callback ) {
        $data = LP_Missing_Line::get_item_data( $item );
        if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
            return $data;
        }
        $new = call_user_func( $callback, $data );
        if ( serialize( $new ) !== serialize( $data ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new );
            $item->save();
        }
        return $new;
    }

    public static function order_is_closed( $order ) {
        return $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) );
    }

    /**
     * Lines waiting for the customer: item_id => array( item, data ).
     */
    protected static function get_waiting_lines( $order ) {
        $waiting = array();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( LP_Missing_Line::is_awaiting_customer( $data ) ) {
                $waiting[ $item_id ] = array( $item, $data );
            }
        }
        return $waiting;
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Order schedule: one reminder job and one deadline job per order.
     * --------------------------------------------------------------------------------------------------------- */

    /**
     * Bring the order's reminder and deadline jobs in line with its lines.
     *
     * @param WC_Order|int $order
     * @param bool         $reset_reminder Restart the reminder cycle (the customer was just contacted).
     */
    public static function sync_order_schedule( $order, $reset_reminder = false ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $args    = array( $order->get_id() );
        $waiting = self::order_is_closed( $order ) ? array() : self::get_waiting_lines( $order );

        // Reminder: when the customer was last contacted + spacing, inside the reminder window.
        $reminder_at = 0;
        if ( $waiting ) {
            $reminder_at = $reset_reminder ? 0 : LP_Missing_Scheduler::next( LP_Missing_Scheduler::REMINDER_HOOK, $args );
            if ( ! $reminder_at ) {
                $reminder_at = self::get_reminder_time();
            }
            $remindable = false;
            foreach ( $waiting as $line ) {
                $remindable = $remindable || self::is_remindable( $line[1], $reminder_at );
            }
            if ( ! $remindable ) {
                $reminder_at = 0;
            }
        }
        if ( $reminder_at ) {
            LP_Missing_Scheduler::schedule_single( $reminder_at, LP_Missing_Scheduler::REMINDER_HOOK, $args );
        } else {
            LP_Missing_Scheduler::unschedule( LP_Missing_Scheduler::REMINDER_HOOK, $args );
        }

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $value = isset( $waiting[ $item_id ] ) && $reminder_at && self::is_remindable( $waiting[ $item_id ][1], $reminder_at ) ? $reminder_at : 0;
            self::update_line(
                $item,
                function( $data ) use ( $value ) {
                    $data['reminder_scheduled_for'] = $value;
                    return $data;
                }
            );
        }

        // Deadline: the earliest deadline among the waiting lines.
        $deadline = self::get_deadline_for_lines( $waiting );
        if ( $deadline ) {
            LP_Missing_Scheduler::schedule_single( $deadline, LP_Missing_Scheduler::DEADLINE_HOOK, $args );
        } else {
            LP_Missing_Scheduler::unschedule( LP_Missing_Scheduler::DEADLINE_HOOK, $args );
        }
    }

    /**
     * A waiting line gets reminders until it is escalated, and not once its deadline has passed.
     */
    protected static function is_remindable( $data, $at ) {
        if ( ! empty( $data['needs_attention'] ) ) {
            return false;
        }
        return ! LP_Missing_Deadline::enabled() || empty( $data['deadline_at'] ) || $data['deadline_at'] > $at;
    }

    protected static function get_deadline_for_lines( $waiting ) {
        if ( ! LP_Missing_Deadline::enabled() ) {
            return 0;
        }
        $deadline = 0;
        foreach ( $waiting as $line ) {
            $data = $line[1];
            if ( empty( $data['deadline_at'] ) || ! empty( $data['auto_action_failed_at'] ) ) {
                continue;
            }
            if ( ! $deadline || $data['deadline_at'] < $deadline ) {
                $deadline = (int) $data['deadline_at'];
            }
        }
        return $deadline;
    }

    /**
     * Earliest deadline the customer was given for the lines still waiting (0 without an enforced deadline).
     * Only deadlines stored on the lines are enforced; they are set when a line starts waiting.
     */
    public static function get_order_deadline( $order ) {
        if ( ! $order instanceof WC_Order || self::order_is_closed( $order ) ) {
            return 0;
        }
        return self::get_deadline_for_lines( self::get_waiting_lines( $order ) );
    }

    /**
     * When the next reminder for this order goes out (0 when none is scheduled).
     * Contract used by the admin screen.
     */
    public static function get_next_reminder_timestamp( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return 0;
        }
        $next = LP_Missing_Scheduler::next( LP_Missing_Scheduler::REMINDER_HOOK, array( $order->get_id() ) );
        if ( ! $next ) {
            // Per-line WP-Cron reminder of an older version that was not migrated yet.
            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                $ts = wp_next_scheduled( LP_Missing_Scheduler::LEGACY_REMINDER_HOOK, array( $order->get_id(), $item_id ) );
                if ( $ts && ( ! $next || $ts < $next ) ) {
                    $next = $ts;
                }
            }
        }
        return (int) $next;
    }

    /**
     * Next reminder time: $from (default now) + reminder spacing, moved into the reminder window.
     */
    public static function get_reminder_time( $from = 0 ) {
        $from  = $from ? (int) $from : time();
        $delay = max( 1, absint( LP_Missing_Settings::get( 'reminder_delay_days' ) ) );
        $time  = self::push_into_reminder_window( $from + $delay * DAY_IN_SECONDS );
        /**
         * Filter when the next reminder for an order is sent.
         *
         * @param int $time Unix time (already inside the reminder window).
         * @param int $from When the customer was last contacted.
         */
        return (int) apply_filters( 'lp_missing_next_reminder_time', $time, $from );
    }

    /**
     * Move a time into the reminder window (site time zone): before the window opens -> when it opens that day;
     * at or after it closes -> when it opens the next day.
     */
    public static function push_into_reminder_window( $timestamp ) {
        $start = absint( LP_Missing_Settings::get( 'reminder_window_start' ) );
        $end   = absint( LP_Missing_Settings::get( 'reminder_window_end' ) );
        if ( ( 0 === $start && $end >= 24 ) || $end <= $start ) {
            return (int) $timestamp;
        }
        $local = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( wp_timezone() );
        $hour  = (int) $local->format( 'G' );
        if ( $hour < $start ) {
            $local = $local->setTime( $start, 0 );
        } elseif ( $hour >= $end ) {
            $local = $local->modify( '+1 day' )->setTime( $start, 0 );
        }
        return $local->getTimestamp();
    }

    public static function is_in_reminder_window( $timestamp ) {
        return self::push_into_reminder_window( $timestamp ) === (int) $timestamp;
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Reminders.
     * --------------------------------------------------------------------------------------------------------- */

    /**
     * Job: the order's reminder is due.
     */
    public static function handle_order_reminder( $order_id ) {
        $order_id = absint( $order_id );
        $order    = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            LP_Missing_Scheduler::unschedule( LP_Missing_Scheduler::REMINDER_HOOK, array( $order_id ) );
            return;
        }
        $now = time();
        if ( ! self::order_is_closed( $order ) && ! self::is_in_reminder_window( $now ) ) {
            // The queue ran outside the reminder window (e.g. late): send when the window opens again.
            LP_Missing_Scheduler::schedule_single( self::push_into_reminder_window( $now ), LP_Missing_Scheduler::REMINDER_HOOK, array( $order_id ) );
            self::sync_order_schedule( $order );
            return;
        }
        self::send_order_reminder( $order );
    }

    /**
     * A per-line WP-Cron reminder of a version before 1.2 fired: handle it as the order's reminder.
     */
    public static function handle_scheduled_reminder( $order_id, $item_id = 0 ) {
        self::clear_legacy_reminders( $order_id );
        self::handle_order_reminder( $order_id );
    }

    /**
     * Send the order's reminder now (one email listing every line still waiting), count it on each of those lines and
     * escalate lines that got too many reminders or waited too long. Escalation does not depend on the email going out.
     *
     * @return bool Whether the reminder email was sent.
     */
    public static function send_order_reminder( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        $waiting = self::order_is_closed( $order ) ? array() : self::get_waiting_lines( $order );
        if ( ! $waiting ) {
            LP_Missing_Orders::refresh_order_flags( $order );
            self::sync_order_schedule( $order );
            return false;
        }

        $now = time();
        foreach ( $waiting as $line ) {
            if ( $line[1]['last_reminder_at'] > $now - HOUR_IN_SECONDS ) {
                // Already reminded within the hour (e.g. several old per-line events firing together).
                self::sync_order_schedule( $order, true );
                return false;
            }
        }

        $sent = LP_Missing_Notifier::send_reminder_email( $order );

        $settings = LP_Missing_Settings::get_settings();
        foreach ( $waiting as $item_id => $line ) {
            $item = $line[0];
            $data = LP_Missing_Line::get_item_data( $item );
            $data['first_missing_at'] = $data['first_missing_at'] ? $data['first_missing_at'] : $now;
            if ( $sent ) {
                $data['reminder_count']   = $data['reminder_count'] + 1;
                $data['last_reminder_at'] = $now;
            }
            $data['reminder_scheduled_for'] = 0;

            $age_days = (int) floor( max( 0, $now - $data['first_missing_at'] ) / DAY_IN_SECONDS );
            if ( empty( $data['needs_attention'] ) && ( $data['reminder_count'] >= $settings['reminder_max_count'] || $age_days >= $settings['reminder_max_age_days'] ) ) {
                $data['needs_attention'] = true;
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: product name, 2: number of reminders, 3: days */
                        __( 'Manual follow-up needed: Item %1$s has %2$d reminder(s) over %3$d day(s).', 'lp-missing' ),
                        $item->get_name(),
                        $data['reminder_count'],
                        $age_days
                    )
                );
                LP_Missing_Logger::warning(
                    'Line escalated to staff.',
                    array(
                        'order_id'  => $order->get_id(),
                        'item_id'   => absint( $item_id ),
                        'reminders' => $data['reminder_count'],
                        'age_days'  => $age_days,
                    )
                );
            }
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
            $item->save();
        }

        LP_Missing_Orders::refresh_order_flags( $order );
        self::sync_order_schedule( $order, true );
        return $sent;
    }

    /**
     * Backwards compatible: restart the order's reminder cycle (reminders are per order now).
     */
    public static function schedule_reminder_for_item( $order, $item_id, $delay_days = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        $item  = $order instanceof WC_Order ? $order->get_item( $item_id, false ) : null;
        if ( ! $item ) {
            return;
        }
        self::update_line(
            $item,
            function( $data ) {
                $data['first_missing_at'] = $data['first_missing_at'] ? $data['first_missing_at'] : time();
                return $data;
            }
        );
        self::sync_order_schedule( $order, true );
    }

    /**
     * Backwards compatible: stop reminding about one line (the order's reminder continues for other waiting lines).
     */
    public static function cancel_reminder_for_item( $order, $item_id ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        self::clear_legacy_reminders( $order->get_id(), $item_id );
        $item = $order->get_item( $item_id, false );
        if ( $item ) {
            self::update_line(
                $item,
                function( $data ) {
                    $data['reminder_scheduled_for'] = 0;
                    $data['needs_attention']        = false;
                    return $data;
                }
            );
        }
        self::sync_order_schedule( $order );
    }

    /**
     * Drop per-line WP-Cron reminders of older versions for an order (all lines, or one).
     */
    protected static function clear_legacy_reminders( $order_id, $item_id = 0 ) {
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) ) {
            return;
        }
        foreach ( $crons as $timestamp => $hooks ) {
            if ( empty( $hooks[ LP_Missing_Scheduler::LEGACY_REMINDER_HOOK ] ) || ! is_array( $hooks[ LP_Missing_Scheduler::LEGACY_REMINDER_HOOK ] ) ) {
                continue;
            }
            foreach ( $hooks[ LP_Missing_Scheduler::LEGACY_REMINDER_HOOK ] as $event ) {
                $args = isset( $event['args'] ) ? (array) $event['args'] : array();
                if ( isset( $args[0] ) && absint( $args[0] ) === absint( $order_id ) && ( ! $item_id || ( isset( $args[1] ) && absint( $args[1] ) === absint( $item_id ) ) ) ) {
                    wp_unschedule_event( $timestamp, LP_Missing_Scheduler::LEGACY_REMINDER_HOOK, $args );
                }
            }
        }
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Decision deadline (default action).
     * --------------------------------------------------------------------------------------------------------- */

    /**
     * Job: a line of the order reached its decision deadline. Applies the configured action to every line still
     * waiting whose deadline passed, then notifies staff and re-arms the job for the remaining lines.
     */
    public static function handle_deadline( $order_id ) {
        $order_id = absint( $order_id );
        $order    = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            LP_Missing_Scheduler::unschedule( LP_Missing_Scheduler::DEADLINE_HOOK, array( $order_id ) );
            return;
        }
        if ( ! LP_Missing_Deadline::enabled() || self::order_is_closed( $order ) ) {
            self::sync_order_schedule( $order );
            return;
        }

        self::begin_batch();
        try {
            $outcome = LP_Missing_Apply_Service::apply_due_deadline_actions( $order_id );
        } finally {
            self::end_batch();
        }

        if ( 'locked' === $outcome['status'] ) {
            // Staff are applying a decision on this order right now: try again shortly.
            LP_Missing_Scheduler::schedule_single( time() + self::LOCK_RETRY_DELAY, LP_Missing_Scheduler::DEADLINE_HOOK, array( $order_id ) );
            LP_Missing_Logger::info( 'Deadline action postponed: another apply holds the order lock.', array( 'order_id' => $order_id ) );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        if ( $outcome['results'] ) {
            LP_Missing_Notifier::send_staff_deadline_email( $order, $outcome['results'] );
        }
        self::sync_order_schedule( $order );
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Orders and lines leaving the flow.
     * --------------------------------------------------------------------------------------------------------- */

    public static function handle_order_status_change( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( $order instanceof WC_Order ) {
            self::queue( $order );
        }
    }

    public static function handle_order_deleted( $order_id ) {
        $args = array( absint( $order_id ) );
        foreach ( array( LP_Missing_Scheduler::REMINDER_HOOK, LP_Missing_Scheduler::DEADLINE_HOOK, LP_Missing_Plugin::CLEANUP_HOOK ) as $hook ) {
            LP_Missing_Scheduler::unschedule( $hook, $args );
        }
        self::clear_legacy_reminders( $order_id );
        unset( self::$queue[ absint( $order_id ) ] );
    }

    public static function remember_deleted_item( $item_id ) {
        $item = WC_Order_Factory::get_order_item( $item_id );
        if ( $item instanceof WC_Order_Item_Product && $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
            self::$deleted_items[ $item_id ] = $item->get_order_id();
        }
    }

    public static function handle_order_item_deleted( $item_id ) {
        if ( empty( self::$deleted_items[ $item_id ] ) ) {
            return;
        }
        $order_id = self::$deleted_items[ $item_id ];
        unset( self::$deleted_items[ $item_id ] );
        self::clear_legacy_reminders( $order_id, $item_id );
        $order = wc_get_order( $order_id );
        if ( $order instanceof WC_Order ) {
            self::queue( $order, array( 'cleanup' => true ) );
        }
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Cleanup.
     * --------------------------------------------------------------------------------------------------------- */

    protected static function get_cleanup_threshold() {
        $settings = LP_Missing_Settings::get_settings();
        return max( LP_Missing_Plugin::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
    }

    protected static function get_resolved_at( $data ) {
        if ( ! empty( $data['resolved_at'] ) ) {
            return $data['resolved_at'];
        }
        return $data['last_updated'] ? $data['last_updated'] : time();
    }

    public static function schedule_cleanup_for_order( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $threshold = self::get_cleanup_threshold();
        $next_time = 0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            // Lines without plugin data (never missing, already purged, added alternatives) need no cleanup.
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! LP_Missing_Line::is_line_resolved( $data ) ) {
                continue;
            }
            $candidate = self::get_resolved_at( $data ) + $threshold;
            if ( 0 === $next_time || $candidate < $next_time ) {
                $next_time = $candidate;
            }
        }

        $args = array( $order->get_id() );
        if ( ! $next_time ) {
            LP_Missing_Scheduler::unschedule( LP_Missing_Plugin::CLEANUP_HOOK, $args );
            return;
        }
        $existing = LP_Missing_Scheduler::next( LP_Missing_Plugin::CLEANUP_HOOK, $args );
        if ( $existing && $existing <= $next_time ) {
            return;
        }
        LP_Missing_Scheduler::schedule_single( $next_time, LP_Missing_Plugin::CLEANUP_HOOK, $args );
    }

    /**
     * Backwards compatible: the scheduler keeps the daily cleanup scheduled.
     */
    public static function maybe_schedule_daily_cleanup() {
        LP_Missing_Scheduler::ensure_daily_cleanup();
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

        LP_Missing_Scheduler::unschedule( LP_Missing_Plugin::CLEANUP_HOOK, array( $order->get_id() ) );
        // Always drop the order-level flags, also when no line data was left, so the order leaves the cleanup queue.
        $order->delete_meta_data( LP_Missing_Plugin::OPTION_ATTENTION_FLAG );
        $order->delete_meta_data( LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING );
        $order->delete_meta_data( LP_Missing_Plugin::OPTION_HAS_MISSING_DATA );
        $order->save();

        return $changed;
    }

    public static function run_daily_cleanup() {
        $threshold = self::get_cleanup_threshold();
        $limit     = max( 1, absint( apply_filters( 'lp_missing_daily_cleanup_limit', LP_Missing_Plugin::DAILY_CLEANUP_LIMIT ) ) );
        $cutoff    = time() - $threshold;
        $skipped   = 0;
        $purged    = 0;

        // meta_key/meta_value work for both storages (meta_query is ignored by legacy wc_get_orders()).
        // Purged orders lose the flag and drop out of the result, so the offset only has to step over skipped ones.
        for ( $batch = 0; $batch < 10 && $purged < $limit; $batch++ ) {
            $orders = wc_get_orders(
                array(
                    'type'          => 'shop_order',
                    'limit'         => $limit,
                    'offset'        => $skipped,
                    'return'        => 'objects',
                    'orderby'       => 'modified',
                    'order'         => 'ASC',
                    'meta_key'      => LP_Missing_Plugin::OPTION_HAS_MISSING_DATA, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
                    'meta_value'    => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
                    'date_modified' => '<' . $cutoff,
                )
            );

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

        self::resync_open_orders();
    }

    /**
     * Safety net: re-arm missing reminder/deadline jobs of open cases (e.g. after the plugin was deactivated, which
     * cancels all jobs). Jobs that exist are left alone. Newest cases first; each run continues where the last one
     * stopped, so every open case is covered over a few days.
     */
    public static function resync_open_orders() {
        $limit  = max( 1, absint( apply_filters( 'lp_missing_resync_limit', 100 ) ) );
        $offset = absint( get_option( 'lp_missing_resync_offset', 0 ) );
        $ids    = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'limit'      => $limit,
                'offset'     => $offset,
                'return'     => 'ids',
                'orderby'    => 'date',
                'order'      => 'DESC',
                'meta_key'   => LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
                'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
            )
        );
        $ids = (array) $ids;
        update_option( 'lp_missing_resync_offset', count( $ids ) < $limit ? 0 : $offset + $limit, false );
        foreach ( $ids as $order_id ) {
            self::sync_order_schedule( $order_id );
        }
    }

    public static function handle_cleanup_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $threshold = self::get_cleanup_threshold();
        $changed   = false;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( LP_Missing_Line::is_line_resolved( $data ) && time() - self::get_resolved_at( $data ) >= $threshold ) {
                $item->delete_meta_data( LP_Missing_Plugin::META_KEY );
                $item->save();
                $changed = true;
            }
        }

        if ( $changed ) {
            LP_Missing_Orders::refresh_order_attention_flag( $order );
        }
        // Lines resolved later get their own (later) cleanup.
        self::schedule_cleanup_for_order( $order );
    }
}
