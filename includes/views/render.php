<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   ?>
<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file with local variables
?>

<div id="buy-me-coffee_app" class="buy-me-coffee_app">
    <div class="buymecoffee_app">
        <div class="buymecoffee_main-menu-items">
            <div class="menu_logo_holder">
                <a href="<?php echo esc_url($baseUrl); ?>">
                    <img style="max-height: 40px;" src="<?php echo esc_url($logo); ?>"/>
                    <span>beta</span>
                </a>
            </div>
            <div class="buymecoffee_handheld"><span class="dashicons dashicons-menu-alt3"></span></div>

            <ul class="buymecoffee_menu">
                <?php foreach ($menuItems as $item): ?>
                    <?php $hasSubMenu = !empty($item['sub_items']); ?>
                    <li data-key="<?php echo esc_attr($item['key']); ?>"
                        class="buymecoffee_menu_item <?php echo ($hasSubMenu) ? 'buymecoffee_has_sub_items' : ''; ?> buymecoffee_item_<?php echo esc_attr($item['key']); ?>">
                        <a class="buymecoffee_menu_primary" href="<?php echo esc_url($item['permalink']); ?>">
                            <?php echo esc_html($item['label']); ?>
                            <?php if ($hasSubMenu) { ?>
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            <?php } ?></a>
                        <?php if ($hasSubMenu): ?>
                            <div class="buymecoffee _submenu_items">
                                <?php foreach ($item['sub_items'] as $sub_item): ?>
                                    <a href="<?php echo esc_url($sub_item['permalink']); ?>"><?php echo esc_attr($sub_item['label']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="buymecoffee_body">
            <div class="bmc_route_wrapper">
                <router-view></router-view>
            </div>
        </div>
    </div>
</div>
