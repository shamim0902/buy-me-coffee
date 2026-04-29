<template>
  <div>
    <PageTitle title="Quick Setup" subtitle="Get your donation page ready in minutes" />

    <!-- Step Indicator -->
    <div class="bmc-steps mb-5">
      <template v-for="(step, index) in stepLabels" :key="index">
        <div class="bmc-steps__item" :class="{ 'bmc-steps__item--active': active === index, 'bmc-steps__item--done': active > index }">
          <div class="bmc-steps__circle">
            <Check v-if="active > index" :size="14" />
            <span v-else>{{ index + 1 }}</span>
          </div>
          <span class="bmc-steps__label">{{ step }}</span>
        </div>
        <div v-if="index < stepLabels.length - 1" class="bmc-steps__line" :class="{ 'bmc-steps__line--done': active > index }"></div>
      </template>
    </div>

    <!-- Progress bar -->
    <div class="bmc-progress mb-6">
      <div class="bmc-progress__bar" :style="{ width: (active / (stepLabels.length - 1)) * 100 + '%' }"></div>
    </div>

    <!-- Step Content Card -->
    <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">

      <!-- Step 0: Welcome -->
      <div v-if="active === 0" class="bmc-welcome">
        <div class="bmc-welcome__icon">
          <Coffee :size="36" />
        </div>
        <h2 class="bmc-step-title text-center">Welcome to Buy Me Coffee</h2>
        <p class="bmc-step-subtitle text-center mb-6">Let's get your donation page ready in about 2 minutes.</p>

        <div class="bmc-welcome__features">
          <div class="bmc-welcome__feature">
            <div class="bmc-welcome__feature-icon bmc-welcome__feature-icon--purple"><CreditCard :size="18" /></div>
            <div>
              <span class="bmc-welcome__feature-title">Accept donations via Stripe and PayPal</span>
              <span class="bmc-welcome__feature-desc">One-time payments and recurring subscriptions</span>
            </div>
          </div>
          <div class="bmc-welcome__feature">
            <div class="bmc-welcome__feature-icon bmc-welcome__feature-icon--teal"><Settings2 :size="18" /></div>
            <div>
              <span class="bmc-welcome__feature-title">Customize your form and currency</span>
              <span class="bmc-welcome__feature-desc">Choose what to collect from your supporters</span>
            </div>
          </div>
          <div class="bmc-welcome__feature">
            <div class="bmc-welcome__feature-icon bmc-welcome__feature-icon--blue"><Code2 :size="18" /></div>
            <div>
              <span class="bmc-welcome__feature-title">Embed anywhere with shortcodes or Gutenberg</span>
              <span class="bmc-welcome__feature-desc">Add a donation button to any page in seconds</span>
            </div>
          </div>
        </div>

        <div class="flex justify-center mt-6">
          <button class="bmc-btn bmc-btn--primary bmc-btn--lg" @click="active = 1">
            Get Started <ChevronRight :size="16" />
          </button>
        </div>
      </div>

      <!-- Step 1: Profile -->
      <div v-else-if="active === 1" v-loading="fetching">
        <h2 class="bmc-step-title">Set up your profile</h2>
        <p class="bmc-step-subtitle mb-5">Tell supporters who you are.</p>

        <div class="flex items-start gap-5 max-w-lg">
          <div class="flex flex-col items-center gap-2 flex-shrink-0">
            <img class="w-20 h-20 rounded-full object-cover border-2 border-neutral-100" :src="template.advanced.image || fullPath('profile.png')" alt="Profile" />
            <MediaButton class="bmc-media-btn" @onMediaSelected="onMediaSelected" />
          </div>
          <div class="flex-1 space-y-3">
            <div>
              <label class="bmc-label">Your name or brand <span class="text-red-500">*</span></label>
              <el-input size="large" v-model="template.yourName" placeholder="e.g. John Doe" @input="errors.yourName = ''" />
              <p v-if="errors.yourName" class="bmc-field-error">{{ errors.yourName }}</p>
            </div>
            <div>
              <label class="bmc-label">Bio / tagline</label>
              <el-input type="textarea" :rows="2" v-model="template.advanced.quote" placeholder="A short message for your supporters (optional)" />
            </div>
          </div>
        </div>

        <div class="flex justify-end mt-6">
          <button class="bmc-btn bmc-btn--primary" :disabled="saving" @click="next">
            {{ saving ? 'Saving...' : 'Next' }} <ChevronRight v-if="!saving" :size="16" />
          </button>
        </div>
      </div>

      <!-- Step 2: Form Setup -->
      <div v-else-if="active === 2">
        <h2 class="bmc-step-title">Configure your form</h2>
        <p class="bmc-step-subtitle mb-5">Set your currency, default amount, and what to collect from supporters.</p>

        <div class="grid grid-cols-2 gap-4 max-w-lg mb-5">
          <div>
            <label class="bmc-label">Currency</label>
            <el-select v-model="template.currency" filterable class="w-full" size="large" placeholder="Select currency">
              <el-option v-for="(label, code) in currencies" :key="code" :label="code + ' — ' + label" :value="code" />
            </el-select>
          </div>
          <div>
            <label class="bmc-label">Default coffee price</label>
            <el-input-number v-model="template.defaultAmount" :min="1" :max="10000" size="large" class="w-full" />
          </div>
        </div>

        <div class="bmc-toggle-list max-w-lg">
          <div class="bmc-toggle-row">
            <div>
              <span class="bmc-toggle-row__label">Collect supporter name</span>
              <span class="bmc-toggle-row__desc">Ask supporters to provide their name</span>
            </div>
            <el-switch v-model="template.enableName" active-value="yes" inactive-value="no" />
          </div>
          <div class="bmc-toggle-row">
            <div>
              <span class="bmc-toggle-row__label">Collect email address</span>
              <span class="bmc-toggle-row__desc">Required for recurring subscriptions</span>
            </div>
            <el-switch v-model="template.enableEmail" active-value="yes" inactive-value="no" />
          </div>
          <div class="bmc-toggle-row">
            <div>
              <span class="bmc-toggle-row__label">Collect message</span>
              <span class="bmc-toggle-row__desc">Let supporters leave a note with their donation</span>
            </div>
            <el-switch v-model="template.enableMessage" active-value="yes" inactive-value="no" />
          </div>
          <div class="bmc-toggle-row">
            <div>
              <span class="bmc-toggle-row__label">Allow recurring donations</span>
              <span class="bmc-toggle-row__desc">Monthly or yearly subscriptions (Stripe only)</span>
            </div>
            <el-switch v-model="template.allow_recurring" active-value="yes" inactive-value="no" />
          </div>
        </div>

        <div class="flex justify-between mt-6">
          <button class="bmc-btn bmc-btn--secondary" @click="prev"><ChevronLeft :size="16" /> Back</button>
          <button class="bmc-btn bmc-btn--primary" :disabled="saving" @click="next">
            {{ saving ? 'Saving...' : 'Next' }} <ChevronRight v-if="!saving" :size="16" />
          </button>
        </div>
      </div>

      <!-- Step 3: Payment Gateway -->
      <div v-else-if="active === 3" v-loading="loadingGateway">
        <h2 class="bmc-step-title">Connect a payment gateway</h2>
        <p class="bmc-step-subtitle mb-5">Add Stripe, PayPal, or both. You can always change this later.</p>

        <!-- Gateway selector -->
        <div class="grid grid-cols-2 gap-3 max-w-lg mb-5">
          <button class="bmc-gw-card" :class="{ 'bmc-gw-card--active': selectedGateway === 'stripe' }" @click="selectedGateway = 'stripe'">
            <img :src="fullPath('stripe.svg')" alt="Stripe" class="bmc-gw-card__logo" />
            <span class="bmc-gw-card__title">Stripe</span>
            <span class="bmc-gw-card__desc">Cards, Apple Pay, Google Pay</span>
            <span class="bmc-gw-card__badge">One-time + Recurring</span>
          </button>
          <button class="bmc-gw-card" :class="{ 'bmc-gw-card--active': selectedGateway === 'paypal' }" @click="selectedGateway = 'paypal'">
            <img :src="fullPath('PayPal.svg')" alt="PayPal" class="bmc-gw-card__logo" />
            <span class="bmc-gw-card__title">PayPal</span>
            <span class="bmc-gw-card__desc">PayPal balance, cards</span>
            <span class="bmc-gw-card__badge">One-time</span>
          </button>
        </div>

        <!-- Stripe config -->
        <div v-if="selectedGateway === 'stripe'" class="max-w-lg">
          <div class="flex items-center gap-3 mb-4">
            <el-radio-group v-model="stripeSettings.payment_mode" size="small">
              <el-radio-button value="test">Test</el-radio-button>
              <el-radio-button value="live">Live</el-radio-button>
            </el-radio-group>
            <span v-if="stripeSettings.payment_mode === 'test'" class="text-xs text-amber-600 flex items-center gap-1">
              <AlertTriangle :size="12" /> Sandbox
            </span>
          </div>
          <div class="space-y-3">
            <div>
              <label class="bmc-label">Publishable Key</label>
              <el-input v-model="stripePublishableKey" type="password" :placeholder="stripeSettings.payment_mode === 'live' ? 'pk_live_...' : 'pk_test_...'" size="large" show-password @input="errors.stripeKey = ''" />
              <p v-if="errors.stripeKey" class="bmc-field-error">{{ errors.stripeKey }}</p>
            </div>
            <div>
              <label class="bmc-label">Secret Key</label>
              <el-input v-model="stripeSecretKey" type="password" :placeholder="stripeSettings.payment_mode === 'live' ? 'sk_live_...' : 'sk_test_...'" size="large" show-password @input="errors.stripeKey = ''" />
            </div>
          </div>
          <div class="flex items-center gap-3 mt-3">
            <button class="bmc-btn bmc-btn--secondary text-xs" :disabled="verifying || !stripeSecretKey" @click="verifyStripeKey">
              <Check v-if="stripeVerified" :size="13" class="text-emerald-600" />
              {{ verifying ? 'Verifying...' : stripeVerified ? 'Verified' : 'Verify Connection' }}
            </button>
            <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener" class="text-xs text-[var(--color-primary)] hover:underline">Find your API keys</a>
          </div>
        </div>

        <!-- PayPal config -->
        <div v-if="selectedGateway === 'paypal'" class="max-w-lg">
          <div class="flex items-center gap-3 mb-4">
            <el-radio-group v-model="paypalSettings.payment_mode" size="small">
              <el-radio-button value="test">Test</el-radio-button>
              <el-radio-button value="live">Live</el-radio-button>
            </el-radio-group>
            <span v-if="paypalSettings.payment_mode === 'test'" class="text-xs text-amber-600 flex items-center gap-1">
              <AlertTriangle :size="12" /> Sandbox
            </span>
          </div>
          <div class="space-y-3">
            <div>
              <label class="bmc-label">Client ID</label>
              <el-input v-model="paypalPublicKey" type="password" placeholder="Client ID" size="large" show-password @input="errors.paypalKey = ''" />
              <p v-if="errors.paypalKey" class="bmc-field-error">{{ errors.paypalKey }}</p>
            </div>
            <div>
              <label class="bmc-label">Secret Key</label>
              <el-input v-model="paypalSecretKey" type="password" placeholder="Secret Key" size="large" show-password @input="errors.paypalKey = ''" />
            </div>
          </div>
          <p class="mt-3 text-xs text-[var(--text-tertiary)]">
            Find credentials in your <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener" class="text-[var(--color-primary)] hover:underline">PayPal Developer Dashboard</a>.
          </p>
        </div>

        <div class="flex justify-between mt-6">
          <button class="bmc-btn bmc-btn--secondary" @click="prev"><ChevronLeft :size="16" /> Back</button>
          <button class="bmc-btn bmc-btn--primary" :disabled="saving" @click="next">
            {{ saving ? 'Saving...' : 'Next' }} <ChevronRight v-if="!saving" :size="16" />
          </button>
        </div>
      </div>

      <!-- Step 4: Launch -->
      <div v-else-if="active === 4">
        <div class="bmc-launch">
          <div class="bmc-launch__icon"><CheckCircle2 :size="44" /></div>
          <h2 class="bmc-step-title text-center">You're ready to accept donations!</h2>
          <p class="bmc-step-subtitle text-center">Share your page and start collecting support.</p>
        </div>

        <!-- Gateway status -->
        <div class="flex items-center justify-center gap-3 mb-5">
          <span class="bmc-gw-status" :class="stripeSettings.enable === 'yes' && stripeSecretKey ? 'bmc-gw-status--ok' : 'bmc-gw-status--off'">
            Stripe: {{ stripeSettings.enable === 'yes' && stripeSecretKey ? 'Connected (' + stripeSettings.payment_mode + ')' : 'Not configured' }}
          </span>
          <span class="bmc-gw-status" :class="paypalSettings.enable === 'yes' && paypalSecretKey ? 'bmc-gw-status--ok' : 'bmc-gw-status--off'">
            PayPal: {{ paypalSettings.enable === 'yes' && paypalSecretKey ? 'Connected (' + paypalSettings.payment_mode + ')' : 'Not configured' }}
          </span>
        </div>

        <!-- Share link -->
        <div class="flex items-center gap-2 bg-neutral-50 rounded-lg border border-neutral-200 px-3 py-2 mb-4 max-w-lg mx-auto">
          <code class="flex-1 text-xs text-[var(--text-secondary)] truncate">{{ previewUrl }}</code>
          <button class="bmc-btn bmc-btn--secondary py-1 px-3 text-xs shrink-0" @click="copyLink">
            <Check v-if="linkCopied" :size="13" />
            <Copy v-else :size="13" />
            {{ linkCopied ? 'Copied' : 'Copy' }}
          </button>
        </div>

        <!-- Shortcodes -->
        <p class="text-xs text-[var(--text-secondary)] mb-2 text-center">Embed with shortcodes:</p>
        <div class="grid grid-cols-3 gap-2 mb-6 max-w-lg mx-auto">
          <CodeBlock label="Full Template" code="[buymecoffee_basic]" />
          <CodeBlock label="Form Only" code="[buymecoffee_form]" />
          <CodeBlock label="Button Only" code="[buymecoffee_button]" />
        </div>

        <div class="flex justify-center gap-3">
          <button class="bmc-btn bmc-btn--secondary" @click="openDashboard">
            <LayoutDashboard :size="15" /> Dashboard
          </button>
          <button class="bmc-btn bmc-btn--primary" @click="gotoPage">
            View Donation Page <ChevronRight :size="16" />
          </button>
        </div>
      </div>
    </div>

    <!-- Skip link -->
    <div v-if="active > 0 && active < 4" class="flex justify-center mt-4">
      <button class="text-sm text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] bg-transparent border-0 cursor-pointer transition-colors" @click="skipSetup">
        Skip setup for later
      </button>
    </div>
  </div>
