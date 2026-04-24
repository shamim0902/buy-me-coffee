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
              v-if="supporter.transaction_url"
              :href="supporter.transaction_url"
              target="_blank"
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
            <p class="text-sm font-medium m-0" style="color: var(--text-primary)">{{ supporter.transaction.charge_id }}</p>
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
            <p class="text-sm font-medium m-0" style="color: var(--text-primary)">{{ supporter.transaction.status }}</p>
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

      <!-- Donation History -->
      <div
        v-if="supporter.other_donations && supporter.other_donations.length"
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
  </div>
</template>

<script>
import { ArrowLeft, Mail, ExternalLink, Edit3, Coffee, DollarSign, Clock } from 'lucide-vue-next';
import { ElMessage, ElMessageBox } from 'element-plus';
import PageTitle from './UI/PageTitle.vue';
import StatusBadge from './UI/StatusBadge.vue';
import CoffeeLoader from './UI/CoffeeLoader.vue';
import MetricCard from './UI/MetricCard.vue';

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
    PageTitle,
    StatusBadge,
    MetricCard,
    CoffeeLoader,
  },
  data() {
    return {
      supporter: {},
      loading: false,
      paymentStatus: '',
      statusModal: false,
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
</style>
