<template>
    <div>
        <PageTitle title="Quick Setup" subtitle="Get started in 3 easy steps" />

        <!-- Step Indicator -->
        <div class="flex items-center justify-center gap-0 mb-5">
            <template v-for="(step, index) in steps" :key="index">
                <div class="flex items-center gap-2">
                    <div
                        class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-colors"
                        :class="stepCircleClass(index)"
                    >
                        <Check v-if="active > index" :size="14" />
                        <span v-else>{{ index + 1 }}</span>
                    </div>
                    <span
                        class="text-sm font-medium"
                        :class="active >= index ? 'text-[var(--text-primary)]' : 'text-[var(--text-tertiary)]'"
                    >
                        {{ step }}
                    </span>
                </div>
                <div
                    v-if="index < steps.length - 1"
                    class="w-12 h-px mx-3"
                    :class="active > index ? 'bg-emerald-500' : 'bg-neutral-200'"
                ></div>
            </template>
        </div>

        <!-- Step Content Card -->
        <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">

            <!-- Step 1: Profile -->
            <div v-if="active === 0">
                <h2 class="text-base font-semibold text-[var(--text-primary)] mb-1">Set up your profile</h2>
                <p class="text-sm text-[var(--text-secondary)] mb-5">Add your photo and name so supporters know who they're donating to.</p>

                <div class="flex items-center gap-5 max-w-lg">
                    <div class="flex flex-col items-center gap-2 flex-shrink-0">
                        <img
                            class="w-20 h-20 rounded-full object-cover border-2 border-neutral-100"
                            :src="template.advanced.image || fullPath('profile.png')"
                            alt="Profile"
                        />
                        <MediaButton class="bmc-media-btn" @onMediaSelected="onMediaSelected" />
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Collect donations for</label>
                        <el-input size="large" v-model="template.yourName" placeholder="Your name or brand" />
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button class="bmc-btn bmc-btn--primary" @click="next">
                        Next <ChevronRight :size="16" />
                    </button>
                </div>
            </div>

            <!-- Step 2: Payment (compact inline form) -->
            <div v-else-if="active === 1" v-loading="loadingStripe">
                <h2 class="text-base font-semibold text-[var(--text-primary)] mb-1">Connect Stripe</h2>
                <p class="text-sm text-[var(--text-secondary)] mb-4">Paste your API keys to start accepting donations.</p>

                <!-- Mode toggle -->
                <div class="flex items-center gap-3 mb-4">
                    <el-radio-group v-model="stripeSettings.payment_mode" size="small">
                        <el-radio-button value="test">Test</el-radio-button>
                        <el-radio-button value="live">Live</el-radio-button>
                    </el-radio-group>
                    <span v-if="stripeSettings.payment_mode === 'test'" class="text-xs text-amber-600 flex items-center gap-1">
                        <AlertTriangle :size="12" /> Sandbox
                    </span>
                </div>

                <!-- Keys -->
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Publishable Key</label>
                        <el-input
                            v-model="activePublishableKey"
                            type="password"
                            :placeholder="stripeSettings.payment_mode === 'live' ? 'pk_live_...' : 'pk_test_...'"
                            size="large"
                            show-password
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Secret Key</label>
                        <el-input
                            v-model="activeSecretKey"
                            type="password"
                            :placeholder="stripeSettings.payment_mode === 'live' ? 'sk_live_...' : 'sk_test_...'"
                            size="large"
                            show-password
                        />
                    </div>
                </div>

                <p class="mt-3 text-xs text-[var(--text-tertiary)]">
                    Find keys in your
                    <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener" class="text-[var(--color-primary)] hover:underline">Stripe Dashboard</a>.
                    You can also configure payment gateways later in Settings → Gateways.
                </p>

                <div class="flex justify-between mt-6">
                    <button class="bmc-btn bmc-btn--secondary" @click="prev">
                        <ChevronLeft :size="16" /> Back
                    </button>
                    <button class="bmc-btn bmc-btn--primary" @click="next">
                        Next <ChevronRight :size="16" />
                    </button>
                </div>
            </div>

            <!-- Step 3: Done -->
            <div v-else-if="active === 2">
                <div class="flex items-center gap-4 mb-5">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 flex items-center justify-center flex-shrink-0">
                        <PartyPopper :size="24" class="text-emerald-600" />
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-[var(--text-primary)]">You're all set!</h2>
                        <p class="text-sm text-[var(--text-secondary)]">Your donation page is ready to share.</p>
                    </div>
                </div>

                <!-- Share Link -->
                <div class="flex items-center gap-2 bg-neutral-50 rounded-lg border border-neutral-200 px-3 py-2 mb-4">
                    <code class="flex-1 text-xs text-[var(--text-secondary)] truncate">{{ previewUrl }}</code>
                    <button class="bmc-btn bmc-btn--secondary py-1 px-3 text-xs shrink-0" @click="copyLink">
                        <Check v-if="linkCopied" :size="13" />
                        <Copy v-else :size="13" />
                        {{ linkCopied ? 'Copied' : 'Copy' }}
                    </button>
                </div>

                <!-- Shortcodes — 3-column row -->
                <p class="text-xs text-[var(--text-secondary)] mb-2">Embed with shortcodes:</p>
                <div class="grid grid-cols-3 gap-2 mb-5">
                    <CodeBlock label="Full Template" code="[buymecoffee_basic]" />
                    <CodeBlock label="Form Only" code="[buymecoffee_form]" />
                    <CodeBlock label="Button Only" code="[buymecoffee_button]" />
                </div>

                <div class="flex justify-center gap-3">
                    <button class="bmc-btn bmc-btn--secondary" @click="openDashboard">
                        <LayoutDashboard :size="15" /> Dashboard
                    </button>
                    <button class="bmc-btn bmc-btn--primary" @click="gotoPage">
                        View Page <ChevronRight :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Skip link -->
        <div v-if="active < 2" class="flex justify-center mt-4">
            <button
                class="text-sm text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] bg-transparent border-0 cursor-pointer transition-colors"
                @click="skipSetup"
            >
                Skip setup for later
            </button>
        </div>
    </div>
