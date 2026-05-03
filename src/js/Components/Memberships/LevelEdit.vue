<template>
  <div class="relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />

    <template v-if="!loading">
      <button class="bmc-back-btn" @click="vm?.$router?.push({ name: 'Memberships', query: { tab: 'levels' } })">
        <ArrowLeft :size="16" />
        Back to Levels
      </button>

      <PageTitle
        :title="isNew ? 'Add Membership Level' : 'Edit Membership Level'"
        subtitle="Set the price, rewards, and content access rules for this level"
      />

      <div class="bmc-level-edit-layout">
        <!-- Main form -->
        <div class="bmc-level-edit-main">

          <!-- Basic info -->
          <div class="bmc-card">
            <h3 class="bmc-sc__title" style="margin-bottom:16px">Level Details</h3>

            <div class="bmc-sr bmc-sr--field">
              <label class="bmc-label">Level name <span class="bmc-required">*</span></label>
              <el-input v-model="form.name" placeholder="e.g. Community Member" />
            </div>

            <div class="bmc-sr bmc-sr--field">
              <label class="bmc-label">Description</label>
              <el-input v-model="form.description" type="textarea" :rows="3" placeholder="Briefly describe this level…" />
            </div>

            <div class="bmc-2col">
              <div class="bmc-sr bmc-sr--field">
                <label class="bmc-label">Price</label>
                <el-input-number v-model="displayPrice" :min="0" :precision="2" :step="1" style="width:100%" />
                <p class="bmc-hint">Amount in your account currency.</p>
              </div>

              <div class="bmc-sr bmc-sr--field">
                <label class="bmc-label">Billing interval</label>
                <el-radio-group v-model="form.interval_type">
                  <el-radio-button value="month">Monthly</el-radio-button>
                  <el-radio-button value="year">Yearly</el-radio-button>
                </el-radio-group>
              </div>
            </div>

            <div class="bmc-sr">
              <div class="bmc-toggle-row__text">
                <p class="bmc-toggle-row__label">Active</p>
                <p class="bmc-toggle-row__desc">Inactive levels won't be shown on paywall CTAs.</p>
              </div>
              <el-switch v-model="form.status" active-value="active" inactive-value="inactive" />
            </div>
          </div>

          <!-- Rewards -->
          <div class="bmc-card">
            <h3 class="bmc-sc__title" style="margin-bottom:6px">Rewards</h3>
            <p class="bmc-sc__sub" style="margin-bottom:16px">Bullet-point perks shown on the paywall CTA and member portal.</p>

            <div class="bmc-rewards-list">
              <div v-for="(reward, i) in form.rewards" :key="i" class="bmc-reward-row">
                <el-input v-model="form.rewards[i]" :placeholder="'e.g. Access to exclusive posts'" style="flex:1" />
                <button class="bmc-reward-remove" @click="removeReward(i)" title="Remove">
                  <X :size="14" />
                </button>
              </div>
            </div>

            <button class="bmc-add-reward-btn" @click="addReward">
              <Plus :size="14" />
              Add reward
            </button>
          </div>

          <!-- Content access rules -->
          <div class="bmc-card">
            <button class="bmc-collapsible-hd" @click="showAccess = !showAccess">
              <div>
                <h3 class="bmc-sc__title">Content Access Rules</h3>
                <p class="bmc-sc__sub">Which content this level can access.</p>
              </div>
              <ChevronDown :size="16" :class="showAccess ? 'bmc-chevron--open' : ''" class="bmc-chevron" />
            </button>

            <template v-if="showAccess">
              <div class="bmc-sr bmc-sr--field" style="margin-top:16px">
                <label class="bmc-label">Access type</label>
                <el-radio-group v-model="form.access_rules.access_level">
                  <el-radio-button value="full">Full access</el-radio-button>
                  <el-radio-button value="preview">Preview only (word-limited)</el-radio-button>
                </el-radio-group>
              </div>

              <div class="bmc-sr bmc-sr--field">
                <label class="bmc-label">Preview word count</label>
                <el-input-number v-model="form.access_rules.preview_words" :min="10" :max="1000" style="width:140px" />
                <p class="bmc-hint">Words non-members see before the paywall (for this level's content).</p>
              </div>

              <div class="bmc-sr bmc-sr--field">
                <label class="bmc-label">Post types</label>
                <div class="bmc-checkbox-group">
                  <el-checkbox
                    v-for="pt in postTypes"
                    :key="pt.name"
                    v-model="form.access_rules.post_types"
                    :label="pt.name"
                  >{{ pt.label }}</el-checkbox>
                </div>
                <p class="bmc-hint">Leave empty to allow all post types.</p>
              </div>

              <div class="bmc-sr bmc-sr--field">
                <label class="bmc-label">Categories</label>
                <el-select
                  v-model="form.access_rules.categories"
                  multiple
                  filterable
                  placeholder="All categories"
                  style="width:100%"
                >
                  <el-option
                    v-for="cat in categories"
                    :key="cat.id"
                    :label="cat.name"
                    :value="cat.id"
                  />
                </el-select>
                <p class="bmc-hint">Leave empty to allow all categories.</p>
              </div>
            </template>
          </div>

        </div>

        <!-- Sidebar preview -->
        <aside class="bmc-level-edit-aside">
          <div class="bmc-card">
            <h4 class="bmc-sc__title" style="margin-bottom:12px">Preview</h4>
            <div class="bmc-level-preview">
              <div class="bmc-level-preview__header">
                <span class="bmc-level-preview__name">{{ form.name || 'Level name' }}</span>
                <span class="bmc-level-preview__price">
                  ${{ displayPrice }}<span class="bmc-level-preview__interval">/{{ form.interval_type === 'year' ? 'yr' : 'mo' }}</span>
                </span>
              </div>
              <p v-if="form.description" class="bmc-level-preview__desc">{{ form.description }}</p>
              <ul v-if="form.rewards.length" class="bmc-level-preview__rewards">
                <li v-for="(r, i) in form.rewards.filter(x=>x)" :key="i">✓ {{ r }}</li>
              </ul>
              <div class="bmc-level-preview__cta">Join</div>
            </div>
          </div>

          <el-button type="primary" :loading="saving" style="width:100%;margin-top:12px" @click="save">
            {{ isNew ? 'Create Level' : 'Save Changes' }}
          </el-button>
        </aside>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, getCurrentInstance, onMounted } from 'vue';
