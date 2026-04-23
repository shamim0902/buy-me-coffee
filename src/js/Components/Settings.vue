<template>
  <div class="bmc-settings relative min-h-[200px]">
    <CoffeeLoader :loading="fetching" />
    <PageTitle title="Settings" subtitle="Configure your donation page" />

    <template v-if="!fetching">
      <!-- General Settings -->
      <div class="bmc-card">
        <div class="bmc-card__header">
          <div>
            <h2 class="bmc-card__title">General Settings</h2>
            <p class="bmc-card__subtitle">Control what information is collected from supporters</p>
          </div>
        </div>

        <el-form label-position="top">
          <!-- Toggle: Show form title -->
          <div class="bmc-toggle-row">
            <div class="bmc-toggle-row__content">
              <p class="bmc-toggle-row__label">Show form title</p>
              <p class="bmc-toggle-row__desc">Display the title section on the donation form</p>
            </div>
            <el-switch
              v-model="template.formTitle"
              active-value="yes"
              inactive-value="no"
            />
          </div>

          <!-- Your Name (shown when form title is enabled) -->
          <div v-if="template.formTitle === 'yes'" class="bmc-field-indent">
            <el-form-item label="Your Name">
              <el-input v-model="template.yourName" placeholder="Enter your name" />
              <p class="bmc-field-hint">
                You can also use URL params, e.g. <code>https://page-link&amp;for=John</code>
              </p>
            </el-form-item>
          </div>

          <!-- Toggle: Collect name -->
          <div class="bmc-toggle-row">
            <div class="bmc-toggle-row__content">
              <p class="bmc-toggle-row__label">Collect supporter name</p>
              <p class="bmc-toggle-row__desc">Ask supporters for their name when donating</p>
            </div>
            <el-switch
              v-model="template.enableName"
              active-value="yes"
              inactive-value="no"
            />
          </div>

          <!-- Toggle: Collect email -->
          <div class="bmc-toggle-row">
            <div class="bmc-toggle-row__content">
              <p class="bmc-toggle-row__label">Collect supporter email</p>
              <p class="bmc-toggle-row__desc">Ask supporters for their email address</p>
            </div>
            <el-switch
              v-model="template.enableEmail"
              active-value="yes"
              inactive-value="no"
            />
          </div>

          <!-- Toggle: Collect message -->
          <div class="bmc-toggle-row">
            <div class="bmc-toggle-row__content">
              <p class="bmc-toggle-row__label">Collect message</p>
              <p class="bmc-toggle-row__desc">Allow supporters to leave a message with their donation</p>
            </div>
            <el-switch
              v-model="template.enableMessage"
              active-value="yes"
              inactive-value="no"
            />
          </div>

          <div class="bmc-form-grid">
            <!-- Default coffee price -->
            <el-form-item label="Default coffee price">
              <el-input-number
                v-model="template.defaultAmount"
                :min="1"
                :step="1"
                controls-position="right"
                class="w-full"
              />
            </el-form-item>

            <!-- Currency -->
            <el-form-item label="Currency">
              <el-select
                v-model="template.currency"
                filterable
                placeholder="Select Currency"
                class="w-full"
              >
                <el-option
                  v-for="(currencyName, currencyKey) in currencies"
                  :key="currencyKey"
                  :label="`${currencyKey} - ${currencyName}`"
                  :value="currencyKey"
                />
              </el-select>
            </el-form-item>
          </div>
        </el-form>
      </div>

      <!-- Appearance -->
      <div class="bmc-card">
        <div class="bmc-card__header">
          <div>
            <h2 class="bmc-card__title">Appearance</h2>
            <p class="bmc-card__subtitle">Customize the look and feel of your donation button and form</p>
          </div>
        </div>

        <div class="bmc-appearance-grid">
          <!-- Left column: settings -->
          <div class="bmc-appearance-grid__settings">
            <el-form label-position="top">
              <el-form-item label="Button text">
                <el-input v-model="template.buttonText" placeholder="Buy me a coffee" />
              </el-form-item>

              <div class="bmc-form-grid">
                <el-form-item label="Background color">
                  <div class="bmc-color-field">
                    <el-color-picker
                      v-model="template.advanced.bgColor"
                      show-alpha
                      :predefine="predefineColors"
                      @active-change="(val) => template.advanced.bgColor = val"
                    />
                    <span class="bmc-color-field__value">{{ template.advanced.bgColor }}</span>
                  </div>
                </el-form-item>

                <el-form-item label="Text color">
                  <div class="bmc-color-field">
                    <el-color-picker
                      v-model="template.advanced.color"
                      show-alpha
                      :predefine="predefineColors"
                      @active-change="(val) => template.advanced.color = val"
                    />
                    <span class="bmc-color-field__value">{{ template.advanced.color }}</span>
                  </div>
                </el-form-item>
              </div>

              <el-form-item label="Border radius (px)">
                <div class="flex items-center gap-4">
                  <el-slider
                    v-model="radiusNumber"
                    :min="0"
                    :max="50"
                    :show-tooltip="true"
                    class="flex-1"
                  />
                  <el-input-number
                    v-model="radiusNumber"
                    :min="0"
                    :max="50"
                    controls-position="right"
                    size="small"
                    class="w-24"
                  />
                </div>
              </el-form-item>

              <!-- Toggle: Form shadow -->
              <div class="bmc-toggle-row bmc-toggle-row--compact">
                <div class="bmc-toggle-row__content">
                  <p class="bmc-toggle-row__label">Form shadow</p>
                  <p class="bmc-toggle-row__desc">Add a subtle shadow to the form container</p>
                </div>
                <el-switch
                  v-model="template.advanced.formShadow"
                  active-value="yes"
                  inactive-value="no"
                />
              </div>

              <!-- Profile image -->
              <el-form-item label="Profile image">
                <div class="bmc-image-upload">
                  <img
                    class="bmc-image-upload__preview"
                    :src="template.advanced.image || fullPath('profile.png')"
                    alt="Profile"
                  />
                  <div class="bmc-image-upload__actions">
                    <MediaButton @onMediaSelected="onMediaSelected" />
                    <button
                      v-if="template.advanced.image"
                      type="button"
                      class="bmc-image-upload__remove"
                      @click="template.advanced.image = ''"
                    >
                      Remove
                    </button>
                  </div>
                </div>
              </el-form-item>

              <el-form-item label="Quote">
                <el-input
                  v-model="template.advanced.quote"
                  type="textarea"
                  :rows="3"
                  placeholder="Add an inspirational quote or message..."
                />
              </el-form-item>
            </el-form>
          </div>

          <!-- Right column: live preview -->
          <div class="bmc-appearance-grid__preview">
            <div class="bmc-preview-panel">
              <p class="bmc-preview-panel__label">
                <Eye :size="14" />
                Live Preview
              </p>
              <div class="bmc-preview-panel__stage">
                <button
                  type="button"
                  class="bmc-preview-btn"
                  :style="previewButtonStyle"
                  @click="openPreview"
                >
                  <Coffee :size="16" />
                  {{ template.buttonText || 'Buy me a coffee' }}
                </button>
              </div>
              <a
                class="bmc-preview-panel__link"
                :href="previewUrl"
                target="_blank"
              >
                <Eye :size="14" />
                Open full preview
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Shortcodes -->
      <div class="bmc-card">
        <div class="bmc-card__header">
          <div>
            <h2 class="bmc-card__title">Shortcodes</h2>
            <p class="bmc-card__subtitle">Embed your donation form or button anywhere on your site</p>
          </div>
        </div>

        <div class="bmc-shortcodes-grid">
          <CodeBlock label="Button only" code="[buymecoffee_button]" />
          <CodeBlock label="Donation form" code="[buymecoffee_form]" />
          <CodeBlock label="Full page layout" code="[buymecoffee_basic]" />
        </div>

        <div class="bmc-shortcode-tip">
          <p class="bmc-shortcode-tip__text">
            You can also use the block editor to insert these components, or use a custom amount:
            <code>[buymecoffee_basic custom=10]</code>
          </p>
          <a
            class="bmc-shortcode-tip__link"
            :href="previewUrl"
            target="_blank"
          >
            <Eye :size="14" />
            Preview page
          </a>
        </div>
      </div>

      <!-- Action Bar -->
      <div class="bmc-action-bar">
        <el-popconfirm
          title="Are you sure you want to reset all settings to their defaults?"
          confirm-button-text="Yes, reset"
          cancel-button-text="Cancel"
          @confirm="resetDefault"
        >
          <template #reference>
            <el-button :loading="resetting" plain>
              <RotateCcw :size="15" class="mr-1.5" />
              Reset to Default
            </el-button>
          </template>
        </el-popconfirm>

        <el-button type="primary" :loading="saving" @click="saveSettings">
          <Save :size="15" class="mr-1.5" />
          Save Settings
        </el-button>
      </div>
    </template>
  </div>
