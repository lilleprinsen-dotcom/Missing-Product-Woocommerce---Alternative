<?php
/**
 * Decision deadline: when a line that waits for the customer gets the configured default action.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Deadline {

    /**
     * A deadline only exists when an automatic action is configured.
     */
    public static function enabled() {
        return 'none' !== LP_Missing_Settings::get( 'deadline_action' );
    }

    public static function get_action() {
        return LP_Missing_Settings::get( 'deadline_action' );
    }

    /**
     * Deadline for a case that (re)started waiting for the customer at $start: N days later at the configured hour
     * (site time zone), never less than 24 hours after $start.
     */
    public static function calculate( $start ) {
        $start = $start ? absint( $start ) : time();
        $days  = max( 1, absint( LP_Missing_Settings::get( 'decision_deadline_days' ) ) );
        $hour  = absint( LP_Missing_Settings::get( 'deadline_hour' ) );
        $date  = ( new DateTimeImmutable( '@' . ( $start + $days * DAY_IN_SECONDS ) ) )->setTimezone( wp_timezone() )->setTime( $hour, 0 );
        if ( $date->getTimestamp() < $start + DAY_IN_SECONDS ) {
            $date = $date->modify( '+1 day' );
        }
        return $date->getTimestamp();
    }

    /**
     * Deadline stored on a line (set when the case starts waiting), or 0 when there is none.
     */
    public static function get_for_line( $data ) {
        if ( ! self::enabled() || ! LP_Missing_Line::is_awaiting_customer( $data ) ) {
            return 0;
        }
        return ! empty( $data['deadline_at'] ) ? absint( $data['deadline_at'] ) : self::calculate( $data['first_missing_at'] );
    }

    /**
     * Earliest deadline among the order's lines waiting for the customer, or 0.
     */
    public static function get_for_order( $order ) {
        $earliest = 0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $deadline = self::get_for_line( LP_Missing_Line::get_item_data( $item ) );
            if ( $deadline && ( ! $earliest || $deadline < $earliest ) ) {
                $earliest = $deadline;
            }
        }
        return $earliest;
    }

    /**
     * E.g. "fredag 3. oktober kl. 12:00" (localised by the site language).
     */
    public static function format( $timestamp ) {
        return wp_date( _x( 'l j. F \k\l. H:i', 'deadline date format', 'lp-missing' ), $timestamp );
    }

    /**
     * Customer-facing sentence describing what happens at the deadline, or '' without a deadline.
     */
    public static function describe( $timestamp ) {
        if ( ! $timestamp ) {
            return '';
        }
        $when = self::format( $timestamp );
        if ( 'reduce' === self::get_action() ) {
            return sprintf( __( 'Hvis vi ikke hører fra deg innen %s, fjerner vi varen fra ordren.', 'lp-missing' ), $when );
        }
        return sprintf( __( 'Hvis vi ikke hører fra deg innen %s, refunderer vi varen.', 'lp-missing' ), $when );
    }
}
