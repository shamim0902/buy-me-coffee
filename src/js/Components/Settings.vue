<template>
  <div class="relative min-h-[200px]">
    <CoffeeLoader :loading="fetching" />

    <template v-if="!fetching">
      <PageTitle title="Settings" subtitle="Manage your donation form preferences and payment configuration" />

      <div class="bmc-settings-layout">

        <!-- ── Content (full width, section chosen by sidebar sub-nav) ── -->
        <div class="bmc-settings-content">

          <!-- General -->
          <section v-show="active === 'general'">

            <!-- Form Configuration Card -->
            <div class="bmc-sc">
              <div class="bmc-sc__hd">
                <div class="bmc-sc__icon bmc-sc__icon--purple">
                  <FileText :size="16" />
                </div>
                <div>
                  <h3 class="bmc-sc__title">Form Configuration</h3>
                  <p class="bmc-sc__sub">Control what information is collected from supporters</p>
                </div>
              </div>

              <!-- Show form title -->
              <div class="bmc-sr">
                <div class="bmc-toggle-row__text">
                  <p class="bmc-toggle-row__label">Show form title</p>
                  <p class="bmc-toggle-row__desc">Display the title section on the donation form</p>
                </div>
                <el-switch v-model="template.formTitle" active-value="yes" inactive-value="no" />
              </div>

              <!-- Your name (conditional) -->
              <div v-if="template.formTitle === 'yes'" class="bmc-sr bmc-sr--field">
                <label class="bmc-label">Your name</label>
                <el-input v-model="template.yourName" placeholder="Enter your name" />
                <p class="bmc-hint bmc-hint--teal">
                  You can also use URL params, e.g. <code>https://page-link&amp;for=John</code>
                </p>
              </div>

              <!-- Collect name -->
              <div class="bmc-sr">
                <div class="bmc-toggle-row__text">
                  <p class="bmc-toggle-row__label">Collect supporter name</p>
                  <p class="bmc-toggle-row__desc">Ask supporters for their name when donating</p>
                </div>
                <el-switch v-model="template.enableName" active-value="yes" inactive-value="no" />
              </div>

              <!-- Collect email -->
              <div class="bmc-sr">
                <div class="bmc-toggle-row__text">
                  <p class="bmc-toggle-row__label">Collect supporter email</p>
                  <p class="bmc-toggle-row__desc">Ask supporters for their email address</p>
                </div>
                <el-switch v-model="template.enableEmail" active-value="yes" inactive-value="no" />
              </div>

              <!-- Collect message -->
              <div class="bmc-sr bmc-sr--last">
                <div class="bmc-toggle-row__text">
                  <p class="bmc-toggle-row__label">Collect message</p>
                  <p class="bmc-toggle-row__desc">Allow supporters to leave a message with their donation</p>
                </div>
                <el-switch v-model="template.enableMessage" active-value="yes" inactive-value="no" />
              </div>
            </div>

            <!-- Pricing & Payment Card -->
            <div class="bmc-sc">
              <div class="bmc-sc__hd">
                <div class="bmc-sc__icon bmc-sc__icon--teal">
                  <DollarSign :size="16" />
                </div>
                <div>
                  <h3 class="bmc-sc__title">Pricing &amp; Payment</h3>
                  <p class="bmc-sc__sub">Configure donation amounts and currency</p>
                </div>
              </div>

              <!-- Price + Currency -->
              <div class="bmc-sr bmc-sr--field bmc-sr--2col">
                <div>
                  <label class="bmc-label">Default coffee price</label>
                  <el-input-number
                    v-model="template.defaultAmount"
                    :min="1"
                    :step="1"
                    controls-position="right"
                    style="width: 100%"
                  />
                </div>
                <div>
                  <label class="bmc-label">Currency</label>
                  <el-select
                    v-model="template.currency"
                    filterable
                    placeholder="Select Currency"
                    style="width: 100%"
                  >
                    <el-option
                      v-for="(currencyName, currencyKey) in currencies"
                      :key="currencyKey"
                      :label="`${currencyKey} — ${currencyName}`"
                      :value="currencyKey"
                    />
                  </el-select>
                </div>
              </div>

              <!-- Allow recurring -->
              <div class="bmc-sr" :class="{ 'bmc-sr--last': template.allow_recurring !== 'yes' }">
                <div class="bmc-toggle-row__text">
                  <p class="bmc-toggle-row__label">Allow recurring</p>
                  <p class="bmc-toggle-row__desc">Show a "Make it recurring" option on the checkout form (Stripe only)</p>
                </div>
                <el-switch v-model="template.allow_recurring" active-value="yes" inactive-value="no" />
              </div>

              <!-- Recurring interval -->
              <div v-if="template.allow_recurring === 'yes'" class="bmc-sr bmc-sr--last">
                <div class="bmc-toggle-row__text">
                  <p class="bmc-toggle-row__label">Recurring interval</p>
                  <p class="bmc-toggle-row__desc">How often supporters will be charged</p>
                </div>
                <el-select v-model="template.recurring_interval" style="width: 130px">
                  <el-option label="Monthly" value="month" />
                  <el-option label="Yearly" value="year" />
                </el-select>
              </div>
            </div>
          </section>

          <!-- Appearance -->
          <section v-show="active === 'appearance'">
            <div class="bmc-section-header">
              <h2 class="bmc-section-title">Appearance</h2>
              <p class="bmc-section-sub">Customize your profile and donation button style</p>
            </div>

            <div class="bmc-appearance-grid">
              <!-- ── Left: settings ── -->
              <div>

                <!-- Profile card -->
                <div class="bmc-card">
                  <h3 class="bmc-card-title">
                    <UserCircle2 :size="15" />
                    Profile
                  </h3>

                  <!-- Avatar upload -->
                  <div class="bmc-avatar-row">
                    <div class="bmc-avatar-wrap" @click="$refs.mediaBtn.$el.click()">
                      <img
                        class="bmc-avatar-img"
                        :src="template.advanced.image || fullPath('profile.png')"
                        alt="Profile"
                      />
                      <div class="bmc-avatar-overlay">
                        <Camera :size="18" />
                        <span>Change</span>
                      </div>
                    </div>
                    <div class="bmc-avatar-meta">
                      <p class="bmc-avatar-meta__title">Profile Photo</p>
                      <p class="bmc-avatar-meta__hint">Shown above your donation form. Recommended 200×200 px.</p>
                      <div class="bmc-avatar-meta__actions">
                        <MediaButton ref="mediaBtn" @onMediaSelected="onMediaSelected" />
                        <button
                          v-if="template.advanced.image"
                          type="button"
                          class="bmc-text-btn bmc-text-btn--danger"
                          @click.stop="template.advanced.image = ''"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- Quote -->
                  <div class="bmc-field-row" style="margin-top: 20px">
                    <label class="bmc-label">Bio / tagline</label>
                    <el-input
                      v-model="template.advanced.quote"
                      type="textarea"
                      :rows="2"
                      placeholder="e.g. Support my open-source work ☕"
                    />
                    <p class="bmc-hint">Displayed beneath your profile on the donation page.</p>
                  </div>

                  <!-- Banner image -->
                  <div class="bmc-field-row" style="margin-bottom: 0">
                    <label class="bmc-label">Cover / Banner Image</label>
                    <div v-if="template.advanced.banner_image" class="bmc-banner-preview">
                      <img :src="template.advanced.banner_image" alt="Banner preview" />
                      <button
                        type="button"
                        class="bmc-text-btn bmc-text-btn--danger"
                        @click="template.advanced.banner_image = ''"
                      >
                        Remove
                      </button>
                    </div>
                    <MediaButton ref="bannerMediaBtn" @onMediaSelected="onBannerSelected" />
                    <p class="bmc-hint">Full-width cover at the top of your donation page. Recommended 1200×400 px.</p>
                  </div>
                </div>

                <!-- Button style card -->
                <div class="bmc-card">
                  <h3 class="bmc-card-title">
                    <MousePointerClick :size="15" />
                    Button Style
                  </h3>

                  <!-- Button text -->
                  <div class="bmc-field-row">
                    <label class="bmc-label">Button label</label>
                    <el-input v-model="template.buttonText" placeholder="Buy me a coffee" />
                  </div>

                  <!-- Colors -->
                  <div class="bmc-field-row">
                    <label class="bmc-label">Colors</label>
                    <div class="bmc-color-stack">
                      <!-- Background color -->
                      <div class="bmc-color-swatch-row">
                        <div
                          class="bmc-color-swatch"
                          :style="{ background: template.advanced.bgColor }"
                        />
                        <div class="bmc-color-swatch-info">
                          <span class="bmc-color-swatch-info__label">Background</span>
                          <span class="bmc-color-swatch-info__value">{{ template.advanced.bgColor }}</span>
                        </div>
                        <el-color-picker
                          v-model="template.advanced.bgColor"
                          show-alpha
                          :predefine="predefineColors"
                          @active-change="(val) => template.advanced.bgColor = val"
                        />
                      </div>
                      <!-- Text color -->
                      <div class="bmc-color-swatch-row">
                        <div
                          class="bmc-color-swatch"
                          :style="{ background: template.advanced.color }"
                        />
                        <div class="bmc-color-swatch-info">
                          <span class="bmc-color-swatch-info__label">Text</span>
                          <span class="bmc-color-swatch-info__value">{{ template.advanced.color }}</span>
                        </div>
                        <el-color-picker
                          v-model="template.advanced.color"
                          show-alpha
                          :predefine="predefineColors"
                          @active-change="(val) => template.advanced.color = val"
                        />
                      </div>
                    </div>
                  </div>

                  <!-- Border radius -->
                  <div class="bmc-field-row">
                    <div class="bmc-label-row">
                      <label class="bmc-label">Corner radius</label>
                      <span class="bmc-label-value">{{ radiusNumber }}px</span>
                    </div>
                    <div class="bmc-slider-row">
                      <button
                        class="bmc-radius-preset"
                        :class="{ 'bmc-radius-preset--active': radiusNumber === 0 }"
                        @click="radiusNumber = 0"
                        title="Square"
                      >
                        <span class="bmc-radius-preview bmc-radius-preview--0" />
                      </button>
                      <button
                        class="bmc-radius-preset"
                        :class="{ 'bmc-radius-preset--active': radiusNumber === 8 }"
                        @click="radiusNumber = 8"
                        title="Rounded"
                      >
                        <span class="bmc-radius-preview bmc-radius-preview--8" />
                      </button>
                      <button
                        class="bmc-radius-preset"
                        :class="{ 'bmc-radius-preset--active': radiusNumber === 50 }"
                        @click="radiusNumber = 50"
                        title="Pill"
                      >
                        <span class="bmc-radius-preview bmc-radius-preview--50" />
                      </button>
                      <el-slider
                        v-model="radiusNumber"
                        :min="0"
                        :max="50"
                        :show-tooltip="false"
                        style="flex: 1; margin: 0 4px 0 12px"
                      />
                      <el-input-number
                        v-model="radiusNumber"
                        :min="0"
                        :max="50"
                        controls-position="right"
                        size="small"
                        style="width: 80px"
                      />
                    </div>
                  </div>

                  <!-- Form shadow toggle -->
                  <div class="bmc-toggle-row bmc-toggle-row--last" style="margin-top: 4px">
                    <div class="bmc-toggle-row__text">
                      <p class="bmc-toggle-row__label">Drop shadow</p>
                      <p class="bmc-toggle-row__desc">Add a subtle shadow to the donation form</p>
                    </div>
                    <el-switch v-model="template.advanced.formShadow" active-value="yes" inactive-value="no" />
                  </div>
                </div>

              </div>

              <!-- ── Right: live preview ── -->
              <div class="bmc-preview-sticky">
                <div class="bmc-preview-panel">
                  <p class="bmc-preview-panel__label">
                    <Eye :size="13" />
                    Live Preview
                  </p>

                  <!-- Mini donation form mockup -->
                  <div class="bmc-preview-mockup">
                    <img
                      class="bmc-preview-mockup__avatar"
                      :src="template.advanced.image || fullPath('profile.png')"
                      alt=""
                    />
                    <p v-if="template.advanced.quote" class="bmc-preview-mockup__quote">
                      "{{ template.advanced.quote }}"
                    </p>
                    <p v-else class="bmc-preview-mockup__quote bmc-preview-mockup__quote--placeholder">
                      Your tagline appears here
                    </p>

                    <div class="bmc-preview-mockup__amounts">
                      <span class="bmc-preview-mockup__pill">1x</span>
                      <span class="bmc-preview-mockup__pill bmc-preview-mockup__pill--active">3x</span>
                      <span class="bmc-preview-mockup__pill">5x</span>
                    </div>

                    <button
                      type="button"
                      class="bmc-preview-btn"
                      :style="previewButtonStyle"
                    >
                      <Coffee :size="14" />
                      {{ template.buttonText || 'Buy me a coffee' }}
                    </button>
                  </div>

                  <a class="bmc-preview-panel__link" :href="previewUrl" target="_blank">
                    <ExternalLink :size="13" />
                    Open full donation page
                  </a>
                </div>
              </div>
            </div>
          </section>

          <!-- Shortcodes -->
          <section v-show="active === 'shortcodes'">
            <div class="bmc-section-header">
              <h2 class="bmc-section-title">Shortcodes</h2>
              <p class="bmc-section-sub">Embed your donation form or button anywhere on your site</p>
            </div>

            <div class="bmc-card">
              <div class="bmc-shortcodes-grid">
                <CodeBlock label="Button only" code="[buymecoffee_button]" />
                <CodeBlock label="Donation form" code="[buymecoffee_form]" />
                <CodeBlock label="Full page layout" code="[buymecoffee_basic]" />
              </div>
            </div>

            <div class="bmc-card">
              <h3 class="bmc-section-title bmc-section-title--sm" style="margin-bottom: 12px">Custom amount</h3>
              <p class="text-sm" style="color: var(--text-secondary); margin: 0 0 12px">
                You can pre-fill a specific coffee price using the <code class="bmc-inline-code">custom</code> attribute:
              </p>
              <CodeBlock label="Custom amount (e.g. $10)" code="[buymecoffee_basic custom=10]" />
            </div>

            <div class="bmc-card">
              <h3 class="bmc-section-title bmc-section-title--sm" style="margin-bottom: 12px">Standalone donation page</h3>
              <p class="text-sm" style="color: var(--text-secondary); margin: 0 0 12px">
                Share this URL directly to send supporters to a full-screen donation page:
              </p>
              <CodeBlock label="Donation page URL" :code="previewUrl" />
            </div>
          </section>

        </div>

      </div>

      <!-- ── Sticky action bar ── -->
      <div class="bmc-action-bar">
        <el-popconfirm
          title="Reset all settings to their defaults?"
          confirm-button-text="Yes, reset"
          cancel-button-text="Cancel"
          @confirm="resetDefault"
        >
          <template #reference>
            <button class="bmc-action-btn bmc-action-btn--ghost" :disabled="resetting">
              <RotateCcw :size="14" />
              {{ resetting ? 'Resetting…' : 'Reset to Default' }}
            </button>
          </template>
        </el-popconfirm>

        <button class="bmc-action-btn bmc-action-btn--primary" :disabled="saving" @click="saveSettings">
          <Save :size="14" />
          {{ saving ? 'Saving…' : 'Save Settings' }}
        </button>
      </div>
    </template>
  </div>