</template>

<script>
import {
  Check, ChevronRight, ChevronLeft, AlertTriangle, Copy, LayoutDashboard,
  Coffee, CreditCard, Settings2, Code2, CheckCircle2,
} from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import CodeBlock from './UI/CodeBlock.vue';
import MediaButton from './Parts/MediaButton.vue';

export default {
  name: 'Onboarding',
  components: {
    Check, ChevronRight, ChevronLeft, AlertTriangle, Copy, LayoutDashboard,
    Coffee, CreditCard, Settings2, Code2, CheckCircle2,
    PageTitle, CodeBlock, MediaButton,
  },
  data() {
    return {
      active: 0,
      saving: false,
      fetching: false,
      loadingGateway: false,
      verifying: false,
      stripeVerified: false,
      linkCopied: false,
      selectedGateway: 'stripe',
      previewUrl: window.BuyMeCoffeeAdmin.preview_url,
      stepLabels: ['Welcome', 'Profile', 'Form', 'Payment', 'Launch'],
      errors: {},
      template: { advanced: {}, currency: 'USD', defaultAmount: 5, enableName: 'yes', enableEmail: 'no', enableMessage: 'yes', allow_recurring: 'no' },
      currencies: {},
      stripeSettings: { enable: 'yes', payment_mode: 'test', test_pub_key: '', test_secret_key: '', live_pub_key: '', live_secret_key: '' },
      paypalSettings: { enable: 'no', payment_mode: 'test', test_pub_key: '', test_secret_key: '', live_pub_key: '', live_secret_key: '' },
    };
  },
  computed: {
    stripePublishableKey: {
      get() { return this.stripeSettings.payment_mode === 'live' ? this.stripeSettings.live_pub_key : this.stripeSettings.test_pub_key; },
      set(v) { if (this.stripeSettings.payment_mode === 'live') this.stripeSettings.live_pub_key = v; else this.stripeSettings.test_pub_key = v; },
    },
    stripeSecretKey: {
      get() { return this.stripeSettings.payment_mode === 'live' ? this.stripeSettings.live_secret_key : this.stripeSettings.test_secret_key; },
      set(v) { if (this.stripeSettings.payment_mode === 'live') this.stripeSettings.live_secret_key = v; else this.stripeSettings.test_secret_key = v; },
    },
    paypalPublicKey: {
      get() { return this.paypalSettings.payment_mode === 'live' ? this.paypalSettings.live_pub_key : this.paypalSettings.test_pub_key; },
      set(v) { if (this.paypalSettings.payment_mode === 'live') this.paypalSettings.live_pub_key = v; else this.paypalSettings.test_pub_key = v; },
    },
    paypalSecretKey: {
      get() { return this.paypalSettings.payment_mode === 'live' ? this.paypalSettings.live_secret_key : this.paypalSettings.test_secret_key; },
      set(v) { if (this.paypalSettings.payment_mode === 'live') this.paypalSettings.live_secret_key = v; else this.paypalSettings.test_secret_key = v; },
    },
  },
  methods: {
    getSettings() {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax', route: 'get_settings',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((res) => {
        this.template = { ...this.template, ...res.data.template };
        if (!this.template.advanced) this.template.advanced = {};
        this.currencies = res.data.currencies || {};
      }).always(() => { this.fetching = false; });
    },
    loadGatewaySettings() {
      this.loadingGateway = true;
      let loaded = 0;
      const done = () => { loaded++; if (loaded >= 2) this.loadingGateway = false; };

      this.$get({
        action: 'buymecoffee_admin_ajax', route: 'get_data', data: { method: 'stripe' },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((res) => { this.stripeSettings = { ...this.stripeSettings, ...res.data.settings }; }).always(done);

      this.$get({
        action: 'buymecoffee_admin_ajax', route: 'get_data', data: { method: 'paypal' },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((res) => { this.paypalSettings = { ...this.paypalSettings, ...res.data.settings }; }).always(done);
    },
    validate() {
      this.errors = {};
      if (this.active === 1) {
        if (!this.template.yourName || !this.template.yourName.trim()) {
          this.errors.yourName = 'Please enter your name or brand.';
          return false;
        }
      }
      if (this.active === 3) {
        if (this.selectedGateway === 'stripe' && this.stripeSettings.enable === 'yes') {
          const prefix = this.stripeSettings.payment_mode === 'live' ? '_live_' : '_test_';
          if (this.stripePublishableKey && !this.stripePublishableKey.startsWith('pk' + prefix)) {
            this.errors.stripeKey = 'Publishable key should start with pk' + prefix;
            return false;
          }
          if (this.stripeSecretKey && !this.stripeSecretKey.startsWith('sk' + prefix)) {
            this.errors.stripeKey = 'Secret key should start with sk' + prefix;
            return false;
          }
        }
        if (this.selectedGateway === 'paypal' && this.paypalSettings.enable === 'yes') {
          if (!this.paypalPublicKey || !this.paypalSecretKey) {
            this.errors.paypalKey = 'Both Client ID and Secret Key are required.';
            return false;
          }
        }
      }
      return true;
    },
    async next() {
      if (this.saving) return;
      if (!this.validate()) return;

      this.saving = true;
      try {
        if (this.active === 1 || this.active === 2) {
          await this.$post({
            action: 'buymecoffee_admin_ajax', route: 'save_settings', data: this.template,
            buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
          });
        }
        if (this.active === 3) {
          const saves = [];
          if (this.stripeSettings.enable === 'yes' && this.stripeSecretKey) {
            saves.push(this.$post({
              action: 'buymecoffee_admin_ajax', route: 'save_payment_settings',
              data: { method: 'stripe', settings: this.stripeSettings },
              buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }));
          }
          if (this.paypalSettings.enable === 'yes' && this.paypalSecretKey) {
            saves.push(this.$post({
              action: 'buymecoffee_admin_ajax', route: 'save_payment_settings',
              data: { method: 'paypal', settings: this.paypalSettings },
              buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }));
          }
          if (saves.length) await Promise.all(saves);
        }
        if (this.active < 4) this.active++;
      } catch (error) {
        const message = error?.responseJSON?.data?.message || 'Could not save. Please try again.';
        this.$message.error(message);
      } finally {
        this.saving = false;
      }
    },
    verifyStripeKey() {
      this.verifying = true;
      this.stripeVerified = false;
      this.$post({
        action: 'buymecoffee_admin_ajax', route: 'validate_stripe_keys',
        data: { secret_key: this.stripeSecretKey },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then(() => {
        this.stripeVerified = true;
        this.$handleSuccess('Stripe key verified successfully');
      }).fail((err) => {
        const msg = err?.responseJSON?.data?.message || 'Key validation failed.';
        this.errors.stripeKey = msg;
      }).always(() => { this.verifying = false; });
    },
    prev() { if (this.active > 0) this.active--; },
    onMediaSelected(selected) { if (selected.length) this.template.advanced.image = selected[0].url; },
    fullPath(path) { return window.BuyMeCoffeeAdmin.assets_url + 'images/' + path; },
    gotoPage() { window.open(this.previewUrl, '_blank'); },
    openDashboard() { this.$saveData('buymecoffee_guided_tour', 'done'); this.$router.push('/'); },
    skipSetup() { this.$saveData('buymecoffee_guided_tour', 'done'); this.$router.push('/'); },
    copyLink() {
      if (navigator.clipboard) navigator.clipboard.writeText(this.previewUrl);
      this.linkCopied = true;
      setTimeout(() => { this.linkCopied = false; }, 2000);
    },
  },
  watch: {
    active(val) {
      if (val === 3) this.loadGatewaySettings();
    },
  },
  mounted() {
    this.getSettings();
  },
};
</script>

<style scoped>
/* ── Step indicator ── */
.bmc-steps { display: flex; align-items: center; justify-content: center; gap: 0; }
.bmc-steps__item { display: flex; align-items: center; gap: 6px; }
.bmc-steps__circle {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600; transition: all 0.2s;
  background: var(--bg-tertiary); color: var(--text-tertiary);
}
.bmc-steps__item--active .bmc-steps__circle {
  background: var(--color-primary-600); color: #fff;
  box-shadow: 0 0 0 4px var(--color-primary-100);
}
.bmc-steps__item--done .bmc-steps__circle { background: #10b981; color: #fff; }
.bmc-steps__label { font-size: 12px; font-weight: 500; color: var(--text-tertiary); }
.bmc-steps__item--active .bmc-steps__label { color: var(--text-primary); font-weight: 600; }
.bmc-steps__item--done .bmc-steps__label { color: var(--text-secondary); }
.bmc-steps__line { width: 32px; height: 2px; margin: 0 8px; background: var(--border-secondary); border-radius: 1px; }
.bmc-steps__line--done { background: #10b981; }

/* ── Progress bar ── */
.bmc-progress { height: 3px; background: var(--border-secondary); border-radius: 2px; overflow: hidden; }
.bmc-progress__bar { height: 100%; background: var(--color-primary-600); border-radius: 2px; transition: width 0.4s ease; }

/* ── Step titles ── */
.bmc-step-title { font-size: 17px; font-weight: 600; color: var(--text-primary); margin: 0 0 4px; }
.bmc-step-subtitle { font-size: 13px; color: var(--text-secondary); margin: 0; }

/* ── Welcome ── */
.bmc-welcome { text-align: center; }
.bmc-welcome__icon {
  width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--color-primary-50); color: var(--color-primary-600);
}
.bmc-welcome__features { display: flex; flex-direction: column; gap: 12px; max-width: 420px; margin: 0 auto; text-align: left; }
.bmc-welcome__feature { display: flex; align-items: start; gap: 12px; }
.bmc-welcome__feature-icon {
  width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.bmc-welcome__feature-icon--purple { background: #f3e8ff; color: #7c3aed; }
.bmc-welcome__feature-icon--teal { background: #ccfbf1; color: #0d9488; }
.bmc-welcome__feature-icon--blue { background: #dbeafe; color: #2563eb; }
.bmc-welcome__feature-title { display: block; font-size: 13px; font-weight: 600; color: var(--text-primary); }
.bmc-welcome__feature-desc { display: block; font-size: 12px; color: var(--text-tertiary); margin-top: 1px; }

/* ── Labels ── */
.bmc-label { display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 4px; }

/* ── Field error ── */
.bmc-field-error { font-size: 12px; color: #dc2626; margin: 4px 0 0; }

/* ── Toggle list ── */
.bmc-toggle-list { border: 1px solid var(--border-primary); border-radius: 10px; overflow: hidden; }
.bmc-toggle-row {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 12px 16px; border-bottom: 1px solid var(--border-secondary);
}
.bmc-toggle-row:last-child { border-bottom: none; }
.bmc-toggle-row__label { display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); }
.bmc-toggle-row__desc { display: block; font-size: 11px; color: var(--text-tertiary); margin-top: 1px; }

/* ── Gateway cards ── */
.bmc-gw-card {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  padding: 16px; border: 2px solid var(--border-primary); border-radius: 12px;
  background: var(--bg-primary); cursor: pointer; transition: all 0.15s; text-align: center;
}
.bmc-gw-card:hover { border-color: var(--color-primary-300); }
.bmc-gw-card--active { border-color: var(--color-primary-500); background: var(--color-primary-50); }
.bmc-gw-card__logo { height: 24px; object-fit: contain; }
.bmc-gw-card__title { font-size: 14px; font-weight: 600; color: var(--text-primary); }
.bmc-gw-card__desc { font-size: 11px; color: var(--text-tertiary); }
.bmc-gw-card__badge { font-size: 10px; font-weight: 500; padding: 2px 8px; border-radius: 100px; background: var(--bg-tertiary); color: var(--text-secondary); }

/* ── Launch ── */
.bmc-launch { text-align: center; margin-bottom: 20px; }
.bmc-launch__icon { color: #10b981; margin-bottom: 12px; }

/* ── Gateway status pills ── */
.bmc-gw-status { font-size: 12px; font-weight: 500; padding: 4px 12px; border-radius: 100px; }
.bmc-gw-status--ok { background: #dcfce7; color: #166534; }
.bmc-gw-status--off { background: var(--bg-tertiary); color: var(--text-tertiary); }

/* ── Buttons ── */
.bmc-media-btn {
  background: transparent; border: 1px solid var(--border-primary); border-radius: 8px;
  padding: 6px 14px; font-size: 13px; color: var(--text-secondary); cursor: pointer; transition: all 0.15s;
}
.bmc-media-btn:hover { background: var(--bg-hover); color: var(--text-primary); }
.bmc-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 18px; font-size: 14px; font-weight: 500;
  border-radius: 8px; border: none; cursor: pointer; transition: all 0.15s;
}
.bmc-btn--lg { padding: 10px 24px; font-size: 15px; }
.bmc-btn--primary { background: var(--color-primary, #10b981); color: #fff; }
.bmc-btn--primary:hover { opacity: 0.9; }
.bmc-btn--primary:disabled { opacity: 0.5; cursor: not-allowed; }
.bmc-btn--secondary {
  background: var(--bg-primary, #fff); color: var(--text-secondary);
  border: 1px solid var(--border-primary, #e5e5e5);
}
.bmc-btn--secondary:hover { background: var(--bg-hover); color: var(--text-primary); }
.bmc-btn--secondary:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