</template>

<script>
import { Check, ChevronRight, ChevronLeft, PartyPopper, AlertTriangle, Copy, LayoutDashboard } from 'lucide-vue-next';
import PageTitle from './UI/PageTitle.vue';
import CodeBlock from './UI/CodeBlock.vue';
import MediaButton from './Parts/MediaButton.vue';

export default {
    name: 'Onboarding',
    components: {
        Check,
        ChevronRight,
        ChevronLeft,
        PartyPopper,
        AlertTriangle,
        Copy,
        LayoutDashboard,
        PageTitle,
        CodeBlock,
        MediaButton,
    },
    data() {
        return {
            active: 0,
            saving: false,
            fetching: false,
            loadingStripe: false,
            linkCopied: false,
            previewUrl: window.BuyMeCoffeeAdmin.preview_url,
            template: { advanced: {} },
            currencies: [],
            steps: ['Profile', 'Payment', 'Done'],
            stripeSettings: {
                enable: 'yes',
                payment_mode: 'test',
                test_pub_key: '',
                test_secret_key: '',
                live_pub_key: '',
                live_secret_key: '',
            },
        };
    },
    computed: {
        activePublishableKey: {
            get() {
                return this.stripeSettings.payment_mode === 'live'
                    ? this.stripeSettings.live_pub_key
                    : this.stripeSettings.test_pub_key;
            },
            set(val) {
                if (this.stripeSettings.payment_mode === 'live') {
                    this.stripeSettings.live_pub_key = val;
                } else {
                    this.stripeSettings.test_pub_key = val;
                }
            },
        },
        activeSecretKey: {
            get() {
                return this.stripeSettings.payment_mode === 'live'
                    ? this.stripeSettings.live_secret_key
                    : this.stripeSettings.test_secret_key;
            },
            set(val) {
                if (this.stripeSettings.payment_mode === 'live') {
                    this.stripeSettings.live_secret_key = val;
                } else {
                    this.stripeSettings.test_secret_key = val;
                }
            },
        },
    },
    methods: {
        getSettings() {
            this.fetching = true;
            this.$get({
                action: 'buymecoffee_admin_ajax',
                route: 'get_settings',
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }).then((res) => {
                this.template = res.data.template;
                this.currencies = res.data.currencies;
                this.fetching = false;
            });
        },
        getStripeSettings() {
            this.loadingStripe = true;
            this.$get({
                action: 'buymecoffee_admin_ajax',
                route: 'get_data',
                data: { method: 'stripe' },
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            }).then((res) => {
                this.stripeSettings = { ...this.stripeSettings, ...res.data.settings };
            }).always(() => {
                this.loadingStripe = false;
            });
        },
        saveStripeSettings() {
            this.$post({
                action: 'buymecoffee_admin_ajax',
                route: 'save_payment_settings',
                data: { method: 'stripe', settings: this.stripeSettings },
                buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
            });
        },
        onMediaSelected(selected) {
            if (selected.length) {
                this.template.advanced.image = selected[0].url;
            }
        },
        fullPath(path) {
            return window.BuyMeCoffeeAdmin.assets_url + 'images/' + path;
        },
        stepCircleClass(index) {
            if (this.active > index) return 'bg-emerald-500 text-white';
            if (this.active === index) return 'bg-emerald-500 text-white ring-4 ring-emerald-100';
            return 'bg-neutral-100 text-[var(--text-tertiary)]';
        },
        prev() {
            if (this.active > 0) this.active--;
        },
        next() {
            if (this.active === 0) {
                this.$post({
                    action: 'buymecoffee_admin_ajax',
                    route: 'save_settings',
                    data: this.template,
                    buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
                });
            }
            if (this.active === 1) {
                this.saveStripeSettings();
            }
            if (this.active < 2) this.active++;
        },
        gotoPage() {
            window.open(this.previewUrl, '_blank');
        },
        openDashboard() {
            this.$saveData('buymecoffee_guided_tour', 'done');
            this.$router.push('/');
        },
        skipSetup() {
            this.$saveData('buymecoffee_guided_tour', 'done');
            this.$router.push('/');
        },
        copyLink() {
            if (navigator.clipboard) navigator.clipboard.writeText(this.previewUrl);
            this.linkCopied = true;
            setTimeout(() => { this.linkCopied = false; }, 2000);
        },
    },
    watch: {
        active(val) {
            if (val === 1) this.getStripeSettings();
        },
    },
    mounted() {
        this.getSettings();
    },
};
</script>

<style scoped>
.bmc-media-btn {
    background: transparent;
    border: 1px solid var(--border-primary);
    border-radius: 8px;
    padding: 6px 14px;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}
.bmc-media-btn:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}
.bmc-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
}
.bmc-btn--primary {
    background: var(--color-primary, #10b981);
    color: #fff;
}
.bmc-btn--primary:hover {
    opacity: 0.9;
}
.bmc-btn--secondary {
    background: var(--bg-primary, #fff);
    color: var(--text-secondary);
    border: 1px solid var(--border-primary, #e5e5e5);
}
.bmc-btn--secondary:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}
</style>