</template>

<script>
import { Save, RotateCcw, Eye, Coffee } from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import CodeBlock from './UI/CodeBlock.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import MediaButton from './Parts/MediaButton.vue';

export default {
  name: 'Settings',
  components: {
    Save,
    RotateCcw,
    Eye,
    Coffee,
    PageTitle,
    CodeBlock,
    MediaButton,
    CoffeeLoader
  },
  data() {
    return {
      fetching: true,
      saving: false,
      resetting: false,
      currencies: {},
      previewUrl: window.BuyMeCoffeeAdmin.preview_url,
      predefineColors: [
        '#ff4500',
        '#ff8c00',
        '#ffd700',
        '#90ee90',
        '#00ced1',
        '#1e90ff',
        '#c71585',
        'rgba(255, 69, 0, 0.68)',
        'rgb(255, 120, 0)',
        '#c7158577',
        '#FFF',
        '#000000'
      ],
      template: {
        yourName: '',
        buttonText: '',
        enableMessage: 'no',
        formTitle: 'no',
        enableName: 'no',
        enableEmail: 'no',
        defaultAmount: 5,
        custom_coffee: '',
        openMode: 'page',
        currency: 'USD',
        advanced: {
          image: '',
          enable: '',
          bgColor: '#ff813f',
          color: '#ffffff',
          formShadow: 'no',
          minWidth: '',
          textAlign: '',
          minHeight: '',
          fontSize: '16',
          radius: '8',
          button_style: '',
          bg_style: '',
          border_style: '',
          quote: ''
        }
      }
    };
  },
  computed: {
    radiusNumber: {
      get() {
        return parseInt(this.template.advanced.radius) || 0;
      },
      set(val) {
        this.template.advanced.radius = String(val);
      }
    },
    previewButtonStyle() {
      return {
        backgroundColor: this.template.advanced.bgColor,
        color: this.template.advanced.color,
        borderRadius: (this.template.advanced.radius || 0) + 'px'
      };
    }
  },
  methods: {
    onMediaSelected(selected) {
      if (selected.length) {
        this.template.advanced.image = selected[0].url;
      }
    },
    fullPath(path) {
      return window.BuyMeCoffeeAdmin.assets_url + 'images/' + path;
    },
    openPreview() {
      window.open(this.previewUrl);
    },
    getSettings() {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_settings',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((res) => {
          this.template = res.data.template;
          this.currencies = res.data.currencies;
        })
        .fail((error) => {
          this.$message.error(error?.responseJSON?.data?.message || 'Failed to load settings');
        })
        .always(() => {
          this.fetching = false;
        });
    },
    saveSettings() {
      this.saving = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'save_settings',
        data: this.template,
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((response) => {
          this.$handleSuccess(response.data.message);
        })
        .fail((error) => {
          this.$message.error(error?.responseJSON?.data?.message || 'Failed to save settings');
        })
        .always(() => {
          this.saving = false;
        });
    },
    resetDefault() {
      this.resetting = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'reset_template_settings',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then((response) => {
          this.$handleSuccess(response.data.message);
          this.template = response.data.settings;
        })
        .fail((error) => {
          this.$message.error(error?.responseJSON?.data?.message || 'Failed to reset settings');
        })
        .always(() => {
          this.resetting = false;
        });
    }
  },
  mounted() {
    this.getSettings();
  }
};
</script>