</template>

<script>
import { Save, RotateCcw, Eye, Coffee, ExternalLink, UserCircle2, Camera, MousePointerClick, FileText, DollarSign } from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import CodeBlock from './UI/CodeBlock.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import MediaButton from './Parts/MediaButton.vue';

export default {
  name: 'Settings',
  components: { Save, RotateCcw, Eye, Coffee, ExternalLink, UserCircle2, Camera, MousePointerClick, FileText, DollarSign, PageTitle, CodeBlock, MediaButton, CoffeeLoader },

  data() {
    return {
      fetching: true,
      saving: false,
      resetting: false,
      currencies: {},
      previewUrl: window.BuyMeCoffeeAdmin.preview_url,
      predefineColors: [
        '#ff813f', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6',
        '#ec4899', '#ef4444', '#ffffff', '#000000',
      ],
      template: {
        yourName: '',
        buttonText: '',
        enableMessage: 'no',
        formTitle: 'no',
        enableName: 'no',
        enableEmail: 'no',
        defaultAmount: 5,
        currency: 'USD',
        allow_recurring: 'no',
        recurring_interval: 'month',
        advanced: {
          image: '',
          bgColor: '#ff813f',
          color: '#ffffff',
          formShadow: 'no',
          fontSize: '16',
          radius: '8',
          quote: '',
        },
      },
    };
  },

  computed: {
    active() {
      return this.$route.query.tab || 'general';
    },
    radiusNumber: {
      get() { return parseInt(this.template.advanced.radius) || 0; },
      set(val) { this.template.advanced.radius = String(val); },
    },
    previewButtonStyle() {
      return {
        backgroundColor: this.template.advanced.bgColor,
        color: this.template.advanced.color,
        borderRadius: (this.template.advanced.radius || 0) + 'px',
      };
    },
  },

  methods: {
    onMediaSelected(selected) {
      if (selected.length) this.template.advanced.image = selected[0].url;
    },
    onBannerSelected(selected) {
      if (selected.length) this.template.advanced.banner_image = selected[0].url;
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
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((res) => {
        this.template = res.data.template;
        this.currencies = res.data.currencies;
      }).fail((error) => {
        this.$message.error(error?.responseJSON?.data?.message || 'Failed to load settings');
      }).always(() => {
        this.fetching = false;
      });
    },
    saveSettings() {
      this.saving = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'save_settings',
        data: this.template,
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        this.$handleSuccess(response.data.message);
      }).fail((error) => {
        this.$message.error(error?.responseJSON?.data?.message || 'Failed to save settings');
      }).always(() => {
        this.saving = false;
      });
    },
    resetDefault() {
      this.resetting = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'reset_template_settings',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        this.$handleSuccess(response.data.message);
        this.template = response.data.settings;
      }).fail((error) => {
        this.$message.error(error?.responseJSON?.data?.message || 'Failed to reset settings');
      }).always(() => {
        this.resetting = false;
      });
    },
  },

  mounted() {
    this.getSettings();
  },
};
</script>

