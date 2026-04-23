<template>
    <div>
        <el-table :data="tableData" class="w-full" :header-cell-style="{ background: 'var(--bg-secondary)', color: 'var(--text-primary)' }">
            <el-table-column prop="name" label="Name" min-width="180">
                <template #default="scope">
                    <span class="text-sm font-medium text-[var(--text-primary)]">{{ scope.row.name }}</span>
                </template>
            </el-table-column>

            <el-table-column prop="trigger_on" label="Trigger" min-width="180">
                <template #default="scope">
                    <span
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium"
                        :class="scope.row.trigger_on === 'on_submit'
                            ? 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20'
                            : 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20'"
                    >
                        {{ scope.row.trigger_on === 'on_submit' ? 'On Submit' : 'On Payment Paid' }}
                    </span>
                </template>
            </el-table-column>

            <el-table-column label="Actions" width="180" align="right">
                <template #default="scope">
                    <div class="flex items-center justify-end gap-2">
                        <button
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-neutral-200 bg-white text-[var(--text-secondary)] hover:bg-neutral-50 hover:text-[var(--text-primary)] transition-colors cursor-pointer"
                            @click="handleEdit(scope.$index, scope.row)"
                        >
                            <Pencil :size="13" />
                            Edit
                        </button>
                        <button
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 bg-white text-red-600 hover:bg-red-50 transition-colors cursor-pointer"
                            @click="handleDelete(scope.$index, scope.row)"
                        >
                            <Trash2 :size="13" />
                            Delete
                        </button>
                    </div>
                </template>
            </el-table-column>
        </el-table>
    </div>
</template>

<script>
import { Pencil, Trash2 } from 'lucide-vue-next';

export default {
    name: 'Emails',
    components: {
        Pencil,
        Trash2,
    },
    data() {
        return {
            tableData: [
                {
                    name: 'John Brown',
                    trigger_on: 'on_submit',
                },
                {
                    name: 'Jim Green',
                    trigger_on: 'on_submit',
                },
                {
                    name: 'Joe Black',
                    trigger_on: 'on_payment_paid',
                },
                {
                    name: 'Jon Snow',
                    trigger_on: 'on_payment_paid',
                },
            ],
        };
    },
    methods: {
        handleEdit(index, row) {
            console.log('Edit:', index, row);
        },
        handleDelete(index, row) {
            this.tableData.splice(index, 1);
        },
    },
};
</script>
