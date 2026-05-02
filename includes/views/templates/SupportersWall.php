<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="buymecoffee-supporters-wall">
    <?php if ( empty( $supporters ) ) : ?>
        <p class="buymecoffee-supporters-wall__empty">
            <?php esc_html_e( 'Be the first to show your support!', 'buy-me-coffee' ); ?>
        </p>
    <?php else : ?>
        <div class="buymecoffee-supporters-wall__list">
            <?php foreach ( $supporters as $supporter ) :
                $rankClass = '';
                if ( $supporter->rank === 1 ) $rankClass = 'buymecoffee-supporter-row--gold';
                elseif ( $supporter->rank === 2 ) $rankClass = 'buymecoffee-supporter-row--silver';
                elseif ( $supporter->rank === 3 ) $rankClass = 'buymecoffee-supporter-row--bronze';
            ?>
                <div class="buymecoffee-supporter-row <?php echo esc_attr( $rankClass ); ?>">
                    <span class="buymecoffee-supporter-row__rank"><?php echo esc_html( $supporter->rank ); ?></span>

                    <?php if ( ! empty( $supporter->avatar ) ) : ?>
                        <img
                            class="buymecoffee-supporter-row__avatar"
                            src="<?php echo esc_url( $supporter->avatar ); ?>"
                            alt="<?php echo esc_attr( $supporter->name ); ?>"
                            width="48"
                            height="48"
                            loading="lazy"
                        />
                    <?php else : ?>
                        <div class="buymecoffee-supporter-row__avatar-placeholder">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                    <?php endif; ?>

                    <div class="buymecoffee-supporter-row__info">
                        <span class="buymecoffee-supporter-row__name">
                            <?php echo esc_html( $supporter->name ); ?>
                        </span>
                        <span class="buymecoffee-supporter-row__meta">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %d: number of payments */
                                _n( '%d payment', '%d payments', $supporter->donation_count, 'buy-me-coffee' ),
                                $supporter->donation_count
                            ) );
                            ?>
                        </span>
                    </div>

                    <?php if ( ! empty( $supporter->amount ) ) : ?>
                        <span class="buymecoffee-supporter-row__amount">
                            <?php echo wp_kses_post( $supporter->amount ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