<style scoped>
/* ─── Settings cards (General section) ────── */
.bmc-sc {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 12px;
  margin-bottom: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
  overflow: hidden;
}

.bmc-sc__hd {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-secondary);
}

.bmc-sc__icon {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.bmc-sc__icon--purple {
  background: var(--color-accent-purple-light);
  color: var(--color-accent-purple);
}

.bmc-sc__icon--teal {
  background: #d5fcf0;
  color: var(--color-accent-teal);
}

.bmc-sc__title {
  font-family: var(--font-display);
  font-size: 15px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.3;
}

.bmc-sc__sub {
  font-size: 11.5px;
  color: var(--text-tertiary);
  margin: 2px 0 0;
  line-height: 1.4;
}

/* ─── Settings rows ──────────────────────── */
.bmc-sr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 0 20px;
  min-height: 56px;
  border-bottom: 1px solid var(--border-secondary);
}

.bmc-sr--last {
  border-bottom: none;
}

.bmc-sr--field {
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  min-height: auto;
  padding: 14px 20px;
}

.bmc-sr--2col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

@media (max-width: 640px) {
  .bmc-sr--2col { grid-template-columns: 1fr; }
}

/* ─── Hint teal variant ──────────────────── */
.bmc-hint--teal {
  color: var(--color-accent-teal);
}