<style scoped>
.bmc-settings {
  max-width: 960px;
}

/* Card */
.bmc-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 24px;
}

.bmc-card__header {
  margin-bottom: 20px;
}

.bmc-card__title {
  font-size: 16px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.4;
}

.bmc-card__subtitle {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 4px 0 0;
}

/* Toggle rows */
.bmc-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 0;
  border-bottom: 1px solid var(--border-primary);
}

.bmc-toggle-row:first-child {
  padding-top: 0;
}

.bmc-toggle-row--compact {
  border-bottom: none;
  padding: 10px 0;
}

.bmc-toggle-row__content {
  flex: 1;
  min-width: 0;
}

.bmc-toggle-row__label {
  font-size: 14px;
  font-weight: 500;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.4;
}

.bmc-toggle-row__desc {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 2px 0 0;
  line-height: 1.4;
}

/* Field indent (for conditional fields) */
.bmc-field-indent {
  padding: 12px 0 4px 0;
  border-bottom: 1px solid var(--border-primary);
}

.bmc-field-hint {
  font-size: 12px;
  color: var(--text-tertiary);
  margin: 4px 0 0;
  line-height: 1.5;
}

.bmc-field-hint code {
  font-family: var(--font-mono);
  font-size: 11px;
  background: var(--bg-tertiary);
  padding: 1px 5px;
  border-radius: 4px;
}

