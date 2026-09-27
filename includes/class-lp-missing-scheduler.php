<?php
/**
 * Background jobs: Action Scheduler (bundled with WooCommerce) in group "lp-missing", with WP-Cron only as a fallback
 * when Action Scheduler is not available.
 *
 * Jobs (all args are integers):
 * - lp_missing_order_reminder [order_id]  One reminder per order, listing every line still waiting for the customer.
 * - lp_missing_order_deadline [order_id]  Default action for lines whose decision deadline passed.
 * - lp_missing_cleanup_order  [order_id]  Purge resolved missing-item data after the retention period.
 * - lp_missing_daily_cleanup              Daily sweep (recurring).
 * - lp_missing_send_reminder  [order_id, item_id]  Per-line reminder of versions before 1.2 (WP-Cron). Never scheduled
 *   any more, but still handled when an old event fires, and migrated by the 1.2 upgrade.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Scheduler {
    const GROUP                = 'lp-missing';
    const REMINDER_HOOK        = 'lp_missing_order_reminder';
    const DEADLINE_HOOK        = 'lp_missing_order_deadline';
    const CUSTOMER_NOTE_HOOK   = 'lp_missing_send_customer_notes';
    const LEGACY_REMINDER_HOOK = 'lp_missing_send_reminder';
    const MAINTENANCE_OPTION   = 'lp_missing_scheduler_checked';
    const MAINTENANCE_INTERVAL = 43200; // 12 hours.

    public static function register() {
        // Cheap, throttled self-check: daily cleanup scheduled, no WP-Cron leftovers once Action Scheduler is in use.
        add_action( 'init', array( __CLASS__, 'maybe_maintain' ), 30 );
        // Action Scheduler (3.9.2+) asks plugins once a day to make sure their recurring actions exist.
        add_action( 'action_scheduler_ensure_recurring_actions', array( __CLASS__, 'ensure_daily_cleanup' ) );
    }

    /**
     * Every hook the plugin schedules (or scheduled in earlier versions).
     */
    public static function hooks() {
        return array(
            self::REMINDER_HOOK,
            self::DEADLINE_HOOK,
            self::CUSTOMER_NOTE_HOOK,
            LP_Missing_Plugin::CLEANUP_HOOK,
            LP_Missing_Plugin::DAILY_CLEANUP_HOOK,
            self::LEGACY_REMINDER_HOOK,
        );
    }

    public static function action_scheduler_available() {
        return function_exists( 'as_schedule_single_action' )
            && function_exists( 'as_schedule_recurring_action' )
            && function_exists( 'as_unschedule_all_actions' )
            && function_exists( 'as_get_scheduled_actions' )
            && function_exists( 'as_has_scheduled_action' )
            && class_exists( 'ActionScheduler' );
    }

    /**
     * Whether jobs go to Action Scheduler (true) or to the WP-Cron fallback (false).
     */
    public static function uses_action_scheduler() {
        /**
         * Filter whether the plugin schedules its jobs with Action Scheduler. Returning false forces the WP-Cron fallback.
         *
         * @param bool $use Whether Action Scheduler is used (only honoured when it is available).
         */
        return self::action_scheduler_available() && (bool) apply_filters( 'lp_missing_use_action_scheduler', true );
    }

    protected static function action_scheduler_ready() {
        return ActionScheduler::is_initialized();
    }

    protected static function normalize_args( $args ) {
        return array_values( array_map( 'intval', (array) $args ) );
    }

    /**
     * Schedule a single job, replacing any pending instance with the same hook and args (one pending instance each).
     *
     * @return bool Whether a job is scheduled for $timestamp.
     */
    public static function schedule_single( $timestamp, $hook, $args = array() ) {
        $timestamp = (int) $timestamp;
        $args      = self::normalize_args( $args );

        if ( ! self::uses_action_scheduler() ) {
            if ( wp_next_scheduled( $hook, $args ) === $timestamp ) {
                return true;
            }
            wp_clear_scheduled_hook( $hook, $args );
            return true === wp_schedule_single_event( $timestamp, $hook, $args );
        }

        if ( ! self::action_scheduler_ready() ) {
            // Too early in the request (before init): schedule as soon as the Action Scheduler store is up.
            add_action(
                'action_scheduler_init',
                function() use ( $timestamp, $hook, $args ) {
                    LP_Missing_Scheduler::schedule_single( $timestamp, $hook, $args );
                }
            );
            return true;
        }

        self::clear_wp_cron( $hook, $args );
        if ( self::next( $hook, $args ) === $timestamp ) {
            return true;
        }
        try {
            as_unschedule_all_actions( $hook, $args, self::GROUP );
            as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, true );
            // Check the queue rather than the returned ID (some database drivers report a stale insert ID when the
            // unique check skips the insert). The unique check also counts the running instance of this job, which
            // is what re-schedules itself here: then add the next instance explicitly.
            if ( ! self::next( $hook, $args ) ) {
                as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, false );
            }
        } catch ( Exception $e ) {
            LP_Missing_Logger::error( 'Could not schedule a background job.', array( 'hook' => $hook, 'args' => $args, 'error' => $e->getMessage() ) );
            return false;
        }
        return true;
    }

    /**
     * Cancel pending jobs with this hook and args (both Action Scheduler and WP-Cron leftovers).
     */
    public static function unschedule( $hook, $args = array() ) {
        $args = self::normalize_args( $args );
        self::clear_wp_cron( $hook, $args );
        if ( ! self::action_scheduler_available() || ! self::action_scheduler_ready() ) {
            return;
        }
        try {
            as_unschedule_all_actions( $hook, $args, self::GROUP );
        } catch ( Exception $e ) {
            LP_Missing_Logger::error( 'Could not cancel a background job.', array( 'hook' => $hook, 'args' => $args, 'error' => $e->getMessage() ) );
        }
    }

    /**
     * Unix time of the next pending job with this hook and args, or 0.
     */
    public static function next( $hook, $args = array() ) {
        $args = self::normalize_args( $args );
        if ( ! self::uses_action_scheduler() ) {
            return (int) wp_next_scheduled( $hook, $args );
        }
        if ( ! self::action_scheduler_ready() ) {
            return 0;
        }
        $ids = as_get_scheduled_actions(
            array(
                'hook'     => $hook,
                'args'     => $args,
                'group'    => self::GROUP,
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'orderby'  => 'date',
                'order'    => 'ASC',
                'per_page' => 1,
            ),
            'ids'
        );
        if ( empty( $ids ) ) {
            return 0;
        }
        $action = ActionScheduler::store()->fetch_action( (int) reset( $ids ) );
        $date   = $action ? $action->get_schedule()->get_date() : null;
        return $date ? (int) $date->getTimestamp() : 0;
    }

    /**
     * Make sure a recurring job exists (never adds a second one).
     */
    public static function ensure_recurring( $hook, $interval, $first_run = 0 ) {
        $first_run = $first_run ? (int) $first_run : time() + HOUR_IN_SECONDS;
        if ( ! self::uses_action_scheduler() ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( $first_run, 'daily', $hook );
            }
            return;
        }
        if ( ! self::action_scheduler_ready() ) {
            return;
        }
        self::clear_wp_cron( $hook, array() );
        if ( as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
            return;
        }
        try {
            as_schedule_recurring_action( $first_run, (int) $interval, $hook, array(), self::GROUP, true );
        } catch ( Exception $e ) {
            LP_Missing_Logger::error( 'Could not schedule a recurring job.', array( 'hook' => $hook, 'error' => $e->getMessage() ) );
        }
    }

    public static function ensure_daily_cleanup() {
        self::ensure_recurring( LP_Missing_Plugin::DAILY_CLEANUP_HOOK, DAY_IN_SECONDS );
    }

    /**
     * Throttled (autoloaded option, no query per request): keep the daily cleanup scheduled and move jobs left in
     * WP-Cron (older versions, or the fallback while Action Scheduler was unavailable) to Action Scheduler.
     */
    public static function maybe_maintain() {
        $checked = (int) get_option( self::MAINTENANCE_OPTION, 0 );
        if ( $checked > time() - self::MAINTENANCE_INTERVAL && $checked <= time() ) {
            return;
        }
        update_option( self::MAINTENANCE_OPTION, time(), true );
        if ( self::wp_cron_leftovers() ) {
            self::migrate_from_wp_cron();
        }
        self::ensure_daily_cleanup();
    }

    protected static function clear_wp_cron( $hook, $args ) {
        if ( wp_next_scheduled( $hook, $args ) ) {
            wp_clear_scheduled_hook( $hook, $args );
        }
    }

    /**
     * The plugin's events in the WP-Cron array that should not be there: legacy per-line reminders always, and every
     * plugin event while Action Scheduler is in use. Returns a list of array( timestamp, hook, args ).
     */
    public static function wp_cron_leftovers() {
        $crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
        if ( ! is_array( $crons ) ) {
            return array();
        }
        $hooks  = self::uses_action_scheduler() ? self::hooks() : array( self::LEGACY_REMINDER_HOOK );
        $events = array();
        foreach ( $crons as $timestamp => $cron_hooks ) {
            if ( ! is_array( $cron_hooks ) ) {
                continue;
            }
            foreach ( $cron_hooks as $hook => $instances ) {
                if ( ! in_array( $hook, $hooks, true ) || ! is_array( $instances ) ) {
                    continue;
                }
                foreach ( $instances as $instance ) {
                    $events[] = array( (int) $timestamp, $hook, isset( $instance['args'] ) ? (array) $instance['args'] : array() );
                }
            }
        }
        return $events;
    }

    /**
     * Upgrade step (1.2): move the plugin's WP-Cron events to Action Scheduler and clear them from WP-Cron.
     * Per-line reminders become one reminder per order (at the earliest of its lines, inside the reminder window).
     *
     * @return int Number of WP-Cron events handled.
     */
    public static function migrate_from_wp_cron() {
        $events    = self::wp_cron_leftovers();
        $reminders = array();
        foreach ( $events as $event ) {
            list( $timestamp, $hook, $args ) = $event;
            wp_unschedule_event( $timestamp, $hook, $args );
            if ( self::LEGACY_REMINDER_HOOK === $hook || self::REMINDER_HOOK === $hook ) {
                $order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
                if ( $order_id && ( empty( $reminders[ $order_id ] ) || $timestamp < $reminders[ $order_id ] ) ) {
                    $reminders[ $order_id ] = $timestamp;
                }
            } elseif ( LP_Missing_Plugin::DAILY_CLEANUP_HOOK === $hook ) {
                // A recurring event: once is enough (ensure_recurring never adds a second one).
                self::ensure_recurring( $hook, DAY_IN_SECONDS, max( time(), $timestamp ) );
            } else {
                // Order cleanup and deadline jobs keep their time.
                $existing = self::next( $hook, $args );
                if ( ! $existing || $existing > $timestamp ) {
                    self::schedule_single( $timestamp, $hook, $args );
                }
            }
        }
        foreach ( $reminders as $order_id => $timestamp ) {
            $when     = LP_Missing_Lifecycle::push_into_reminder_window( max( time(), $timestamp ) );
            $existing = self::next( self::REMINDER_HOOK, array( $order_id ) );
            if ( ! $existing || $existing > $when ) {
                self::schedule_single( $when, self::REMINDER_HOOK, array( $order_id ) );
            }
        }
        if ( $events ) {
            LP_Missing_Logger::info( 'Moved WP-Cron events to Action Scheduler.', array( 'events' => count( $events ), 'order_reminders' => count( $reminders ) ) );
        }
        return count( $events );
    }

    public static function upgrade_migrate_wp_cron() {
        self::migrate_from_wp_cron();
        self::ensure_daily_cleanup();
        update_option( self::MAINTENANCE_OPTION, time(), true );
    }

    /**
     * Deactivation: cancel every job of the plugin (Action Scheduler group and WP-Cron leftovers).
     */
    public static function unschedule_all() {
        if ( self::action_scheduler_available() && self::action_scheduler_ready() ) {
            try {
                as_unschedule_all_actions( '', array(), self::GROUP );
            } catch ( Exception $e ) {
                LP_Missing_Logger::error( 'Could not cancel the background jobs.', array( 'error' => $e->getMessage() ) );
            }
        }
        foreach ( self::hooks() as $hook ) {
            wp_unschedule_hook( $hook );
        }
        delete_option( self::MAINTENANCE_OPTION );
    }
}
