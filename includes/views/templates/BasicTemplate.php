<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url($profile_image); ?>">
    <title><?php echo esc_html($name); ?> - <?php esc_html_e('Buy Me Coffee', 'buy-me-coffee'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="bmc-page">
    <?php include BUYMECOFFEE_DIR . 'includes/views/templates/FormTemplate.php'; ?>
    <?php wp_footer(); ?>
</body>
</html>