/* ─── Action bar buttons ─────────────────── */
.bmc-action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 38px;
  padding: 0 16px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 500;
  font-family: var(--font-sans);
  cursor: pointer;
  transition: all 0.15s ease;
  white-space: nowrap;
}

.bmc-action-btn--ghost {
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  color: var(--text-secondary);
}

.bmc-action-btn--ghost:hover:not(:disabled) {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}

.bmc-action-btn--primary {
  background: linear-gradient(180deg, var(--color-primary-500) 0%, var(--color-primary-600) 100%);
  border: none;
  color: #fff;
  font-weight: 600;
  padding: 0 20px;
  box-shadow: 0 2px 8px rgba(139, 92, 246, 0.25);
}

.bmc-action-btn--primary:hover:not(:disabled) {
  opacity: 0.9;
}

.bmc-action-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* ─── Layout ─────────────────────────────── */
.bmc-settings-layout {
  margin-bottom: 20px;
}

/* ─── Content area ───────────────────────── */
.bmc-settings-content {
  min-width: 0;
}

/* ─── Section header ─────────────────────── */
.bmc-section-header {
  margin-bottom: 14px;
}

.bmc-section-header--sm {
  margin-bottom: 10px;
}

.bmc-section-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
}

.bmc-section-title--sm {
  font-size: 13.5px;
}

