<?php
/**
 * Customer portal: every missing line of the order in one form.
 *
 * Override by copying this file to yourtheme/lp-missing/portal/portal.php.
 *
 * @package LP_Missing
 *
 * @var WC_Order $order The order.
 * @var array    $view  View model from LP_Missing_Portal_View::build(): readonly, readonly_text, greeting, order_label,
 *                      intro, change_hint, deadline, notice (type, text, emoji, after) or null, lines (see item.php),
 *                      summary (title, lines, total), form (action, fields, config), submit_label.
 */

defined( 'ABSPATH' ) || exit;

$lp_notice = $view['notice'];
?>
<div class="lp-missing-portal<?php echo $view['readonly'] ? ' lp-missing-portal--readonly' : ''; ?>">
    <?php if ( $view['readonly'] ) : ?>
        <p class="lp-portal-banner" role="note"><?php echo esc_html( $view['readonly_text'] ); ?></p>
    <?php endif; ?>

    <div class="lp-portal-card lp-portal-intro">
        <h2 class="lp-portal-title"><?php echo esc_html( $view['greeting'] ); ?> <span aria-hidden="true">👋</span></h2>
        <p class="lp-portal-muted"><?php echo esc_html( $view['order_label'] ); ?></p>
        <p><?php echo esc_html( $view['intro'] ); ?></p>
        <?php if ( $view['deadline'] ) : ?>
            <p class="lp-portal-deadline"><span aria-hidden="true">⏰</span> <strong><?php echo esc_html( $view['deadline'] ); ?></strong></p>
        <?php endif; ?>
    </div>

    <div class="lp-portal-notices">
        <?php if ( $lp_notice ) : ?>
            <div class="lp-portal-notice lp-portal-notice--<?php echo esc_attr( $lp_notice['type'] ); ?> <?php echo 'error' === $lp_notice['type'] ? 'lp-message-error' : 'lp-message-success'; ?>" role="<?php echo 'error' === $lp_notice['type'] ? 'alert' : 'status'; ?>" aria-live="<?php echo 'error' === $lp_notice['type'] ? 'assertive' : 'polite'; ?>" tabindex="-1" data-lp-focus="1">
                <?php echo esc_html( $lp_notice['text'] ); ?>
                <?php if ( ! empty( $lp_notice['emoji'] ) ) : ?>
                    <span aria-hidden="true"><?php echo esc_html( $lp_notice['emoji'] ); ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $lp_notice['after'] ) ) : ?>
                    <?php echo esc_html( $lp_notice['after'] ); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( $view['readonly'] ) : ?>
    <div class="lp-portal-form" data-lp-config="<?php echo esc_attr( $view['form']['config'] ); ?>">
    <?php else : ?>
    <form method="post" action="<?php echo esc_url( $view['form']['action'] ); ?>" class="lp-portal-form" data-lp-config="<?php echo esc_attr( $view['form']['config'] ); ?>">
        <?php foreach ( $view['form']['fields'] as $lp_name => $lp_value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( $lp_name ); ?>" value="<?php echo esc_attr( $lp_value ); ?>" />
        <?php endforeach; ?>
    <?php endif; ?>

        <?php
        foreach ( $view['lines'] as $lp_line ) {
            // Each card is its own (overridable) template.
            echo LP_Missing_Util::get_template_html( 'portal/item.php', array( 'order' => $order, 'line' => $lp_line ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
        }
        ?>

        <section class="lp-portal-card lp-portal-summary" aria-labelledby="lp-portal-summary-title">
            <h3 class="lp-portal-summary__title" id="lp-portal-summary-title"><?php echo esc_html( $view['summary']['title'] ); ?></h3>
            <div class="lp-portal-summary__body" aria-live="polite">
                <ul class="lp-portal-summary__lines">
                    <?php foreach ( $view['summary']['lines'] as $lp_text ) : ?>
                        <li><?php echo esc_html( $lp_text ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="lp-portal-summary__total"><strong><?php echo esc_html( $view['summary']['total'] ); ?></strong></p>
            </div>
            <p class="lp-portal-muted"><?php echo esc_html( $view['change_hint'] ); ?></p>
        </section>

    <?php if ( $view['readonly'] ) : ?>
    </div>
    <?php else : ?>
        <div class="lp-portal-actions">
            <button type="submit" class="lp-btn lp-btn--primary"><?php echo esc_html( $view['submit_label'] ); ?></button>
        </div>
    </form>
    <?php endif; ?>
</div>