import { ArrowLeft, X, Plus, ChevronDown } from 'lucide-vue-next';
import CoffeeLoader from '../UI/CoffeeLoader.vue';
import PageTitle from '../UI/PageTitle.vue';
import { useApi } from '../../composables/useApi';
import { useToast } from '../../composables/useToast';

// Access route/router via globalProperties — reliable in lazy-loaded chunks
const vm = getCurrentInstance()?.proxy;
const { adminGet, adminPost } = useApi();
const toast  = useToast();

const loading    = ref(true);
const saving     = ref(false);
const showAccess = ref(false);
const postTypes  = ref([]);
const categories = ref([]);

const isNew = computed(() => !vm?.$route?.params?.id);

const form = ref({
  id:            null,
  name:          '',
  description:   '',
  price:         500,    // cents
  interval_type: 'month',
  status:        'active',
  rewards:       [''],
  access_rules:  {
    post_types:    [],
    categories:    [],
    preview_words: 50,
    access_level:  'full',
  },
});

// Human-readable price (dollars)
const displayPrice = computed({
  get: () => parseFloat((form.value.price / 100).toFixed(2)),
  set: (val) => { form.value.price = Math.round(parseFloat(val || 0) * 100); },
});

function addReward() {
  form.value.rewards.push('');
}

function removeReward(i) {
  form.value.rewards.splice(i, 1);
}

async function loadPostTypes() {
  const res = await adminGet('get_post_types_for_membership');
  postTypes.value = res?.data?.post_types || [];
}

async function loadCategories() {
  const res = await adminGet('get_categories_for_membership');
  categories.value = res?.data?.categories || [];
}

async function loadLevel() {
  if (isNew.value) return;
  const res = await adminGet('get_membership_levels');
  const found = (res?.data?.levels || []).find(l => l.id == vm?.$route?.params?.id);
  if (!found) {
    vm?.$router?.push({ name: 'Memberships', query: { tab: 'levels' } });
    return;
  }
  form.value = {
    id:            found.id,
    name:          found.name,
    description:   found.description || '',
    price:         found.price,
    interval_type: found.interval_type || 'month',
    status:        found.status,
    rewards:       Array.isArray(found.rewards) && found.rewards.length ? found.rewards : [''],
    access_rules:  {
      post_types:    found.access_rules?.post_types || [],
      categories:    found.access_rules?.categories || [],
      preview_words: found.access_rules?.preview_words || 50,
      access_level:  found.access_rules?.access_level || 'full',
    },
  };
}

