<?php

namespace BuyMeCoffee\Classes;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Menu
{
    public function register()
    {
        add_action('admin_menu', array($this, 'addMenus'));
    }

    public function addMenus()
    {
        $menuPermission = AccessControl::hasTopLevelMenuPermission();
        if (!$menuPermission) {
            return;
        }

        $title = __('Buy Me Coffee', 'buy-me-coffee');

        add_menu_page(
            $title,
            $title,
            'manage_options',
            'buy-me-coffee.php',
            [$this, 'render'],
            'dashicons-coffee',
            68
        );
    }

    public function render()
    {
        $appUrl = site_url('?buymecoffee_admin');
        ?>
        <div class="wrap" style="display:flex; align-items:center; justify-content:center; min-height:70vh;">
            <div style="text-align:center; max-width:480px;">
                <div style="margin-bottom:24px;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/>
                    </svg>
                </div>
                <h1 style="font-size:24px; font-weight:600; margin-bottom:8px; color:#1e293b;">
                    <?php esc_html_e('Buy Me Coffee', 'buy-me-coffee'); ?>
                </h1>
                <p style="font-size:15px; color:#64748b; margin-bottom:28px; line-height:1.6;">
                    <?php esc_html_e('Manage your donations, supporters, and payment settings in the full dashboard.', 'buy-me-coffee'); ?>
                </p>
                <a href="<?php echo esc_url($appUrl); ?>"
                   style="display:inline-flex; align-items:center; gap:8px; padding:12px 28px; background:#0d9488; color:#fff; text-decoration:none; border-radius:8px; font-size:15px; font-weight:500; transition:background 0.2s;"
                   onmouseover="this.style.background='#0f766e'"
                   onmouseout="this.style.background='#0d9488'"
                >
                    <?php esc_html_e('Open Dashboard', 'buy-me-coffee'); ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <p style="font-size:13px; color:#94a3b8; margin-top:16px;">
                    <?php esc_html_e('The dashboard opens in a dedicated full-screen view.', 'buy-me-coffee'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
