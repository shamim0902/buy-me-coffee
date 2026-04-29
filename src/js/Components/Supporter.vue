<template>
  <div class="relative min-h-[200px]">
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
      <!-- Profile Header Card -->
      <div class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
          <!-- Left: Avatar + Info -->
          <div class="flex items-start gap-4">
            <img
              v-if="supporter.supporters_image"
              :src="supporter.supporters_image"
              :alt="supporter.supporters_name"
              class="w-16 h-16 rounded-full border-2 border-neutral-200 object-cover flex-shrink-0"
            />
            <div
              v-else
              class="w-16 h-16 rounded-full border-2 border-neutral-200 flex items-center justify-center flex-shrink-0"
              style="background: var(--color-primary-50); color: var(--color-primary-600)"
            >
              <span class="text-xl font-bold uppercase">{{ (supporter.supporters_name || 'A').charAt(0) }}</span>
            </div>

            <div>
              <h2 class="text-xl font-bold m-0" style="color: var(--text-primary)">
                {{ supporter.supporters_name || 'Anonymous' }}
              </h2>
              <p v-if="supporter.supporters_email" class="text-sm mt-0.5 mb-0" style="color: var(--text-secondary)">
                {{ supporter.supporters_email }}
              </p>
              <div class="flex flex-wrap items-center gap-2 mt-2">
                <StatusBadge :status="supporter.payment_status" />
                <span v-if="supporter.payment_method" class="text-xs px-2 py-0.5 rounded-full border border-neutral-200" style="color: var(--text-secondary)">
                  {{ supporter.payment_method }}
                </span>
                <span class="text-xs" style="color: var(--text-tertiary)">
                  {{ supporter.created_at }}
                </span>
              </div>
            </div>
          </div>

          <!-- Right: Action Buttons -->
          <div class="flex flex-wrap items-center gap-2">
            <a
              v-if="supporter.supporters_email"
              :href="'mailto:' + supporter.supporters_email"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg border border-neutral-200 no-underline transition-colors"
              style="color: var(--text-primary); background: var(--bg-primary)"
            >
              <Mail :size="14" />
              Send Email
            </a>
            <a
              v-if="supporter.transaction && supporter.transaction.transaction_url"
              :href="supporter.transaction.transaction_url"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg border border-neutral-200 no-underline transition-colors"
              style="color: var(--text-primary); background: var(--bg-primary)"
            >
              <ExternalLink :size="14" />
              View on {{ supporter.payment_method }}
            </a>
            <button
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg border-none cursor-pointer transition-colors"
              style="background: var(--color-primary-50); color: var(--color-primary-700)"
              @click="statusModal = true"
            >
              <Edit3 :size="14" />
              Change Status
            </button>

            <!-- Three-dot more menu -->
            <div class="bmc-more-menu" ref="moreMenu">
              <button
                class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-neutral-200 cursor-pointer transition-colors"
                style="background: var(--bg-primary); color: var(--text-secondary)"
                @click.stop="moreOpen = !moreOpen"
              >
                <MoreVertical :size="15" />
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
        </div>

        <!-- Warning for paid-initially -->
        <div
          v-if="supporter.payment_status === 'paid-initially'"
          class="mt-4 flex items-center gap-2 text-sm px-3 py-2 rounded-lg border"
          style="background: var(--color-warning-50); border-color: var(--color-warning-300); color: var(--color-warning-700)"
        >
          <Clock :size="14" />
          Please verify this transaction before marking as paid.
        </div>
      </div>

      <!-- Stat Boxes -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <MetricCard
          label="All-time Paid"
          :value="supporter.all_time_total_paid || '$0'"
          icon="DollarSign"
          color="primary"
        />
        <MetricCard
          label="All-time Pending"
          :value="supporter.all_time_total_pending || '$0'"
          icon="Clock"
          color="amber"
        />
        <MetricCard
          label="Total Coffees"
          :value="supporter.all_time_total_coffee || '0'"
          icon="Coffee"
          color="violet"
        />
      </div>

      <!-- Message Card -->
      <div
        v-if="supporter.supporters_message"
        class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6 mb-6"
      >
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

      <!-- Transaction Details Card -->
      <div
        v-if="supporter.transaction"
        class="bg-white rounded-xl border border-neutral-200 shadow-xs p-6 mb-6"
      >
        <h3 class="text-sm font-semibold uppercase tracking-wide mt-0 mb-4" style="color: var(--text-secondary)">
          Transaction Details
        </h3>
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
          <p class="bmc-refund-processing__text">Processing refund with {{ supporter.transaction?.payment_method }}...</p>
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
import { ArrowLeft, Mail, ExternalLink, Edit3, Coffee, DollarSign, Clock, MoreVertical, RotateCcw, CheckCircle, XCircle } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';
import PageTitle from './UI/PageTitle.vue';
import StatusBadge from './UI/StatusBadge.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import MetricCard from './UI/MetricCard.vue';
import ActivityTimeline from './ActivityTimeline.vue';

export default {
  name: 'Supporter',
  components: {
    ArrowLeft,
    Mail,
    ExternalLink,
    Edit3,
    Coffee,
    DollarSign,
    Clock,
    MoreVertical,
    RotateCcw,
    CheckCircle,
    XCircle,
    PageTitle,
    StatusBadge,
    MetricCard,
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
      this.refundModal = true;
    },
    refundTransaction() {
      this.refunding = true;
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'refund_transaction',
        data: { id: this.supporter.transaction.id },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce,
      }).then((response) => {
        const data = response?.data || {};
        const status = data.refund_status || 'succeeded';
        const details = {};
        if (data.refund_id) details['Refund ID'] = data.refund_id;
        if (data.gateway_status) details['Gateway Status'] = data.gateway_status;
        details['Amount'] = this.getFormatedAmount(this.supporter.transaction.payment_total, this.supporter.transaction.currency);

        this.refundResult = {
          type: status === 'pending' ? 'warning' : 'success',
          title: status === 'pending' ? 'Refund Pending' : 'Refund Successful',
          message: data.message || 'The refund has been processed.',
          details,
        };
      }).fail((error) => {
        const msg = error?.responseJSON?.data?.message || 'Refund failed. Please try again or check your gateway dashboard.';
        this.refundResult = {
          type: 'error',
          title: 'Refund Failed',
          message: msg,
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
