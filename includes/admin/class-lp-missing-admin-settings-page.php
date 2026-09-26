<?php
/**
 * WooCommerce > Missing Items Settings page, rendered from the settings schema.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Admin_Settings_Page {
    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
        add_action( 'admin_post_lp_missing_save_settings', array( __CLASS__, 'handle_settings_save' ) );
    }

    public static function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Missing Items Settings', 'lp-missing' ),
            __( 'Missing Items Settings', 'lp-missing' ),
            'manage_woocommerce',
            'lp-missing-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function get_page_url() {
        return admin_url( 'admin.php?page=lp-missing-settings' );
    }

    public static function handle_settings_save() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage these settings.', 'lp-missing' ) );
        }
        check_admin_referer( 'lp_missing_settings' );

        $settings = LP_Missing_Settings::get_settings();
        foreach ( LP_Missing_Settings::get_fields() as $key => $field ) {
            $name = 'lp_' . $key;
            if ( 'checkbox' === $field['type'] ) {
                $settings[ $key ] = ! empty( $_POST[ $name ] ) ? 'yes' : 'no';
            } elseif ( isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ) {
                // Sanitised per field type by LP_Missing_Settings. Anything else (e.g. an array) keeps the current value.
                $settings[ $key ] = wp_unslash( $_POST[ $name ] );
            }
        }

        LP_Missing_Settings::update_settings( $settings );

        /**
         * Fires after the plugin settings were saved.
         *
         * @param array $settings Sanitised settings.
         */
        do_action( 'lp_missing_settings_saved', LP_Missing_Settings::get_settings() );

        wp_safe_redirect( add_query_arg( 'updated', 'true', self::get_page_url() ) );
        exit;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = LP_Missing_Settings::get_settings();
        $fields   = LP_Missing_Settings::get_fields();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Missing Items Settings', 'lp-missing' ); ?></h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'lp-missing' ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lp_missing_settings' ); ?>
                <input type="hidden" name="action" value="lp_missing_save_settings" />
                <?php foreach ( LP_Missing_Settings::get_sections() as $section => $title ) : ?>
                    <?php
                    $section_fields = array_filter(
                        $fields,
                        function( $field ) use ( $section ) {
                            return $section === $field['section'];
                        }
                    );
                    if ( ! $section_fields ) {
                        continue;
                    }
                    ?>
                    <h2 class="title"><?php echo esc_html( $title ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php foreach ( $section_fields as $key => $field ) : ?>
                                <tr>
                                    <th scope="row"><label for="lp_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                                    <td>
                                        <?php self::render_field( $key, $field, $settings[ $key ] ); ?>
                                        <?php if ( ! empty( $field['description'] ) ) : ?>
                                            <p class="description"><?php echo esc_html( $field['description'] ); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
                <?php submit_button( __( 'Save settings', 'lp-missing' ) ); ?>
            </form>
        </div>
        <?php
    }

    protected static function render_field( $key, $field, $value ) {
        $name = 'lp_' . $key;
        switch ( $field['type'] ) {
            case 'checkbox':
                echo '<label><input type="checkbox" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 'yes', $value, false ) . ' /> ' . esc_html__( 'Enabled', 'lp-missing' ) . '</label>';
                break;
            case 'int':
                $min = isset( $field['min'] ) ? ' min="' . esc_attr( $field['min'] ) . '"' : '';
                $max = isset( $field['max'] ) ? ' max="' . esc_attr( $field['max'] ) . '"' : '';
                echo '<input type="number" step="1" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $min . $max . ' class="small-text" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
                break;
            case 'amount':
                echo '<input type="number" step="0.01" min="0" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="small-text" /> ' . esc_html( get_woocommerce_currency_symbol() );
                break;
            case 'radio':
                echo '<fieldset>';
                foreach ( $field['options'] as $option => $label ) {
                    echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option ) . '" ' . checked( $option, $value, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
                }
                echo '</fieldset>';
                break;
            case 'select':
                echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
                foreach ( $field['options'] as $option => $label ) {
                    echo '<option value="' . esc_attr( $option ) . '" ' . selected( $option, $value, false ) . '>' . esc_html( $label ) . '</option>';
                }
                echo '</select>';
                break;
            case 'page':
                wp_dropdown_pages(
                    array(
                        'name'              => esc_attr( $name ),
                        'id'                => esc_attr( $name ),
                        'selected'          => absint( $value ),
                        'show_option_none'  => esc_html__( '— My Account page —', 'lp-missing' ),
                        'option_none_value' => '0',
                    )
                );
                break;
            case 'url':
            case 'email':
            case 'text':
            default:
                $type = in_array( $field['type'], array( 'url', 'email' ), true ) ? $field['type'] : 'text';
                echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
                break;
        }
    }
}