async function save() {
  if (!form.value.name.trim()) {
    toast.error('Level name is required.');
    return;
  }
  saving.value = true;
  const payload = {
    ...form.value,
    rewards: form.value.rewards.filter(r => r.trim()),
  };
  const res = await adminPost('save_membership_level', payload);
  saving.value = false;
  if (res?.success) {
    toast.success(res.data?.message || 'Level saved.');
    vm?.$router?.push({ name: 'Memberships', query: { tab: 'levels' } });
  } else {
    toast.error(res?.data?.message || 'Failed to save.');
  }
}

onMounted(async () => {
  await Promise.all([loadPostTypes(), loadCategories(), loadLevel()]);
  loading.value = false;
});
</script>

<style scoped>
.bmc-back-btn { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; color: var(--text-secondary); font-size: 13px; cursor: pointer; padding: 0; margin-bottom: 16px; }
.bmc-back-btn:hover { color: var(--text-primary); }
.bmc-level-edit-layout { display: grid; grid-template-columns: 1fr 280px; gap: 20px; align-items: start; }
@media (max-width: 900px) { .bmc-level-edit-layout { grid-template-columns: 1fr; } }
.bmc-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.bmc-required { color: #ef4444; }

.bmc-rewards-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
.bmc-reward-row { display: flex; align-items: center; gap: 8px; }
.bmc-reward-remove { background: none; border: 1px solid var(--border-secondary); border-radius: 6px; padding: 5px 8px; cursor: pointer; color: var(--text-tertiary); }
.bmc-reward-remove:hover { color: #ef4444; border-color: #ef4444; }
.bmc-add-reward-btn { display: inline-flex; align-items: center; gap: 6px; background: none; border: 1px dashed var(--border-secondary); border-radius: 8px; padding: 6px 14px; color: var(--text-secondary); cursor: pointer; font-size: 13px; }
.bmc-add-reward-btn:hover { color: var(--color-primary-600); border-color: var(--color-primary-400); }

.bmc-collapsible-hd { display: flex; align-items: center; justify-content: space-between; width: 100%; background: none; border: none; padding: 0; cursor: pointer; text-align: left; }
.bmc-chevron { transition: transform .2s; color: var(--text-tertiary); }
.bmc-chevron--open { transform: rotate(180deg); }
.bmc-checkbox-group { display: flex; flex-wrap: wrap; gap: 12px; }

.bmc-level-preview { border: 1px solid var(--border-secondary); border-radius: 10px; padding: 14px; }
.bmc-level-preview__header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
.bmc-level-preview__name { font-weight: 700; font-size: 14px; color: var(--text-primary); }
.bmc-level-preview__price { font-size: 16px; font-weight: 800; color: var(--text-primary); }
.bmc-level-preview__interval { font-size: 12px; font-weight: 400; color: var(--text-tertiary); }
.bmc-level-preview__desc { font-size: 12px; color: var(--text-secondary); margin: 0 0 8px; }
.bmc-level-preview__rewards { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--text-secondary); }
.bmc-level-preview__cta { background: var(--color-primary-600); color: #fff; border-radius: 6px; padding: 6px 14px; text-align: center; font-size: 13px; font-weight: 600; }

/* ─── Shared layout classes ──────────────── */
.bmc-card { background: var(--bg-primary); border: 1px solid var(--border-secondary); border-radius: 12px; padding: 16px 20px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.bmc-sc__title { font-size: 15px; font-weight: 700; color: var(--text-primary); margin: 0; line-height: 1.3; }
.bmc-sc__sub { font-size: 11.5px; color: var(--text-tertiary); margin: 2px 0 0; line-height: 1.4; }

.bmc-sr { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0; min-height: 48px; border-bottom: 1px solid var(--border-secondary); }
.bmc-sr:last-child { border-bottom: none; }
.bmc-sr--field { flex-direction: column; align-items: stretch; justify-content: flex-start; min-height: auto; padding: 14px 0; }

.bmc-toggle-row__text { flex: 1; min-width: 0; }
.bmc-toggle-row__label { font-size: 13.5px; font-weight: 500; color: var(--text-primary); margin: 0; }
.bmc-toggle-row__desc { font-size: 11.5px; color: var(--text-secondary); margin: 2px 0 0; }

.bmc-label { display: block; font-size: 12.5px; font-weight: 500; color: var(--text-secondary); margin-bottom: 5px; }
.bmc-hint { font-size: 12px; color: var(--text-tertiary); margin: 5px 0 0; }
</style>