.bmc-section-sub {
  font-size: 12.5px;
  color: var(--text-secondary);
  margin: 3px 0 0;
}

/* ─── Cards ──────────────────────────────── */
.bmc-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 12px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.bmc-card--no-mb {
  margin-bottom: 0;
}

/* ─── Toggle rows ────────────────────────── */
.bmc-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 11px 0;
  border-bottom: 1px solid var(--border-primary);
}

.bmc-toggle-row--last {
  border-bottom: none;
}

.bmc-toggle-row--compact {
  padding: 10px 0;
}

.bmc-toggle-row__text {
  flex: 1;
  min-width: 0;
}

.bmc-toggle-row__label {
  font-size: 13.5px;
  font-weight: 500;
  color: var(--text-primary);
  margin: 0;
}

.bmc-toggle-row__desc {
  font-size: 11.5px;
  color: var(--text-secondary);
  margin: 2px 0 0;
}

/* ─── Sub-field (conditional indent) ─────── */
.bmc-sub-field {
  padding: 12px 0 4px 0;
  border-bottom: 1px solid var(--border-primary);
}

/* ─── Generic field row ──────────────────── */
.bmc-field-row {
  margin-bottom: 18px;
}

.bmc-field-row:last-child {
  margin-bottom: 0;
}

/* ─── Label ──────────────────────────────── */
.bmc-label {
  display: block;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--text-secondary);
  margin-bottom: 5px;
}

