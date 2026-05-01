<template>
  <div class="bmc-supporter-page relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />
    <!-- Back Button -->
    <button
      class="bmc-back-btn"
      @click="$router.push({ name: 'Supporters' })"
    >
      <ArrowLeft :size="16" />
      Back to Supporters
    </button>

    <template v-if="!loading && supporter.id">
      <div class="bmc-supporter-layout">
        <aside class="bmc-supporter-sidebar">
          <div class="bmc-supporter-profile-card">
            <img
              v-if="supporter.supporters_image"
              :src="supporter.supporters_image"
              :alt="supporter.supporters_name"
              class="bmc-supporter-avatar"
            />
            <div
              v-else
              class="bmc-supporter-avatar bmc-supporter-avatar--placeholder"
            >
              <span>{{ (supporter.supporters_name || 'A').charAt(0) }}</span>
            </div>

            <h2 class="bmc-supporter-name">
              {{ supporter.supporters_name || 'Anonymous' }}
            </h2>
            <p v-if="supporter.supporters_email" class="bmc-supporter-email">
              {{ supporter.supporters_email }}
            </p>

            <div class="bmc-supporter-badges">
              <StatusBadge :status="supporter.payment_status" />
              <span v-if="supporter.payment_method" class="bmc-supporter-method">
                {{ supporter.payment_method }}
              </span>
            </div>

            <p class="bmc-supporter-created">{{ supporter.created_at }}</p>

            <div
              v-if="supporter.payment_status === 'paid-initially'"
              class="bmc-supporter-warning"
            >
              <Clock :size="14" />
              <span>Please verify this transaction before marking as paid.</span>
            </div>

            <div class="bmc-supporter-actions">
            <a
              v-if="supporter.supporters_email"
              :href="'mailto:' + supporter.supporters_email"
              class="bmc-supporter-action"
            >
              <Mail :size="14" />
              Send Email
            </a>
            <a
              v-if="supporter.transaction && supporter.transaction.transaction_url"
              :href="supporter.transaction.transaction_url"
              target="_blank"
              rel="noopener noreferrer"
              class="bmc-supporter-action"
            >
              <ExternalLink :size="14" />
              View on {{ supporter.payment_method }}
            </a>
            <button
              class="bmc-supporter-action bmc-supporter-action--primary"
              @click="statusModal = true"
            >
              <Edit3 :size="14" />
              Change Status
            </button>

          </div>
        </div>

          <div class="bmc-supporter-sidebar-card">
            <h3 class="bmc-sidebar-section-title">Lifetime Summary</h3>
            <div class="bmc-supporter-stat-list">
              <div class="bmc-supporter-stat">
                <span class="bmc-supporter-stat__label">All-time Paid</span>
                <span class="bmc-supporter-stat__value">{{ supporter.all_time_total_paid || '$0' }}</span>
              </div>
              <div class="bmc-supporter-stat">
                <span class="bmc-supporter-stat__label">All-time Pending</span>
                <span class="bmc-supporter-stat__value">{{ supporter.all_time_total_pending || '$0' }}</span>
              </div>
              <div class="bmc-supporter-stat">
                <span class="bmc-supporter-stat__label">Total Coffees</span>
                <span class="bmc-supporter-stat__value">{{ supporter.all_time_total_coffee || '0' }}</span>
              </div>
            </div>
          </div>
        </aside>

        <section class="bmc-supporter-main">

      <!-- Transaction Details Card -->
      <div
        v-if="supporter.transaction"
        class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6 mb-6"
      >
        <div class="bmc-card-header-actions">
          <h3 class="text-sm font-semibold uppercase tracking-wide mt-0 mb-0" style="color: var(--text-secondary)">
            Transaction Details
          </h3>
          <div class="bmc-more-menu" ref="moreMenu">
            <button
              class="bmc-card-more-btn"
              title="Transaction actions"
              @click.stop="moreOpen = !moreOpen"
            >
              <MoreVertical :size="16" />
            </button>
            <div v-if="moreOpen" class="bmc-more-dropdown">
              <button
                v-if="supporter.transaction && supporter.transaction.status === 'paid' && supporter.transaction.charge_id"
                class="bmc-more-item bmc-more-item--danger"
                :disabled="refunding"
                @click="openRefundModal"
              >
                <RotateCcw :size="14" />
                {{ refunding ? 'Refunding...' : 'Refund Transaction' }}
              </button>
              <span
                v-else
                class="bmc-more-item bmc-more-item--disabled"
                :title="supporter.transaction && supporter.transaction.status === 'refunded' ? 'Already refunded' : 'Not refundable'"
              >
                <RotateCcw :size="14" />
                Refund Transaction
              </span>
            </div>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-6">
          <div v-if="supporter.transaction.charge_id">
            <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Transaction ID</p>
            <p class="text-sm font-medium m-0">
              <a
                v-if="supporter.transaction.transaction_url"
                :href="supporter.transaction.transaction_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 font-mono no-underline hover:underline"
                style="color: var(--color-primary-600); font-size: 13px"
              >
                {{ supporter.transaction.charge_id }}
                <ExternalLink :size="11" />
              </a>
              <span v-else class="font-mono" style="color: var(--text-primary); font-size: 13px">
                {{ supporter.transaction.charge_id }}
              </span>
            </p>
          </div>
          <div v-if="supporter.transaction.card_brand">
            <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Card</p>
            <p class="text-sm font-medium m-0" style="color: var(--text-primary)">
              {{ supporter.transaction.card_brand }}
              <span v-if="supporter.transaction.card_last_4">**** {{ supporter.transaction.card_last_4 }}</span>
            </p>
          </div>
          <div v-if="supporter.transaction.payment_method">
            <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Payment Method</p>
            <p class="text-sm font-medium m-0" style="color: var(--text-primary)">{{ supporter.transaction.payment_method }}</p>
          </div>
          <div v-if="supporter.transaction.status">
            <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Gateway Status</p>
            <StatusBadge :status="supporter.transaction.status" />
          </div>
          <div v-if="supporter.payment_mode">
            <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Mode</p>
            <p class="text-sm font-medium m-0" style="color: var(--text-primary)">
              <span
                class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full"
                :class="supporter.payment_mode === 'live' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
              >
                {{ supporter.payment_mode }}
              </span>
            </p>
          </div>
          <div>
            <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Amount</p>
            <p class="text-sm font-medium m-0" style="color: var(--text-primary)">
              {{ getFormatedAmount(supporter.payment_total, supporter.currency) }}
              <span class="text-xs" style="color: var(--text-tertiary)">
                ({{ parseInt(supporter.coffee_count) }} {{ parseInt(supporter.coffee_count) === 1 ? 'coffee' : 'coffees' }})
              </span>
            </p>
          </div>
        </div>
      </div>

      <!-- Subscription + Message row (two-column when both present) -->
      <div
        v-if="supporter.subscription || supporter.supporters_message"
        class="bmc-two-col-row mb-6"
        :class="{ 'bmc-two-col-row--single': !(supporter.subscription && supporter.supporters_message) }"
      >
        <!-- Subscription Info -->
        <div v-if="supporter.subscription" class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold uppercase tracking-wide m-0" style="color: var(--text-secondary)">
              Subscription
            </h3>
            <router-link
              :to="{ name: 'SubscriptionDetail', params: { id: supporter.subscription.id } }"
              class="inline-flex items-center gap-1 text-xs font-medium no-underline hover:underline"
              style="color: var(--color-primary-600)"
            >
              View <ExternalLink :size="11" />
            </router-link>
          </div>
          <div class="grid grid-cols-2 gap-y-4 gap-x-6">
            <div>
              <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Status</p>
              <StatusBadge :status="supporter.subscription.status" />
            </div>
            <div>
              <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Interval</p>
              <p class="text-sm font-medium m-0" style="color: var(--text-primary)">
                {{ supporter.subscription.interval_type === 'year' ? 'Yearly' : 'Monthly' }}
              </p>
            </div>
            <div>
              <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Amount</p>
              <p class="text-sm font-medium m-0" style="color: var(--text-primary)">
                {{ getFormatedAmount(supporter.subscription.amount, supporter.subscription.currency) }}
              </p>
            </div>
            <div>
              <p class="text-xs mb-1 m-0" style="color: var(--text-tertiary)">Next Renewal</p>
              <p class="text-sm font-medium m-0" style="color: var(--text-primary)">
                {{ supporter.subscription.current_period_end && supporter.subscription.current_period_end !== '0000-00-00 00:00:00'
                  ? new Date(supporter.subscription.current_period_end).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
                  : '--' }}
              </p>
            </div>
          </div>
        </div>

        <!-- Message Card -->
        <div v-if="supporter.supporters_message" class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6">
          <h3 class="text-sm font-semibold uppercase tracking-wide mt-0 mb-3" style="color: var(--text-secondary)">
            Message
          </h3>
          <div
            class="pl-4 py-2 text-sm leading-relaxed italic"
            style="border-left: 3px solid var(--color-primary-500); color: var(--text-primary)"
          >
            "{{ supporter.supporters_message }}"
          </div>
        </div>
      </div>

      <!-- Payment History (all transactions including renewals) -->
      <div
        v-if="supporter.transactions && supporter.transactions.length > 1"
        class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6 mb-6"
      >
        <h3 class="text-sm font-semibold uppercase tracking-wide mt-0 mb-4" style="color: var(--text-secondary)">
          Payment History
        </h3>
        <el-table :data="supporter.transactions" class="w-full">
          <el-table-column label="Date" min-width="150">
            <template #default="{ row }">
              <span class="text-sm" style="color: var(--text-primary)">{{ row.created_at }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Amount" min-width="120">
            <template #default="{ row }">
              <span class="text-sm font-medium" style="color: var(--text-primary)">
                {{ getFormatedAmount(row.payment_total, row.currency) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="Status" min-width="110">
            <template #default="{ row }">
              <StatusBadge :status="row.status" />
            </template>
          </el-table-column>
          <el-table-column label="Transaction ID" min-width="200">
            <template #default="{ row }">
              <a
                v-if="row.charge_id && row.transaction_url"
                :href="row.transaction_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 font-mono no-underline hover:underline"
                style="color: var(--color-primary-600); font-size: 12px"
              >
                {{ row.charge_id }}
                <ExternalLink :size="11" />
              </a>
              <span v-else-if="row.charge_id" class="font-mono text-xs" style="color: var(--text-primary)">{{ row.charge_id }}</span>
              <span v-else style="color: var(--text-tertiary)">--</span>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- Donation History (other form submissions from the same email) -->
      <div
        v-if="supporter.other_donations && supporter.other_donations.length > 1"
        class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6"
      >
        <h3 class="text-sm font-semibold uppercase tracking-wide mt-0 mb-4" style="color: var(--text-secondary)">
          Donation History
        </h3>
        <el-table
          :data="supporter.other_donations"
          class="w-full"
          @row-click="(row) => handleGet(row.id)"
          row-class-name="cursor-pointer"
        >
          <el-table-column label="Date" min-width="150">
            <template #default="{ row }">
              <span class="text-sm" style="color: var(--text-primary)">{{ row.created_at }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Amount" min-width="120">
            <template #default="{ row }">
              <span class="text-sm font-medium" style="color: var(--text-primary)">
                {{ getFormatedAmount(row.payment_total, row.currency) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="Status" min-width="110">
            <template #default="{ row }">
              <StatusBadge :status="row.payment_status" />
            </template>
          </el-table-column>
          <el-table-column label="Coffees" min-width="90">
            <template #default="{ row }">
              <div class="flex items-center gap-1">
                <Coffee :size="14" style="color: var(--text-tertiary)" />
                <span class="text-sm" style="color: var(--text-primary)">{{ parseInt(row.coffee_count || 0) }}</span>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- Activity Log -->
      <div class="mt-6 bg-white rounded-xl border border-neutral-200 shadow-xs overflow-hidden">
        <ActivityTimeline
          :supporter-id="Number(supporter.id)"
          :show-module="true"
        />
      </div>
        </section>
      </div>
    </template>

    <!-- Status Change Dialog -->
    <el-dialog
      v-model="statusModal"
      title="Change Payment Status"
      width="400px"
      :close-on-click-modal="false"
    >
      <div class="mb-4">
        <label class="block text-sm font-medium mb-2" style="color: var(--text-primary)">
          Select new status
        </label>
        <el-select v-model="paymentStatus" class="w-full" placeholder="Choose status">
          <el-option
            v-for="item in payment_statuses"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button
            class="px-4 py-2 text-sm font-medium rounded-lg border border-neutral-200 cursor-pointer"
            style="background: var(--bg-primary); color: var(--text-primary)"
            @click="statusModal = false"
          >
            Cancel
          </button>
          <button
            class="px-4 py-2 text-sm font-medium rounded-lg border-none cursor-pointer text-white"
            style="background: var(--color-primary-500)"
            @click="updateStatus"
          >
            Save
          </button>
        </div>
      </template>
    </el-dialog>

    <!-- Refund Confirmation Dialog -->
    <el-dialog
      v-model="refundModal"
      :title="refundResult ? 'Refund Result' : 'Refund Transaction'"
      width="460px"
      :close-on-click-modal="!refunding"
      :close-on-press-escape="!refunding"
      :show-close="!refunding"
      append-to-body
      @closed="onRefundModalClosed"
    >
      <!-- Confirmation view (before refund) -->
      <template v-if="!refundResult && !refunding">
        <div v-if="supporter.transaction" class="bmc-refund-details">
          <div class="bmc-refund-details__row">
            <span class="bmc-refund-details__label">Transaction ID</span>
            <span class="bmc-refund-details__value font-mono text-xs">{{ supporter.transaction.charge_id }}</span>
          </div>
          <div class="bmc-refund-details__row">
            <span class="bmc-refund-details__label">Amount</span>
            <span class="bmc-refund-details__value bmc-refund-details__amount">
              {{ getFormatedAmount(supporter.transaction.payment_total, supporter.transaction.currency) }}
            </span>
          </div>
          <div class="bmc-refund-details__row">
            <span class="bmc-refund-details__label">Payment Method</span>
            <span class="bmc-refund-details__value" style="text-transform: capitalize">{{ supporter.transaction.payment_method }}</span>
          </div>
          <div class="bmc-refund-details__row">
            <span class="bmc-refund-details__label">Supporter</span>
            <span class="bmc-refund-details__value">{{ supporter.supporters_name || 'Anonymous' }}</span>
          </div>

          <!-- Cancel subscription option for recurring transactions -->
          <div
            v-if="supporter.subscription && supporter.subscription.status !== 'cancelled'"
            class="bmc-refund-cancel-sub"
          >
            <el-checkbox v-model="cancelSubscriptionOnRefund">
              Cancel subscription before refunding
            </el-checkbox>
            <p class="bmc-refund-cancel-sub__hint">
              This will cancel the recurring subscription on {{ supporter.transaction.payment_method }} and then process the refund.
            </p>
          </div>

          <div class="bmc-refund-warning">
            <RotateCcw :size="14" />
            This will issue a refund through the payment gateway. This action cannot be undone.
          </div>
        </div>
      </template>

      <!-- Processing view -->
      <template v-if="refunding && !refundResult">
        <div class="bmc-refund-processing">
          <div class="bmc-refund-processing__spinner"></div>
          <p class="bmc-refund-processing__text">Processing with {{ supporter.transaction?.payment_method }}...</p>
          <p class="bmc-refund-processing__hint">Please wait, do not close this window.</p>
        </div>
      </template>

      <!-- Result view (after gateway responds) -->
      <template v-if="refundResult">
        <div class="bmc-refund-result">
          <div class="bmc-refund-result__icon" :class="'bmc-refund-result__icon--' + refundResult.type">
            <component :is="refundResult.type === 'error' ? XCircle : CheckCircle" :size="40" />
          </div>
          <h3 class="bmc-refund-result__title">{{ refundResult.title }}</h3>
          <p class="bmc-refund-result__message">{{ refundResult.message }}</p>
          <div v-if="refundResult.details" class="bmc-refund-result__details">
            <div v-for="(val, label) in refundResult.details" :key="label" class="bmc-refund-details__row">
              <span class="bmc-refund-details__label">{{ label }}</span>
              <span class="bmc-refund-details__value">{{ val }}</span>
            </div>
          </div>
        </div>
      </template>

      <template #footer>
        <template v-if="!refundResult && !refunding">
          <el-button @click="refundModal = false">Cancel</el-button>
          <el-button type="danger" @click="refundTransaction">Confirm Refund</el-button>
        </template>
        <template v-if="refundResult">
          <el-button type="primary" @click="closeRefundAndReload">Done</el-button>
        </template>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { ArrowLeft, Mail, ExternalLink, Edit3, Coffee, Clock, MoreVertical, RotateCcw, CheckCircle, XCircle } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';
import StatusBadge from './UI/StatusBadge.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import ActivityTimeline from './ActivityTimeline.vue';

export default {
  name: 'Supporter',
  components: {
    ArrowLeft,
    Mail,
    ExternalLink,
    Edit3,
    Coffee,
    Clock,
    MoreVertical,
    RotateCcw,
    CheckCircle,
    XCircle,
    StatusBadge,
    CoffeeLoader,
    ActivityTimeline,
  },
  data() {
    return {
      supporter: {},
      loading: false,
      paymentStatus: '',
      statusModal: false,
      moreOpen: false,
      refunding: false,
      refundModal: false,
      refundResult: null,
      cancelSubscriptionOnRefund: true,
      payment_statuses: [
        { label: 'Paid', value: 'paid' },
        { label: 'Pending', value: 'pending' },
        { label: 'Refunded', value: 'refunded' },
        { label: 'Cancelled', value: 'cancelled' },
        { label: 'Failed', value: 'failed' },
      ],
    };
  },
  methods: {
    handleGet(id) {
      this.$router.push({ name: 'Supporter', params: { id } }).then(() => {
        this.getSupporter();
      });
    },
    getFormatedAmount(amount, currency) {
      const value = parseInt(amount) / 100;
      const symbols = {
        USD: '$', EUR: '\u20AC', GBP: '\u00A3', JPY: '\u00A5',
        AUD: 'A$', CAD: 'C$', INR: '\u20B9', BDT: '\u09F3',
      };
      const symbol = symbols[currency] || (currency ? currency + ' ' : '$');
      return symbol + value;
    },
    updateStatus() {
      ElMessageBox.confirm(
        'Are you sure you want to change the payment status?',
        'Confirm Status Change',
        {
          confirmButtonText: 'Confirm',
          cancelButtonText: 'Cancel',
          type: 'warning',
        }
      )
        .then(() => {
          this.$post({
            action: 'buymecoffee_admin_ajax',
            route: 'update_payment_status',
            data: {
              id: this.$route.params.id,
              status: this.paymentStatus,
            },
            buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
          }).then(() => {
            this.statusModal = false;
            this.getSupporter();
            this.$handleSuccess('Payment status updated successfully');
          });
        })
        .catch(() => {
          ElMessage({
            type: 'info',
            message: 'Status update cancelled',
          });
        });
    },
    openRefundModal() {
      this.moreOpen = false;
      this.refundResult = null;
      this.cancelSubscriptionOnRefund = !!(this.supporter.subscription && this.supporter.subscription.status !== 'cancelled');
      this.refundModal = true;
    },
    refundTransaction() {
      this.refunding = true;

      const shouldCancelSub = this.cancelSubscriptionOnRefund
        && this.supporter.subscription
        && this.supporter.subscription.status !== 'cancelled';

      const proceed = shouldCancelSub
        ? this.doCancelSubscription()
        : Promise.resolve();

      proceed.then(() => {
        return this.doRefund();
      }).catch(() => {
        this.refunding = false;
      });
    },
    doCancelSubscription() {
      return new Promise((resolve, reject) => {
        this.$post({
          action: 'buymecoffee_admin_ajax',
          route: 'cancel_subscription',
          data: { id: this.supporter.subscription.id },
          buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
        }).then(() => {
          resolve();
        }).fail((error) => {
          const msg = error?.responseJSON?.data?.message || 'Failed to cancel subscription.';
          this.refundResult = {
            type: 'error',
            title: 'Subscription Cancellation Failed',
            message: msg + ' Refund was not processed.',
            details: null,
          };
          this.refunding = false;
          reject();
        });
      });
    },
    doRefund() {
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'refund_transaction',
        data: { id: this.supporter.transaction.id },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        const data = response?.data || {};
        const status = data.refund_status || 'succeeded';
        const details = {};
        details['Amount'] = this.getFormatedAmount(this.supporter.transaction.payment_total, this.supporter.transaction.currency);
        if (data.refund_id) details['Refund ID'] = data.refund_id;
        if (data.gateway_status) details['Gateway Status'] = data.gateway_status;
        details['Transaction Status'] = status === 'pending' ? 'Pending' : 'Refunded';
        if (this.cancelSubscriptionOnRefund && this.supporter.subscription) {
          details['Subscription'] = 'Cancelled';
        }

        this.refundResult = {
          type: status === 'pending' ? 'warning' : 'success',
          title: status === 'pending' ? 'Refund Pending' : 'Refund Successful',
          message: data.message || 'The refund has been processed.',
          details,
        };
      }).fail((error) => {
        const msg = error?.responseJSON?.data?.message || 'Refund failed. Please try again or check your gateway dashboard.';
        const subCancelled = this.cancelSubscriptionOnRefund && this.supporter.subscription;
        this.refundResult = {
          type: 'error',
          title: 'Refund Failed',
          message: msg + (subCancelled ? ' Note: the subscription was already cancelled.' : ''),
          details: null,
        };
      }).always(() => {
        this.refunding = false;
      });
    },
    closeRefundAndReload() {
      this.refundModal = false;
      this.getSupporter();
    },
    onRefundModalClosed() {
      if (this.refundResult) {
        this.refundResult = null;
        this.getSupporter();
      }
    },
    onClickOutside(e) {
      if (this.$refs.moreMenu && !this.$refs.moreMenu.contains(e.target)) {
        this.moreOpen = false;
      }
    },
    getSupporter() {
      this.loading = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_supporter',
        data: {
          id: this.$route.params.id,
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      })
        .then((response) => {
          this.supporter = response.data;
          this.paymentStatus = response.data.payment_status;
          this.loading = false;
        })
        .catch((e) => {
          this.loading = false;
          this.$handleError(e);
        });
    },
  },
  mounted() {
    this.getSupporter();
    document.addEventListener('click', this.onClickOutside);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onClickOutside);
  },
};
</script>

<style scoped>
.bmc-supporter-page {
  max-width: 1440px;
}

.bmc-supporter-layout {
  display: grid;
  grid-template-columns: minmax(280px, 320px) minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}

.bmc-supporter-sidebar {
  display: flex;
  flex-direction: column;
  gap: 14px;
  position: sticky;
  top: 0;
}

.bmc-supporter-profile-card,
.bmc-supporter-sidebar-card {
  background: var(--bg-primary);
  border: 1px solid var(--border-secondary);
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.bmc-supporter-profile-card {
  padding: 20px;
}

.bmc-supporter-sidebar-card {
  padding: 16px;
}

.bmc-supporter-avatar {
  width: 64px;
  height: 64px;
  border-radius: 999px;
  border: 2px solid var(--border-secondary);
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.bmc-supporter-avatar--placeholder {
  background: var(--color-primary-50);
  color: var(--color-primary-600);
}

.bmc-supporter-avatar--placeholder span {
  font-size: 22px;
  font-weight: 800;
  text-transform: uppercase;
}

.bmc-supporter-name {
  margin: 14px 0 0;
  color: var(--text-primary);
  font-size: 20px;
  font-weight: 700;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.bmc-supporter-email {
  margin: 4px 0 0;
  color: var(--text-secondary);
  font-size: 13px;
  overflow-wrap: anywhere;
}

.bmc-supporter-badges {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.bmc-supporter-method {
  display: inline-flex;
  align-items: center;
  height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  border: 1px solid var(--border-secondary);
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 500;
  text-transform: capitalize;
}

.bmc-supporter-created {
  margin: 10px 0 0;
  color: var(--text-tertiary);
  font-size: 12px;
}

.bmc-supporter-warning {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--color-warning-300);
  background: var(--color-warning-50);
  color: var(--color-warning-700);
  font-size: 13px;
  line-height: 1.45;
}

.bmc-supporter-actions {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
  margin-top: 16px;
}

.bmc-supporter-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 36px;
  padding: 0 12px;
  border-radius: 8px;
  border: 1px solid var(--border-primary);
  background: var(--bg-primary);
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
  text-decoration: none;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}

.bmc-supporter-action:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}

.bmc-supporter-action--primary {
  border-color: transparent;
  background: var(--color-primary-50);
  color: var(--color-primary-700);
}

.bmc-supporter-action--primary:hover {
  background: var(--color-primary-100);
  color: var(--color-primary-800);
}

.bmc-supporter-action--icon {
  width: 100%;
}

.bmc-sidebar-section-title {
  margin: 0 0 12px;
  color: var(--text-secondary);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.bmc-supporter-stat-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.bmc-supporter-stat {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border-secondary);
}

.bmc-supporter-stat:last-child {
  border-bottom: none;
}

.bmc-supporter-stat__label {
  color: var(--text-secondary);
  font-size: 13px;
}

.bmc-supporter-stat__value {
  color: var(--text-primary);
  font-size: 14px;
  font-weight: 700;
  text-align: right;
}

.bmc-supporter-main {
  min-width: 0;
}

.bmc-card-header-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.bmc-card-more-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border-primary);
  background: var(--bg-primary);
  color: var(--text-secondary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.bmc-card-more-btn:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
}

.bmc-back-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 500;
  color: var(--text-secondary);
  background: transparent;
  border: none;
  padding: 0;
  margin-bottom: 20px;
  cursor: pointer;
  transition: color 0.15s;
}
.bmc-back-btn:hover {
  color: var(--text-primary);
  background: transparent;
}

/* Three-dot dropdown */
.bmc-more-menu {
  position: relative;
  display: inline-flex;
}
.bmc-more-dropdown {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  min-width: 190px;
  background: var(--bg-primary);
  border: 1px solid var(--border-primary);
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  z-index: 100;
  padding: 4px;
}
.bmc-more-item {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  text-align: left;
  border: none;
  background: transparent;
  cursor: pointer;
  transition: background 0.15s;
  color: var(--text-primary);
}
.bmc-more-item--danger {
  color: #dc2626;
}
.bmc-more-item--danger:hover:not(:disabled) {
  background: #fee2e2;
}
.bmc-more-item:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.bmc-more-item--disabled {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  color: var(--text-tertiary);
  opacity: 0.5;
  cursor: not-allowed;
}

.bmc-refund-details {
  display: flex;
  flex-direction: column;
  gap: 0;
}
.bmc-refund-details__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid var(--border-secondary);
}
.bmc-refund-details__row:last-of-type { border-bottom: none; }
.bmc-refund-details__label {
  font-size: 13px;
  color: var(--text-tertiary);
}
.bmc-refund-details__value {
  font-size: 14px;
  font-weight: 500;
  color: var(--text-primary);
}
.bmc-refund-details__amount {
  font-size: 16px;
  font-weight: 700;
  color: #dc2626;
}
.bmc-two-col-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.bmc-two-col-row--single {
  grid-template-columns: 1fr;
}
@media (max-width: 768px) {
  .bmc-two-col-row { grid-template-columns: 1fr; }
}

