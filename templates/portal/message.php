<?php
/**
 * Customer portal: a single message (invalid link, nothing to choose, landing page).
 *
 * Override by copying this file to yourtheme/lp-missing/portal/message.php.
 *
 * @package LP_Missing
 *
 * @var string $type    error, success or info.
 * @var string $message Message text.
 * @var string $emoji   Decorative emoji, or ''.
 * @var array  $links   Links to show under the message: array( array( 'url' => ..., 'label' => ... ) ).
 */

defined( 'ABSPATH' ) || exit;

$lp_classes = array(
    'error'   => 'lp-missing-portal-error',
    'success' => 'lp-missing-portal-empty',
    'info'    => 'lp-missing-portal-info',
);
$lp_class   = isset( $lp_classes[ $type ] ) ? $lp_classes[ $type ] : $lp_classes['info'];
?>
<div class="lp-missing-portal lp-missing-portal--message">
    <div class="lp-portal-card <?php echo esc_attr( $lp_class ); ?> lp-portal-notice lp-portal-notice--<?php echo esc_attr( $type ); ?>" role="<?php echo 'error' === $type ? 'alert' : 'status'; ?>">
        <p>
            <?php echo esc_html( $message ); ?>
            <?php if ( $emoji ) : ?>
                <span aria-hidden="true"><?php echo esc_html( $emoji ); ?></span>
            <?php endif; ?>
        </p>
        <?php if ( $links ) : ?>
            <ul class="lp-portal-links">
                <?php foreach ( $links as $lp_link ) : ?>
                    <li><a class="lp-btn lp-btn--secondary" href="<?php echo esc_url( $lp_link['url'] ); ?>"><?php echo esc_html( $lp_link['label'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