/* ─── Hint ───────────────────────────────── */
.bmc-hint {
  font-size: 12px;
  color: var(--text-tertiary);
  margin: 5px 0 0;
}

.bmc-hint code {
  font-family: var(--font-mono);
  font-size: 11px;
  background: var(--bg-tertiary);
  padding: 1px 5px;
  border-radius: 4px;
}

/* ─── Two-col grid ───────────────────────── */
.bmc-two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-top: 14px;
}

@media (max-width: 640px) {
  .bmc-two-col { grid-template-columns: 1fr; }
}

/* ─── Color row ──────────────────────────── */
.bmc-color-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.bmc-color-row__value {
  font-size: 12.5px;
  font-family: var(--font-mono);
  color: var(--text-secondary);
}

/* ─── Image upload ───────────────────────── */
.bmc-image-upload {
  display: flex;
  align-items: center;
  gap: 16px;
}

.bmc-image-upload__preview {
  width: 64px;
  height: 64px;
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
  padding: 0;
  text-align: left;
  transition: color 0.15s;
}

.bmc-image-upload__remove:hover {
  color: #dc2626;
}

/* ─── Card title ─────────────────────────── */
.bmc-card-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 16px;
}

/* ─── Appearance two-column ──────────────── */
.bmc-appearance-grid {
  display: grid;
  grid-template-columns: 1fr 240px;
  gap: 14px;
  align-items: start;
}

