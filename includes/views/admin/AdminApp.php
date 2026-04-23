<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?></title>
    <style>
        /* Prevent FOUC — hide until Vue mounts */
        #buy-me-coffee_app { opacity: 0; transition: opacity 0.2s ease; }
        #buy-me-coffee_app[data-v-app] { opacity: 1; }
        html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; }
    </style>
    <?php wp_head(); ?>
</head>
<body class="buymecoffee-admin-app">
    <div id="buy-me-coffee_app"><router-view></router-view></div>
    <?php wp_footer(); ?>
</body>
</html>