/* Form grid (two columns) */
.bmc-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 20px;
  margin-top: 16px;
}

@media (max-width: 640px) {
  .bmc-form-grid {
    grid-template-columns: 1fr;
  }
}

/* Appearance two-column layout */
.bmc-appearance-grid {
  display: grid;
  grid-template-columns: 3fr 2fr;
  gap: 24px;
  align-items: start;
}

@media (max-width: 860px) {
  .bmc-appearance-grid {
    grid-template-columns: 1fr;
  }
}

.bmc-appearance-grid__settings {
  min-width: 0;
}

.bmc-appearance-grid__preview {
  position: sticky;
  top: 40px;
}

/* Color field with swatch + value */
.bmc-color-field {
  display: flex;
  align-items: center;
  gap: 10px;
}

.bmc-color-field__value {
  font-size: 13px;
  font-family: var(--font-mono);
  color: var(--text-secondary);
}

/* Image upload */
.bmc-image-upload {
  display: flex;
  align-items: center;
  gap: 16px;
}

.bmc-image-upload__preview {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--border-primary);
  flex-shrink: 0;
}

.bmc-image-upload__actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.bmc-image-upload__remove {
  background: none;
  border: none;
  font-size: 13px;
  color: var(--text-tertiary);
  cursor: pointer;
  padding: 2px 0;
  text-align: left;
  transition: color 0.15s ease;
}

.bmc-image-upload__remove:hover {
  color: var(--color-danger-600, #dc2626);
}

/* Preview panel */
.bmc-preview-panel {
  background: var(--bg-tertiary);
  border: 1px solid var(--border-primary);
  border-radius: 10px;
  padding: 20px;
}

.bmc-preview-panel__label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-secondary);
  margin: 0 0 20px;
}

.bmc-preview-panel__stage {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 16px;
  background: var(--bg-primary);
  border: 1px dashed var(--border-primary);
  border-radius: 8px;
  margin-bottom: 16px;
}

.bmc-preview-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 24px;
  border: none;
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  transition: opacity 0.15s ease;
  white-space: nowrap;
}

.bmc-preview-btn:hover {
  opacity: 0.9;
}

.bmc-preview-panel__link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-primary-600);
  text-decoration: none;
  cursor: pointer;
  transition: color 0.15s ease;
}

.bmc-preview-panel__link:hover {
  color: var(--color-primary-700, var(--color-primary-600));
  text-decoration: none;
}

/* Shortcodes grid */
.bmc-shortcodes-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 16px;
}

@media (max-width: 768px) {
  .bmc-shortcodes-grid {
    grid-template-columns: 1fr;
  }
}

.bmc-shortcode-tip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 16px;
  background: var(--bg-tertiary);
  border-radius: 8px;
}

.bmc-shortcode-tip__text {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 0;
  line-height: 1.5;
}

.bmc-shortcode-tip__text code {
  font-family: var(--font-mono);
  font-size: 12px;
  background: var(--bg-primary);
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid var(--border-primary);
}

.bmc-shortcode-tip__link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-primary-600);
  text-decoration: none;
  white-space: nowrap;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.15s ease;
}

.bmc-shortcode-tip__link:hover {
  color: var(--color-primary-700, var(--color-primary-600));
}

/* Action bar */
.bmc-action-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px 24px;
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 12px;
  position: sticky;
  bottom: 16px;
  box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.06);
}

/* Utility */
.w-full {
  width: 100%;
}

.w-24 {
  width: 96px;
}
</style>