@media (max-width: 860px) {
  .bmc-appearance-grid { grid-template-columns: 1fr; }
}

/* ─── Avatar upload ──────────────────────── */
.bmc-avatar-row {
  display: flex;
  align-items: flex-start;
  gap: 20px;
}

.bmc-avatar-wrap {
  position: relative;
  flex-shrink: 0;
  cursor: pointer;
}

.bmc-avatar-img {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--border-primary);
  display: block;
  transition: opacity 0.2s;
}

.bmc-avatar-wrap:hover .bmc-avatar-img {
  opacity: 0.6;
}

.bmc-avatar-overlay {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  color: var(--text-primary);
  font-size: 11px;
  font-weight: 600;
  opacity: 0;
  transition: opacity 0.2s;
}

.bmc-avatar-wrap:hover .bmc-avatar-overlay {
  opacity: 1;
}

.bmc-avatar-meta {
  flex: 1;
  min-width: 0;
}

.bmc-avatar-meta__title {
  font-size: 13.5px;
  font-weight: 500;
  color: var(--text-primary);
  margin: 0 0 4px;
}

.bmc-avatar-meta__hint {
  font-size: 12px;
  color: var(--text-tertiary);
  margin: 0 0 10px;
  line-height: 1.4;
}

.bmc-avatar-meta__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

/* ─── Text button ────────────────────────── */
.bmc-text-btn {
  background: none;
  border: none;
  font-size: 13px;
  font-family: var(--font-sans);
  cursor: pointer;
  padding: 0;
  transition: color 0.15s;
  color: var(--text-tertiary);
}

.bmc-text-btn--danger:hover {
  color: #dc2626;
}

/* ─── Banner preview ────────────────────── */
.bmc-banner-preview {
  margin-bottom: 8px;
  position: relative;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid var(--border-primary);
}

.bmc-banner-preview img {
  display: block;
  width: 100%;
  height: 100px;
  object-fit: cover;
}

.bmc-banner-preview .bmc-text-btn {
  position: absolute;
  top: 6px;
  right: 6px;
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 12px;
}

.bmc-banner-preview .bmc-text-btn:hover {
  background: rgba(220, 38, 38, 0.8);
  color: #fff;
}

/* ─── Color stack ────────────────────────── */
.bmc-color-stack {
  border: 1px solid var(--border-primary);
  border-radius: 10px;
  overflow: hidden;
}

.bmc-color-swatch-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  transition: background 0.15s;
}

.bmc-color-swatch-row:not(:last-child) {
  border-bottom: 1px solid var(--border-primary);
}

.bmc-color-swatch-row:hover {
  background: var(--bg-hover);
}

.bmc-color-swatch {
  width: 32px;
  height: 32px;
  border-radius: 7px;
  flex-shrink: 0;
  border: 1px solid rgba(0,0,0,0.08);
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bmc-color-swatch-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.bmc-color-swatch-info__label {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-primary);
}

.bmc-color-swatch-info__value {
  font-size: 11.5px;
  font-family: var(--font-mono);
  color: var(--text-tertiary);
}

