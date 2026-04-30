<?php if (!defined('ABSPATH')) exit; ?>

<div id="buymecoffee-deactivate-modal" style="display:none;">
    <div class="bmc-deactivate-overlay"></div>
    <div class="bmc-deactivate-dialog">
        <div class="bmc-deactivate-header">
            <img src="<?php echo esc_url($logo); ?>" alt="Buy Me Coffee" class="bmc-deactivate-logo" />
            <h3><?php esc_html_e('Quick Feedback', 'buy-me-coffee'); ?></h3>
            <p><?php esc_html_e('If you have a moment, please let us know why you are deactivating:', 'buy-me-coffee'); ?></p>
        </div>

        <div class="bmc-deactivate-body">
            <ul class="bmc-deactivate-reasons">
                <li>
                    <label>
                        <input type="checkbox" name="bmc_reason[]" value="temporary">
                        <?php esc_html_e("I'm only deactivating temporarily", 'buy-me-coffee'); ?>
                    </label>
                </li>
                <li>
                    <label>
                        <input type="checkbox" name="bmc_reason[]" value="missing_feature" data-show="bmc-field-feature">
                        <?php esc_html_e("Doesn't have the feature I need", 'buy-me-coffee'); ?>
                    </label>
                    <input type="text" id="bmc-field-feature" class="bmc-deactivate-extra" maxlength="200" placeholder="<?php esc_attr_e('What feature do you need?', 'buy-me-coffee'); ?>" style="display:none;" />
                </li>
                <li>
                    <label>
                        <input type="checkbox" name="bmc_reason[]" value="no_longer_needed">
                        <?php esc_html_e('I no longer need the plugin', 'buy-me-coffee'); ?>
                    </label>
                </li>
                <li>
                    <label>
                        <input type="checkbox" name="bmc_reason[]" value="stopped_working">
                        <?php esc_html_e('The plugin is not working properly', 'buy-me-coffee'); ?>
                    </label>
                </li>
                <li>
                    <label>
                        <input type="checkbox" name="bmc_reason[]" value="other" data-show="bmc-field-other">
                        <?php esc_html_e('Other', 'buy-me-coffee'); ?>
                    </label>
                    <textarea id="bmc-field-other" class="bmc-deactivate-extra" maxlength="1000" placeholder="<?php esc_attr_e('Please share the details...', 'buy-me-coffee'); ?>" rows="3" style="display:none;"></textarea>
                </li>
            </ul>

            <p class="bmc-deactivate-error" style="display:none;">
                <?php esc_html_e('Please select at least one reason.', 'buy-me-coffee'); ?>
            </p>
        </div>

        <div class="bmc-deactivate-footer">
            <button class="button bmc-btn-skip" id="bmc-skip-deactivate">
                <?php esc_html_e('Skip & Deactivate', 'buy-me-coffee'); ?>
            </button>
            <button class="button button-primary bmc-btn-submit" id="bmc-submit-deactivate">
                <?php esc_html_e('Submit & Deactivate', 'buy-me-coffee'); ?>
            </button>
            <button class="button bmc-btn-cancel" id="bmc-cancel-deactivate">
                <?php esc_html_e('Cancel', 'buy-me-coffee'); ?>
            </button>
        </div>
    </div>
</div>
