<?php

namespace BuyMeCoffee\Classes;

use BuyMeCoffee\Classes\View;
use BuyMeCoffee\Classes\Vite;
use BuyMeCoffee\Helpers\ArrayHelper;
use \BuyMeCoffee\Models\Buttons;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class DemoPage
{
    public function register()
    {
        $this->handleExteriorPages();
    }

    public function loadTemplateStyles()
    {
        Vite::enqueueStyle('buymecoffee_template_style', 'scss/public/BasicTemplate.scss', array(), BUYMECOFFEE_VERSION);
    }

    public function renderFormOnly($args = [])
    {
        $this->loadTemplateStyles();
        $btnController = new Buttons();
        $template = $btnController->getButton();
        ob_start();
        include BUYMECOFFEE_DIR . 'includes/views/templates/FormShortCode.php';
        return ob_get_clean();
    }

    public function handleExteriorPages()
    {
        if (defined('CT_VERSION')) {
            // oxygen page compatibility
            remove_action('wp_head', 'oxy_print_cached_css', 999999);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['share_coffee'])) {
            $this->renderBasicTemplate([], 'page');
        }
    }

    public function loadModalContent()
    {
        wp_enqueue_style('dashicons');
        $this->loadTemplateStyles();
        $btnController = new Buttons();
        $template = $btnController->getButton();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo View::make('templates.FormShortCode', ['template' => $template, 'type' => '',]);
    }

    public function renderBasicTemplate($args = [], $type = '')
    {
        if (is_page() || $type === 'page') {
            wp_enqueue_style('dashicons');
            $this->loadDefaultPageTemplate();
            $this->loadTemplateStyles();
            $this->loadCustomizer();

            $btnController = new Buttons();
            $template = $btnController->getButton();

            $quote = ArrayHelper::get($template, 'advanced.quote', false);
            $profileImage = ArrayHelper::get($template, 'advanced.image');
            if ($profileImage == '') {
                $profileImage = Vite::staticPath() . 'images/profile.png';
            }
            //escaped in template, ignoring phpcs here now
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo View::make('templates.BasicTemplate', [
                'template' => $template,
                'type' => 'button',
                'quote' => esc_html($quote),
                'show_title' => esc_html(ArrayHelper::get($template, 'formTitle') == 'yes'),
                'profile_image' => esc_url($profileImage),
                'name' => esc_html(ArrayHelper::get($template, 'yourName')),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'args' => static::getSanitizedArguments($args),
            ]);
            exit();
        }
    }

    public function loadCustomizer()
    {
        if (!current_user_can('manage_options')) return;
        vite::enqueueScript('buymecoffee_customizer',  'js/customizer.js', array('jquery'), BUYMECOFFEE_VERSION, false);
        Vite::enqueueStyle('buymecoffee_customizer_style', 'scss/admin/customizer.scss', array(), BUYMECOFFEE_VERSION);
    }

    public function loadDefaultPageTemplate()
    {
        add_filter('template_include', function ($original) {
            return locate_template(array('page.php', 'single.php', 'index.php'));
        }, 999);
    }

    public static function getSanitizedArguments($args): array
    {
        if (!is_array($args)) {
            $args = [];
        }

        foreach ($args as $key => $value) {
            $args[$key] = esc_html($value);
        }
        return $args;
    }
}