<template>
  <div>
    <el-table
      v-loading="loading"
      element-loading-text="Loading supporters..."
      :data="supporters"
      class="bmc-table"
      :row-class-name="() => 'bmc-table__row'"
      @row-click="(row) => handleView(row.id)"
    >
      <!-- Supporter (name + email stacked) -->
      <el-table-column label="Supporter" min-width="220">
        <template #default="{ row }">
          <div class="flex flex-col gap-0.5">
            <span
              class="bmc-supporter-name"
              @click.stop="handleView(row.id)"
            >
              {{ row.supporters_name || 'Anonymous' }}
            </span>
            <span class="bmc-supporter-email">{{ row.supporters_email }}</span>
          </div>
        </template>
      </el-table-column>

      <!-- Amount -->
      <el-table-column label="Amount" width="140">
        <template #default="{ row }">
          <span class="bmc-amount">{{ stripHtml(row.amount_formatted) }}</span>
        </template>
      </el-table-column>

      <!-- Status -->
      <el-table-column label="Status" width="120">
        <template #default="{ row }">
          <StatusBadge :status="row.payment_status" />
        </template>
      </el-table-column>

      <!-- Method -->
      <el-table-column label="Method" width="110">
        <template #default="{ row }">
          <span class="bmc-method">{{ row.payment_method ? ucFirst(row.payment_method) : '-' }}</span>
        </template>
      </el-table-column>

      <!-- Mode -->
      <el-table-column label="Mode" width="100">
        <template #default="{ row }">
          <span class="bmc-mode" :class="row.transaction_type === 'recurring' ? 'bmc-mode--recurring' : 'bmc-mode--onetime'">
            {{ row.transaction_type === 'recurring' ? 'Recurring' : 'One-time' }}
          </span>
        </template>
      </el-table-column>

      <!-- Date -->
      <el-table-column label="Date" width="160">
        <template #default="{ row }">
          <span class="bmc-date">{{ row.created_at }}</span>
        </template>
      </el-table-column>

      <!-- Actions -->
      <el-table-column label="Actions" width="100" align="right">
        <template #default="{ row }">
          <div class="flex items-center justify-end gap-1" @click.stop>
            <button class="bmc-action-btn" title="View" @click="handleView(row.id)">
              <Eye :size="15" />
            </button>
            <button class="bmc-action-btn bmc-action-btn--danger" title="Delete" @click="confirmDelete(row.id)">
              <Trash2 :size="15" />
            </button>
          </div>
        </template>
      </el-table-column>

      <!-- Empty state -->
      <template #empty>
        <EmptyState
          v-if="!loading"
          title="No transactions found"
          description="Transactions will appear here once someone makes a donation."
          icon="Heart"
        />
      </template>
    </el-table>
  </div>
</template>

<script>
import { Eye, Trash2 } from 'lucide-vue-next';
import { ElMessageBox } from 'element-plus';
import StatusBadge from './UI/StatusBadge.vue';
import EmptyState from './UI/EmptyState.vue';

export default {
  name: 'SupportersTable',
  components: {
    Eye,
    Trash2,
    StatusBadge,
    EmptyState
  },
  props: {
    supporters: {
      type: Array,
      default: () => []
    },
    loading: {
      type: Boolean,
      default: false
    }
  },
  emits: ['deleted'],
  methods: {
    stripHtml(str) {
      if (!str) return '';
      const div = document.createElement('div');
      div.innerHTML = str;
      return div.textContent || div.innerText || '';
    },
    ucFirst(text) {
      if (!text) return '';
      return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
    },
    handleView(id) {
      this.$router.push({ name: 'Supporter', params: { id } });
    },
    confirmDelete(id) {
      ElMessageBox.confirm('This transaction and all related data will be permanently removed.', 'Delete transaction?', {
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel',
        type: 'warning',
      }).then(() => this.handleDelete(id)).catch(() => {});
    },
    handleDelete(id) {
      this.$post({
        action: 'buymecoffee_admin_ajax',
        route: 'delete_supporter',
        data: { id },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
        .then(() => {
          this.$handleSuccess('Supporter has been deleted successfully.');
          this.$emit('deleted');
        })
        .fail((error) => {
          this.$handleError(error);
        });
    }
  }
};
</script>

<style scoped>
.bmc-table {
  --el-table-border-color: var(--border-secondary);
  --el-table-header-bg-color: var(--bg-secondary);
  --el-table-header-text-color: var(--text-secondary);
  --el-table-row-hover-bg-color: var(--bg-secondary);
  --el-table-text-color: var(--text-primary);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.bmc-table :deep(.bmc-table__row) {
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-default);
}

.bmc-table :deep(th .cell) {
  font-size: var(--text-xs);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-family: var(--font-sans);
}

.bmc-table :deep(td .cell) {
  font-size: var(--text-base);
  font-family: var(--font-sans);
}

.bmc-supporter-name {
  font-weight: 600;
  color: var(--color-primary-600);
  cursor: pointer;
  transition: color var(--duration-fast) var(--ease-default);
}
.bmc-supporter-name:hover {
  color: var(--color-primary-700);
  text-decoration: underline;
}

.bmc-supporter-email {
  font-size: var(--text-xs);
  color: var(--text-tertiary);
}

.bmc-amount {
  font-family: var(--font-mono);
  font-weight: 600;
  font-size: var(--text-base);
  color: var(--text-primary);
}

.bmc-method {
  font-size: var(--text-sm);
  color: var(--text-secondary);
  text-transform: capitalize;
}

.bmc-mode {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  font-size: 11px;
  font-weight: 500;
  font-family: var(--font-sans);
}
.bmc-mode--recurring {
  background: var(--color-accent-purple-light);
  color: var(--color-accent-purple);
}
.bmc-mode--onetime {
  background: var(--bg-tertiary);
  color: var(--text-secondary);
}

.bmc-date {
  font-size: var(--text-sm);
  color: var(--text-secondary);
  white-space: nowrap;
}

/* Action buttons */
.bmc-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: 1px solid var(--border-primary);
  border-radius: var(--radius-md);
  background: var(--bg-primary);
  color: var(--text-secondary);
  cursor: pointer;
  transition: all var(--duration-fast) var(--ease-default);
}
.bmc-action-btn:hover {
  background: var(--bg-tertiary);
  color: var(--text-primary);
  border-color: var(--color-neutral-300);
}
.bmc-action-btn--danger:hover {
  background: var(--color-error-50);
  color: var(--color-error-600);
  border-color: var(--color-error-500);
}
</style>
