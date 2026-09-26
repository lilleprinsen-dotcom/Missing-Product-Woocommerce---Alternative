<?php
/**
 * Customer portal: one missing line, its current decision and the answers to choose from.
 *
 * Override by copying this file to yourtheme/lp-missing/portal/item.php.
 * Keep the input names (lp_choice[], lp_qty[]) and the data-* attributes used by assets/js/portal.js.
 *
 * @package LP_Missing
 *
 * @var WC_Order $order The order.
 * @var array    $line  Line view from LP_Missing_Portal_View::build_line().
 */

defined( 'ABSPATH' ) || exit;

$lp_disabled  = $line['readonly'] ? ' disabled' : '';
$lp_described = $line['error'] ? ' aria-describedby="' . esc_attr( $line['error_id'] ) . '"' : '';
?>
<fieldset class="lp-portal-card lp-portal-item" data-lp-item="<?php echo esc_attr( $line['item_id'] ); ?>" data-name="<?php echo esc_attr( $line['name'] ); ?>" data-qty-missing="<?php echo esc_attr( $line['qty_missing'] ); ?>"<?php echo $lp_described; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
    <legend class="lp-portal-item__legend"><?php echo esc_html( $line['name'] ); ?></legend>

    <div class="lp-portal-item__head">
        <img class="lp-portal-item__img" src="<?php echo esc_url( $line['image'] ); ?>" alt="" width="72" height="72" loading="lazy" decoding="async" />
        <div class="lp-portal-item__info">
            <p class="lp-portal-muted"><?php echo esc_html( $line['meta_text'] ); ?></p>
            <p class="lp-portal-muted"><?php echo esc_html( $line['paid_text'] ); ?></p>
            <?php if ( '' !== $line['note'] ) : ?>
                <p class="lp-pill"><?php echo esc_html( $line['note'] ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $line['decision'] ) : ?>
        <div class="lp-portal-decision">
            <p><strong><?php echo esc_html( $line['decision_label'] ); ?></strong> <?php echo esc_html( $line['decision']['text'] ); ?> <span aria-hidden="true">💚</span></p>
            <?php if ( '' !== $line['decision']['price'] ) : ?>
                <p class="lp-portal-muted"><?php echo esc_html( $line['decision']['price'] ); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $line['unavailable_text'] ) : ?>
        <p class="lp-portal-warning"><?php echo esc_html( $line['unavailable_text'] ); ?></p>
    <?php endif; ?>

    <?php if ( $line['error'] ) : ?>
        <p class="lp-portal-line-error" id="<?php echo esc_attr( $line['error_id'] ); ?>"><span aria-hidden="true">⚠️</span> <?php echo esc_html( $line['error'] ); ?></p>
    <?php endif; ?>

    <p class="lp-portal-options__title"><?php echo esc_html( $line['options_title'] ); ?></p>
    <?php if ( $line['sold_out_text'] ) : ?>
        <p class="lp-portal-muted"><?php echo esc_html( $line['sold_out_text'] ); ?></p>
    <?php endif; ?>

    <div class="lp-portal-options">
        <?php foreach ( $line['options'] as $lp_option ) : ?>
            <div class="lp-option lp-option--<?php echo esc_attr( $lp_option['type'] ); ?>">
                <label class="lp-option__label" for="<?php echo esc_attr( $lp_option['id'] ); ?>">
                    <input
                        type="radio"
                        class="lp-option__input"
                        id="<?php echo esc_attr( $lp_option['id'] ); ?>"
                        name="<?php echo esc_attr( $line['choice_name'] ); ?>"
                        value="<?php echo esc_attr( $lp_option['value'] ); ?>"
                        data-type="<?php echo esc_attr( $lp_option['type'] ); ?>"
                        <?php
                        if ( ! empty( $lp_option['data'] ) ) {
                            foreach ( $lp_option['data'] as $lp_key => $lp_value ) {
                                echo ' data-' . esc_attr( $lp_key ) . '="' . esc_attr( $lp_value ) . '"';
                            }
                        }
                        checked( $lp_option['checked'] );
                        echo $lp_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string.
                        ?>
                    />
                    <span class="lp-option__body">
                        <?php if ( 'alt' === $lp_option['type'] ) : ?>
                            <img class="lp-option__img" src="<?php echo esc_url( $lp_option['image'] ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async" />
                            <span class="lp-option__text">
                                <span class="lp-option__name"><?php echo esc_html( $lp_option['label'] ); ?></span>
                                <?php if ( $lp_option['badge'] ) : ?>
                                    <span class="lp-badge lp-badge--low"><?php echo esc_html( $lp_option['badge'] ); ?></span>
                                <?php endif; ?>
                                <span class="lp-option__price">
                                    <?php echo esc_html( $lp_option['price_text'] ); ?>
                                    <?php if ( $lp_option['per_kg'] ) : ?>
                                        <span class="lp-option__per-kg"><?php echo esc_html( $lp_option['per_kg'] ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="lp-option__diff"><?php echo esc_html( $lp_option['diff_text'] ); ?></span>
                                <?php if ( $lp_option['stock_text'] ) : ?>
                                    <span class="lp-option__stock"><?php echo esc_html( $lp_option['stock_text'] ); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php else : ?>
                            <span class="lp-option__icon" aria-hidden="true"><?php echo esc_html( $lp_option['emoji'] ); ?></span>
                            <span class="lp-option__text">
                                <span class="lp-option__name"><?php echo esc_html( $lp_option['label'] ); ?></span>
                                <span class="lp-option__diff"><?php echo esc_html( $lp_option['description'] ); ?></span>
                            </span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php if ( 'alt' === $lp_option['type'] && $lp_option['link'] ) : ?>
                    <a class="lp-option__link" href="<?php echo esc_url( $lp_option['link'] ); ?>" target="_blank" rel="noopener noreferrer">
                        <span aria-hidden="true"><?php esc_html_e( 'Se varen', 'lp-missing' ); ?></span>
                        <span class="screen-reader-text"><?php echo esc_html( $lp_option['link_text'] ); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ( $line['qty']['show'] ) : ?>
        <div class="lp-portal-qty">
            <label for="<?php echo esc_attr( $line['qty']['id'] ); ?>"><?php echo esc_html( $line['qty']['label'] ); ?></label>
            <input
                type="number"
                class="lp-qty-input"
                id="<?php echo esc_attr( $line['qty']['id'] ); ?>"
                name="<?php echo esc_attr( $line['qty']['name'] ); ?>"
                value="<?php echo esc_attr( $line['qty']['value'] ); ?>"
                min="1"
                max="<?php echo esc_attr( $line['qty']['max'] ); ?>"
                step="1"
                inputmode="numeric"
                aria-describedby="<?php echo esc_attr( $line['qty']['id'] ); ?>-help"
                <?php echo $lp_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string. ?>
            />
            <p class="lp-portal-muted" id="<?php echo esc_attr( $line['qty']['id'] ); ?>-help"><?php echo esc_html( $line['qty']['help'] ); ?></p>
        </div>
    <?php endif; ?>
</fieldset>
