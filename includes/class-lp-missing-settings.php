<?php
/**
 * Plugin settings: schema, defaults, sanitising and access.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Settings {
    protected static $settings = null;

    /**
     * Settings schema. Every setting is declared once here; sanitising, saving and the settings page are driven by it.
     *
     * Types: checkbox (yes/no), int (min/max), amount (decimal >= 0), text, url, email, radio/select (options), page.
     */
    public static function get_fields() {
        $fields = array(
            // Stock.
            'enable_stock_lock'           => array( 'section' => 'stock', 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'Lock stock for missing quantities', 'lp-missing' ), 'description' => __( 'Adjust inventory when items are marked missing. Disabling this releases existing locks as lines are updated.', 'lp-missing' ) ),
            'enable_stock_notes'          => array( 'section' => 'stock', 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'Add inventory history notes', 'lp-missing' ), 'description' => __( 'Write order notes when stock is adjusted.', 'lp-missing' ) ),
            'show_stock_preview'          => array( 'section' => 'stock', 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'Show stock in alternative previews', 'lp-missing' ), 'description' => __( 'Display stock quantities and availability for suggested alternatives.', 'lp-missing' ) ),
            // Reminders and escalation.
            'reminder_delay_days'         => array( 'section' => 'reminders', 'type' => 'int', 'default' => 3, 'min' => 1, 'label' => __( 'Reminder spacing (days)', 'lp-missing' ), 'description' => __( 'How long after the first email (and between reminders) a reminder is sent.', 'lp-missing' ) ),
            'reminder_max_count'          => array( 'section' => 'reminders', 'type' => 'int', 'default' => 3, 'min' => 1, 'label' => __( 'Maximum reminders before escalation', 'lp-missing' ), 'description' => __( 'After this many reminders, the line is flagged for manual follow-up.', 'lp-missing' ) ),
            'reminder_max_age_days'       => array( 'section' => 'reminders', 'type' => 'int', 'default' => 7, 'min' => 1, 'label' => __( 'Maximum age before escalation (days)', 'lp-missing' ), 'description' => __( 'A line still waiting after this many days is escalated even if the reminder limit is not reached.', 'lp-missing' ) ),
            'reminder_window_start'       => array( 'section' => 'reminders', 'type' => 'int', 'default' => 9, 'min' => 0, 'max' => 23, 'label' => __( 'Send reminders from (hour)', 'lp-missing' ), 'description' => __( 'Reminders are only sent between these hours, in the site time zone.', 'lp-missing' ) ),
            'reminder_window_end'         => array( 'section' => 'reminders', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 24, 'label' => __( 'Send reminders until (hour)', 'lp-missing' ), 'description' => '' ),
            // Decision deadline (default action).
            'deadline_action'             => array( 'section' => 'deadline', 'type' => 'select', 'default' => 'none', 'options' => array( 'none' => __( 'No automatic action (escalate to staff only)', 'lp-missing' ), 'refund' => __( 'Refund the missing quantity', 'lp-missing' ), 'reduce' => __( 'Remove the missing quantity from the order totals (for payments captured at shipping; paid amounts are not refunded)', 'lp-missing' ) ), 'label' => __( 'When the customer does not answer in time', 'lp-missing' ), 'description' => __( 'The deadline starts when the customer is emailed and is shown in emails and in the portal. Unpaid orders always have the quantity removed. Lines changed after the customer was notified are left to staff.', 'lp-missing' ) ),
            'decision_deadline_days'      => array( 'section' => 'deadline', 'type' => 'int', 'default' => 5, 'min' => 1, 'label' => __( 'Deadline (days after the first email)', 'lp-missing' ), 'description' => '' ),
            'deadline_hour'               => array( 'section' => 'deadline', 'type' => 'int', 'default' => 12, 'min' => 0, 'max' => 23, 'label' => __( 'Deadline time of day (hour)', 'lp-missing' ), 'description' => '' ),
            // Prices.
            'alt_price_handling'          => array( 'section' => 'prices', 'type' => 'radio', 'default' => 'charge_customer', 'options' => array( 'charge_customer' => __( 'Charge the customer for more expensive alternatives.', 'lp-missing' ), 'store_covers' => __( 'Store covers the extra cost (no charge to customer).', 'lp-missing' ) ), 'label' => __( 'Price differences for alternatives', 'lp-missing' ), 'description' => __( 'Controls whether a separate surcharge order is raised when the chosen alternative costs more. Comparison values are frozen when the customer makes their choice.', 'lp-missing' ) ),
            'store_covers_below'          => array( 'section' => 'prices', 'type' => 'amount', 'default' => 0, 'label' => __( 'Store covers differences up to (incl. VAT)', 'lp-missing' ), 'description' => __( 'When charging customers, the store still covers a total difference up to this amount. 0 turns it off.', 'lp-missing' ) ),
            // Portal and links.
            'portal_page_id'              => array( 'section' => 'portal', 'type' => 'page', 'default' => 0, 'label' => __( 'Customer portal page', 'lp-missing' ), 'description' => __( 'Page that shows the [lp_missing_items] portal. Magic links in customer emails point here.', 'lp-missing' ) ),
            'portal_base_url'             => array( 'section' => 'portal', 'type' => 'url', 'default' => '', 'label' => __( 'Customer portal URL (override)', 'lp-missing' ), 'description' => __( 'Only needed when the portal lives outside a WordPress page. Leave blank to use the page above, or the My Account page.', 'lp-missing' ) ),
            'magic_link_ttl_days'         => array( 'section' => 'portal', 'type' => 'int', 'default' => 30, 'min' => 1, 'label' => __( 'Customer links valid for (days)', 'lp-missing' ), 'description' => __( 'Links in emails stop working after this many days. A new email always carries a fresh link.', 'lp-missing' ) ),
            // Staff notifications.
            'notify_staff_on_decision'    => array( 'section' => 'staff', 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'Email staff when a customer has chosen', 'lp-missing' ), 'description' => '' ),
            'staff_notification_email'    => array( 'section' => 'staff', 'type' => 'email', 'default' => '', 'label' => __( 'Staff email address', 'lp-missing' ), 'description' => __( 'Leave blank to use the site admin email.', 'lp-missing' ) ),
            // Housekeeping.
            'cleanup_resolved_after_days' => array( 'section' => 'general', 'type' => 'int', 'default' => 30, 'min' => LP_Missing_Plugin::CLEANUP_MIN_DAYS, 'label' => __( 'Cleanup resolved records after (days)', 'lp-missing' ), 'description' => sprintf( __( 'Resolved missing-item data is removed after this many days (minimum %d days).', 'lp-missing' ), LP_Missing_Plugin::CLEANUP_MIN_DAYS ) ),
            'enable_logging'              => array( 'section' => 'general', 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'Log events', 'lp-missing' ), 'description' => __( 'Write status changes, emails and refunds to WooCommerce > Status > Logs (source "lp-missing").', 'lp-missing' ) ),
        );

        /**
         * Filter the settings schema (add or change fields).
         *
         * @param array $fields Settings schema keyed by setting name.
         */
        return apply_filters( 'lp_missing_settings_fields', $fields );
    }

    public static function get_sections() {
        return array(
            'stock'     => __( 'Stock', 'lp-missing' ),
            'reminders' => __( 'Reminders and escalation', 'lp-missing' ),
            'deadline'  => __( 'Decision deadline', 'lp-missing' ),
            'prices'    => __( 'Prices', 'lp-missing' ),
            'portal'    => __( 'Customer portal and links', 'lp-missing' ),
            'staff'     => __( 'Staff notifications', 'lp-missing' ),
            'general'   => __( 'General', 'lp-missing' ),
        );
    }

    public static function get_default_settings() {
        return wp_list_pluck( self::get_fields(), 'default' );
    }

    public static function sanitize_field( $field, $value ) {
        if ( null !== $value && ! is_scalar( $value ) ) {
            return $field['default'];
        }
        switch ( $field['type'] ) {
            case 'checkbox':
                return 'yes' === $value ? 'yes' : 'no';
            case 'int':
                $value = absint( $value );
                if ( isset( $field['min'] ) ) {
                    $value = max( $field['min'], $value );
                }
                if ( isset( $field['max'] ) ) {
                    $value = min( $field['max'], $value );
                }
                return $value;
            case 'amount':
                return (float) wc_format_decimal( max( 0, (float) $value ), wc_get_price_decimals() );
            case 'page':
                // Only a real page (not a product or another post type) can be the portal page.
                $value = absint( $value );
                return $value && 'page' === get_post_type( $value ) ? $value : absint( $field['default'] );
            case 'url':
                return esc_url_raw( trim( (string) $value ) );
            case 'email':
                return sanitize_email( (string) $value );
            case 'radio':
            case 'select':
                return array_key_exists( (string) $value, $field['options'] ) ? (string) $value : $field['default'];
            default:
                return sanitize_text_field( (string) $value );
        }
    }

    public static function sanitize_settings( $settings ) {
        $defaults = self::get_default_settings();
        $settings = is_array( $settings ) ? wp_parse_args( $settings, $defaults ) : $defaults;
        foreach ( self::get_fields() as $key => $field ) {
            $settings[ $key ] = self::sanitize_field( $field, $settings[ $key ] );
        }
        if ( $settings['reminder_window_end'] <= $settings['reminder_window_start'] ) {
            $settings['reminder_window_end'] = min( 24, $settings['reminder_window_start'] + 1 );
        }
        return $settings;
    }

    public static function get_settings( $force = false ) {
        if ( is_array( self::$settings ) && ! $force ) {
            return self::$settings;
        }

        $raw = get_option( LP_Missing_Plugin::OPTION_SETTINGS, array() );
        $raw = is_array( $raw ) ? $raw : array();

        if ( empty( $raw['enable_stock_lock'] ) ) {
            $raw['enable_stock_lock'] = get_option( LP_Missing_Plugin::OPTION_ENABLE_STOCK, 'yes' );
        }
        if ( empty( $raw['portal_base_url'] ) ) {
            $raw['portal_base_url'] = get_option( LP_Missing_Plugin::OPTION_PORTAL_URL, '' );
        }

        self::$settings = self::sanitize_settings( $raw );

        return self::$settings;
    }

    public static function get( $key ) {
        $settings = self::get_settings();
        return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
    }

    public static function update_settings( $settings ) {
        $sanitized = self::sanitize_settings( $settings );
        update_option( LP_Missing_Plugin::OPTION_SETTINGS, $sanitized, false );
        update_option( LP_Missing_Plugin::OPTION_ENABLE_STOCK, $sanitized['enable_stock_lock'], false );
        update_option( LP_Missing_Plugin::OPTION_PORTAL_URL, $sanitized['portal_base_url'], false );
        self::$settings = $sanitized;
    }

    /**
     * Forget the cached settings (e.g. after options were changed directly).
     */
    public static function flush() {
        self::$settings = null;
    }

    public static function stock_lock_enabled() {
        $settings = self::get_settings();
        $enabled  = isset( $settings['enable_stock_lock'] ) ? $settings['enable_stock_lock'] : 'yes';
        return apply_filters( 'lp_missing_enable_stock_log', 'yes' === $enabled );
    }
}