@media (max-width: 1180px) {
  .bmc-supporter-layout {
    grid-template-columns: 1fr;
  }

  .bmc-supporter-sidebar {
    position: static;
  }

  .bmc-supporter-profile-card {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr);
    gap: 0 16px;
    align-items: start;
  }

  .bmc-supporter-name,
  .bmc-supporter-email,
  .bmc-supporter-badges,
  .bmc-supporter-created {
    grid-column: 2;
  }

  .bmc-supporter-warning,
  .bmc-supporter-actions {
    grid-column: 1 / -1;
  }
}

@media (max-width: 640px) {
  .bmc-supporter-profile-card {
    display: block;
  }

  .bmc-supporter-layout {
    gap: 16px;
  }
}

.bmc-refund-cancel-sub {
  margin-top: 14px;
  padding: 12px;
  border-radius: 8px;
  background: var(--bg-tertiary);
  border: 1px solid var(--border-secondary);
}
.bmc-refund-cancel-sub__hint {
  font-size: 12px;
  color: var(--text-tertiary);
  margin: 6px 0 0;
  line-height: 1.4;
}

.bmc-refund-warning {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-top: 16px;
  padding: 12px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.5;
  background: #fef2f2;
  color: #991b1b;
}

.bmc-refund-processing {
  text-align: center;
  padding: 24px 0;
}
.bmc-refund-processing__spinner {
  width: 40px; height: 40px; margin: 0 auto 16px;
  border: 3px solid var(--border-secondary);
  border-top-color: var(--color-primary-600);
  border-radius: 50%;
  animation: bmc-spin 0.8s linear infinite;
}
@keyframes bmc-spin { to { transform: rotate(360deg); } }
.bmc-refund-processing__text {
  font-size: 14px; font-weight: 500; color: var(--text-primary); margin: 0;
}
.bmc-refund-processing__hint {
  font-size: 12px; color: var(--text-tertiary); margin: 6px 0 0;
}

.bmc-refund-result {
  text-align: center;
  padding: 12px 0;
}
.bmc-refund-result__icon {
  margin: 0 auto 12px;
  width: 48px; height: 48px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 50%;
}
.bmc-refund-result__icon--success { color: #16a34a; background: #dcfce7; }
.bmc-refund-result__icon--warning { color: #d97706; background: #fef3c7; }
.bmc-refund-result__icon--error   { color: #dc2626; background: #fee2e2; }
.bmc-refund-result__title {
  font-size: 16px; font-weight: 600; margin: 0 0 6px; color: var(--text-primary);
}
.bmc-refund-result__message {
  font-size: 13px; color: var(--text-secondary); margin: 0 0 16px; line-height: 1.5;
}
.bmc-refund-result__details {
  text-align: left;
  border: 1px solid var(--border-secondary);
  border-radius: 8px;
  overflow: hidden;
}
.bmc-refund-result__details .bmc-refund-details__row {
  padding: 10px 14px;
}
</style>