/* ─── Label with value ───────────────────── */
.bmc-label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}

.bmc-label-value {
  font-size: 12px;
  font-family: var(--font-mono);
  color: var(--text-tertiary);
  background: var(--bg-tertiary);
  padding: 1px 7px;
  border-radius: 4px;
  border: 1px solid var(--border-primary);
}

/* ─── Slider row with presets ────────────── */
.bmc-slider-row {
  display: flex;
  align-items: center;
  gap: 0;
}

.bmc-radius-preset {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 8px;
  border: 1px solid var(--border-primary);
  background: var(--bg-primary);
  cursor: pointer;
  margin-right: 4px;
  transition: border-color 0.15s, background 0.15s;
  flex-shrink: 0;
}

.bmc-radius-preset:hover {
  background: var(--bg-hover);
}

.bmc-radius-preset--active {
  border-color: var(--color-primary-500);
  background: var(--color-primary-50);
}

.bmc-radius-preview {
  display: block;
  width: 18px;
  height: 18px;
  border: 2px solid var(--text-secondary);
  background: transparent;
}

.bmc-radius-preview--0  { border-radius: 0; }
.bmc-radius-preview--8  { border-radius: 4px; }
.bmc-radius-preview--50 { border-radius: 9999px; }

.bmc-radius-preset--active .bmc-radius-preview {
  border-color: var(--color-primary-600);
}

/* ─── Live preview panel ─────────────────── */
.bmc-preview-sticky {
  position: sticky;
  top: 24px;
}

.bmc-preview-panel {
  background: var(--bg-secondary);
  border: 1px solid var(--border-primary);
  border-radius: 12px;
  padding: 16px;
}

.bmc-preview-panel__label {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-secondary);
  margin: 0 0 14px;
}

/* ─── Preview mockup ─────────────────────── */
.bmc-preview-mockup {
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 10px;
  padding: 20px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}

.bmc-preview-mockup__avatar {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--border-primary);
}

.bmc-preview-mockup__quote {
  font-size: 11.5px;
  color: var(--text-secondary);
  text-align: center;
  font-style: italic;
  margin: 0;
  line-height: 1.4;
}

.bmc-preview-mockup__quote--placeholder {
  color: var(--text-tertiary);
  font-style: normal;
}

.bmc-preview-mockup__amounts {
  display: flex;
  gap: 6px;
}

.bmc-preview-mockup__pill {
  font-size: 12px;
  font-weight: 500;
  padding: 3px 10px;
  border-radius: 999px;
  border: 1px solid var(--border-primary);
  color: var(--text-secondary);
  background: var(--bg-primary);
}

.bmc-preview-mockup__pill--active {
  border-color: var(--color-primary-400);
  background: var(--color-primary-50);
  color: var(--color-primary-700);
}

.bmc-preview-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 20px;
  border: none;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  transition: opacity 0.15s;
  white-space: nowrap;
  width: 100%;
  justify-content: center;
}

.bmc-preview-btn:hover { opacity: 0.9; }

.bmc-preview-panel__link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--color-primary-600);
  text-decoration: none;
  transition: color 0.15s;
}

.bmc-preview-panel__link:hover {
  color: var(--color-primary-700, var(--color-primary-600));
}

/* ─── Shortcodes ─────────────────────────── */
.bmc-shortcodes-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

@media (max-width: 640px) {
  .bmc-shortcodes-grid { grid-template-columns: 1fr; }
}

.bmc-inline-code {
  font-family: var(--font-mono);
  font-size: 12px;
  background: var(--bg-tertiary);
  padding: 1px 5px;
  border-radius: 4px;
  border: 1px solid var(--border-primary);
  color: var(--text-secondary);
}

/* ─── Sticky action bar ──────────────────── */
.bmc-action-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 20px;
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 10px;
  position: sticky;
  bottom: 16px;
  box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.06);
}
</style>
