<?php
/**
 * Customer portal: confirm the billing email once before the choices are shown.
 *
 * Override by copying this file to yourtheme/lp-missing/portal/verify.php. Keep the field names.
 *
 * @package LP_Missing
 *
 * @var WC_Order $order      The order.
 * @var string   $error      Error message of the last attempt, or ''.
 * @var string   $action_url Form action.
 * @var array    $fields     Hidden fields (name => value).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lp-missing-portal lp-missing-portal--verify">
    <div class="lp-portal-card lp-missing-portal-verify">
        <h2 class="lp-portal-title"><?php esc_html_e( 'Bekreft at det er deg', 'lp-missing' ); ?></h2>
        <p><?php esc_html_e( 'Bekreft e-postadressen du brukte i kassen for å fortsette.', 'lp-missing' ); ?></p>
        <?php if ( $error ) : ?>
            <p class="lp-missing-portal-error lp-portal-notice lp-portal-notice--error" id="lp-verify-error" role="alert" tabindex="-1" data-lp-focus="1"><?php echo esc_html( $error ); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="lp-portal-verify-form">
            <?php foreach ( $fields as $lp_name => $lp_value ) : ?>
                <input type="hidden" name="<?php echo esc_attr( $lp_name ); ?>" value="<?php echo esc_attr( $lp_value ); ?>" />
            <?php endforeach; ?>
            <label for="lp-verify-email"><?php esc_html_e( 'E-postadresse', 'lp-missing' ); ?></label>
            <input type="email" id="lp-verify-email" name="lp_missing_verify_email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required<?php echo $error ? ' aria-invalid="true" aria-describedby="lp-verify-error"' : ''; ?> />
            <button type="submit" class="lp-btn lp-btn--primary"><?php esc_html_e( 'Fortsett', 'lp-missing' ); ?></button>
        </form>
        <p class="lp-portal-muted"><?php esc_html_e( 'Vi spør om dette for å beskytte bestillingen din. Etterpå husker vi deg en stund på denne enheten.', 'lp-missing' ); ?></p>
    </div>
</div>
