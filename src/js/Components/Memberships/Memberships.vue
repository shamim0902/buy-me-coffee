<template>
  <div class="relative min-h-[200px]">
    <CoffeeLoader :loading="loading" />

    <template v-if="!loading">
      <PageTitle title="Memberships" subtitle="Create membership levels, manage members, and configure content monetization" />

      <!-- Tab nav -->
      <div class="bmc-tab-nav">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          class="bmc-tab-nav__btn"
          :class="{ 'bmc-tab-nav__btn--active': active === tab.key }"
          @click="setTab(tab.key)"
        >{{ tab.label }}</button>
      </div>

      <!-- ── Members Tab ── -->
      <section v-show="active === 'members'">
        <div class="bmc-card">
          <div class="bmc-card-header">
            <h3 class="bmc-sc__title">Active Members</h3>
            <el-input
              v-model="memberSearch"
              placeholder="Search by name or email…"
              clearable
              style="max-width:260px"
              @input="fetchMembers"
            />
          </div>

          <el-table :data="members" style="width:100%" v-loading="membersLoading" empty-text="No members yet">
            <el-table-column prop="supporters_name" label="Name" min-width="130">
              <template #default="{ row }">{{ row.supporters_name || 'Anonymous' }}</template>
            </el-table-column>
            <el-table-column prop="supporters_email" label="Email" min-width="170" />
            <el-table-column prop="level_name" label="Level" min-width="120">
              <template #default="{ row }">
                <span class="bmc-level-badge">{{ row.level_name || '—' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="Renews" min-width="120">
              <template #default="{ row }">
                <span v-if="row.status === 'active' && row.current_period_end">{{ formatDate(row.current_period_end) }}</span>
                <span v-else-if="row.status === 'cancelled'" class="bmc-text-muted">Cancelled</span>
                <span v-else class="bmc-text-muted">—</span>
              </template>
            </el-table-column>
            <el-table-column prop="interval_type" label="Billing" width="90">
              <template #default="{ row }">
                <span class="bmc-text-muted">{{ row.interval_type === 'year' ? 'Yearly' : 'Monthly' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="Status" width="100">
              <template #default="{ row }">
                <span class="bmc-status-badge" :class="'bmc-status-badge--' + row.status">{{ row.status }}</span>
              </template>
            </el-table-column>
            <el-table-column width="100" align="center">
              <template #default="{ row }">
                <el-dropdown trigger="click" @command="(cmd) => handleMemberAction(cmd, row)">
                  <button class="bmc-actions-btn" type="button">
                    <MoreHorizontal :size="16" />
                  </button>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item command="view_subscription">View subscription</el-dropdown-item>
                      <el-dropdown-item v-if="row.status === 'active'" command="cancel" divided style="color:#dc2626">Cancel membership</el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </template>
            </el-table-column>
          </el-table>

          <div class="bmc-pagination" v-if="membersTotal > 20">
            <el-pagination
              background
              layout="prev, pager, next"
              :total="membersTotal"
              :page-size="20"
              :current-page="membersPage + 1"
              @current-change="onMembersPageChange"
            />
          </div>
        </div>
      </section>

      <!-- ── Levels Tab ── -->
      <section v-show="active === 'levels'">
        <div class="bmc-membership-levels-header">
          <p class="bmc-membership-levels-hint">Create tiers your supporters can subscribe to.</p>
          <router-link to="/memberships/level/new" class="bmc-btn bmc-btn--primary">
            + Add another level
          </router-link>
        </div>

        <div class="bmc-levels-grid">
          <div
            v-for="level in levels"
            :key="level.id"
            class="bmc-level-card"
          >
            <div class="bmc-level-card__body">
              <h4 class="bmc-level-card__name">{{ level.name }}</h4>
              <p class="bmc-level-card__price">
                <span class="bmc-level-card__price-icon">$</span>
                {{ formatPrice(level.price) }} per {{ level.interval_type === 'year' ? 'year' : 'month' }}
              </p>

              <div v-if="level.description" class="bmc-level-card__desc">{{ level.description }}</div>

              <template v-if="level.rewards && level.rewards.length">
                <p class="bmc-level-card__rewards-label">REWARDS</p>
                <ul class="bmc-level-card__rewards">
                  <li v-for="(reward, i) in level.rewards" :key="i" class="bmc-level-card__reward">
                    <component :is="rewardIcon(i)" :size="15" class="bmc-level-card__reward-icon" />
                    {{ reward }}
                  </li>
                </ul>
              </template>
            </div>

            <div class="bmc-level-card__footer">
              <router-link :to="'/memberships/level/' + level.id" class="bmc-btn bmc-btn--outline bmc-btn--full">
                Edit
              </router-link>
              <a :href="previewUrl + '&bmc_level_id=' + level.id" target="_blank" class="bmc-level-card__action" title="Preview checkout">
                <ExternalLink :size="14" />
              </a>
              <button class="bmc-level-card__delete" @click="confirmDelete(level)" title="Delete">
                <Trash2 :size="14" />
              </button>
            </div>
          </div>

          <div v-if="!levels.length" class="bmc-empty-state-inline">
            <p>No membership levels yet. Add your first level to start monetizing content.</p>
          </div>
        </div>
      </section>

      <!-- ── Recovery Tab ── -->
      <section v-show="active === 'recovery'">
        <div class="bmc-card">
          <div class="bmc-sr">
            <div class="bmc-toggle-row__text">
              <p class="bmc-toggle-row__label">
                What you miss modal
                <span class="bmc-badge bmc-badge--green">+ Reduces cancellations</span>
              </p>
              <p class="bmc-toggle-row__desc">Show members what they'll miss, before they cancel their membership.</p>
            </div>
            <el-switch v-model="membershipSettings.recovery_modal_enabled" />
          </div>

          <template v-if="membershipSettings.recovery_modal_enabled">
            <div class="bmc-sr bmc-sr--field">
              <label class="bmc-label">Title</label>
              <el-input v-model="membershipSettings.recovery_modal_title" placeholder="Don't lose your benefits" />
            </div>

            <div class="bmc-sr bmc-sr--field">
              <label class="bmc-label">Briefly describe what members will miss out on.</label>
              <el-input
                v-model="membershipSettings.recovery_modal_body"
                type="textarea"
                :rows="5"
                placeholder="• Access to premium content&#10;• Priority support&#10;• Exclusive community access"
              />
            </div>
          </template>
        </div>

        <div class="bmc-save-row">
          <el-button type="primary" :loading="saving" @click="saveSettings">Save Changes</el-button>
        </div>
      </section>

      <!-- ── Guide Tab ── -->
      <section v-show="active === 'guide'">
        <div class="bmc-card bmc-guide-hero">
          <div class="bmc-guide-hero__content">
            <h3 class="bmc-sc__title" style="margin-bottom:4px">Getting Started with Memberships</h3>
            <p class="bmc-sc__sub" style="margin-bottom:16px">Follow these steps to start monetizing your content with membership levels.</p>

            <div class="bmc-guide-steps">
              <div class="bmc-guide-step">
                <span class="bmc-guide-step__num">1</span>
                <div>
                  <p class="bmc-guide-step__title">Create membership levels</p>
                  <p class="bmc-guide-step__desc">Go to the <a href="javascript:void(0)" @click="setTab('levels')">Levels</a> tab and create one or more membership tiers. Set pricing, billing interval, and reward bullets for each level.</p>
                </div>
              </div>
              <div class="bmc-guide-step">
                <span class="bmc-guide-step__num">2</span>
                <div>
                  <p class="bmc-guide-step__title">Mark posts as paid</p>
                  <p class="bmc-guide-step__desc">When editing a post or page, look for the <strong>Content Access (Buy Me Coffee)</strong> panel in the editor sidebar. Set access to <em>Paid</em> and choose which membership levels can view the full content.</p>
                </div>
              </div>
              <div class="bmc-guide-step">
                <span class="bmc-guide-step__num">3</span>
                <div>
                  <p class="bmc-guide-step__title">Non-members see a teaser + paywall</p>
                  <p class="bmc-guide-step__desc">Visitors without the required membership see a word-limited preview followed by a paywall CTA showing your levels, pricing, and reward bullets. Customize the CTA text and preview word count in <a href="javascript:void(0)" @click="setTab('settings')">Settings</a>.</p>
                </div>
              </div>
              <div class="bmc-guide-step">
                <span class="bmc-guide-step__num">4</span>
                <div>
                  <p class="bmc-guide-step__title">Members subscribe &amp; get access</p>
                  <p class="bmc-guide-step__desc">When a visitor clicks "Join" on the paywall, they are taken to the checkout form pre-filled with the level's price and interval. After payment, they get full access to all content gated by their membership level.</p>
                </div>
              </div>
            </div>
          </div>
          <div class="bmc-guide-hero__image">
            <img :src="assetsUrl + 'images/members-only-banner.png'" alt="Membership content monetization" />
          </div>
        </div>

        <div class="bmc-card">
          <h3 class="bmc-sc__title" style="margin-bottom:4px">Content Access (Block Editor)</h3>
          <p class="bmc-sc__sub" style="margin-bottom:16px">How the per-post paywall works in the Gutenberg editor.</p>

          <div class="bmc-guide-info">
            <div class="bmc-guide-info__row">
              <strong>Panel location</strong>
              <span>Post sidebar &rarr; <em>Content Access (Buy Me Coffee)</em></span>
            </div>
            <div class="bmc-guide-info__row">
              <strong>Access Level</strong>
              <span><em>Free</em> (visible to everyone) or <em>Paid</em> (members only)</span>
            </div>
            <div class="bmc-guide-info__row">
              <strong>Allowed Levels</strong>
              <span>When set to Paid, choose which membership levels can view the full post</span>
            </div>
            <div class="bmc-guide-info__row">
              <strong>Preview words</strong>
              <span>Override the default preview word count for this post (0 = use global default from <a href="javascript:void(0)" @click="setTab('settings')">Settings</a>)</span>
            </div>
          </div>
        </div>

        <div class="bmc-card">
          <h3 class="bmc-sc__title" style="margin-bottom:4px">Membership Shortcode</h3>
          <p class="bmc-sc__sub" style="margin-bottom:16px">Use this shortcode to let members manage their subscriptions.</p>

          <div class="bmc-shortcode-list">
            <div class="bmc-shortcode-item">
              <div class="bmc-shortcode-item__hd">
                <code class="bmc-shortcode-code">[buymecoffee_account]</code>
              </div>
              <p class="bmc-shortcode-item__desc">Renders the subscriber account page where logged-in members can view their active subscriptions and transaction history. Create a dedicated "My Account" page with this shortcode so your members have a place to manage their membership.</p>
            </div>
          </div>
        </div>

        <div class="bmc-card">
          <h3 class="bmc-sc__title" style="margin-bottom:4px">Recovery Modal</h3>
          <p class="bmc-sc__sub" style="margin-bottom:16px">Reduce cancellations by reminding members what they'll lose.</p>

          <div class="bmc-guide-steps">
            <div class="bmc-guide-step">
              <span class="bmc-guide-step__num">!</span>
              <div>
                <p class="bmc-guide-step__title">How it works</p>
                <p class="bmc-guide-step__desc">When a member tries to cancel their subscription, a modal appears showing the title and description you configure in the <a href="javascript:void(0)" @click="setTab('recovery')">Recovery</a> tab. This gives them a chance to reconsider before finalizing the cancellation.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="bmc-card">
          <h3 class="bmc-sc__title" style="margin-bottom:4px">Helpful Links</h3>
          <div class="bmc-guide-links">
            <a href="https://wpminers.com/buymecoffee/docs/" target="_blank" rel="noopener" class="bmc-guide-link">
              <FileText :size="15" />
              Full documentation
            </a>
          </div>
        </div>
      </section>

      <!-- ── Settings Tab ── -->
      <section v-show="active === 'settings'">
        <div class="bmc-card">
          <!-- Giveaway -->
          <div class="bmc-sc__hd" style="margin-bottom:16px">
            <div>
              <h3 class="bmc-sc__title">Membership giveaway</h3>
              <p class="bmc-sc__sub">Give free access to your family and close friends.</p>
            </div>
          </div>
          <div class="bmc-invite-row">
            <el-input v-model="inviteEmail" placeholder="Enter email address" style="flex:1" />
            <el-button type="primary" :loading="inviting" @click="sendInvite">Send Invite</el-button>
          </div>
        </div>

        <div class="bmc-card">
          <div class="bmc-sr">
            <div class="bmc-toggle-row__text">
              <p class="bmc-toggle-row__label">Accept annual memberships</p>
              <p class="bmc-toggle-row__desc">New members can choose to pay for 12 months upfront.</p>
            </div>
            <el-switch v-model="membershipSettings.accept_annual" />
          </div>

          <div class="bmc-sr">
            <div class="bmc-toggle-row__text">
              <p class="bmc-toggle-row__label">Display member count</p>
              <p class="bmc-toggle-row__desc">Showing your member count might encourage more people to join.</p>
            </div>
            <el-switch v-model="membershipSettings.display_member_count" />
          </div>

          <div class="bmc-sr">
            <div class="bmc-toggle-row__text">
              <p class="bmc-toggle-row__label">Display monthly earnings</p>
              <p class="bmc-toggle-row__desc">Displaying earnings allows you to be transparent with your supporters.</p>
            </div>
            <el-switch v-model="membershipSettings.display_earnings" />
          </div>

          <div class="bmc-sr bmc-sr--field">
            <label class="bmc-label">Paywall CTA heading</label>
            <el-input v-model="membershipSettings.cta_heading" placeholder="This content is for members only" />
          </div>

          <div class="bmc-sr bmc-sr--field">
            <label class="bmc-label">Paywall CTA subtext</label>
            <el-input v-model="membershipSettings.cta_subtext" placeholder="Join to get full access…" />
          </div>

          <div class="bmc-sr bmc-sr--field">
            <label class="bmc-label">Default preview word count</label>
            <el-input-number v-model="membershipSettings.default_preview_words" :min="10" :max="500" />
            <p class="bmc-hint">Words shown to non-members before the paywall CTA.</p>
          </div>

          <div class="bmc-sr bmc-sr--field">
            <label class="bmc-label">Checkout redirect URL</label>
            <el-input v-model="membershipSettings.redirect_url" placeholder="Leave blank to use the default donation page" />
          </div>

          <div class="bmc-sr">
            <div class="bmc-toggle-row__text">
              <p class="bmc-toggle-row__label">Pause membership</p>
              <p class="bmc-toggle-row__desc">Pausing allows you to take a break. Billing cycles continue but new members cannot join.</p>
            </div>
            <el-switch v-model="membershipSettings.membership_active" :active-value="false" :inactive-value="true" />
          </div>
        </div>

        <div class="bmc-save-row">
          <el-button type="primary" :loading="saving" @click="saveSettings">Save Changes</el-button>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, getCurrentInstance, onMounted } from 'vue';
import { Trash2, Calendar, BookOpen, Video, FileText, MoreHorizontal, ExternalLink } from 'lucide-vue-next';
import { ElMessageBox } from 'element-plus';
import CoffeeLoader from '../UI/CoffeeLoader.vue';
import PageTitle from '../UI/PageTitle.vue';
import { useApi } from '../../composables/useApi';
import { useToast } from '../../composables/useToast';

// Access route/router via globalProperties — reliable in lazy-loaded chunks
const vm = getCurrentInstance()?.proxy;
const { adminGet, adminPost } = useApi();
const toast  = useToast();

const loading         = ref(true);
const saving          = ref(false);
const inviting        = ref(false);
const membersLoading  = ref(false);
const levels          = ref([]);
const members         = ref([]);
const membersTotal    = ref(0);
const membersPage     = ref(0);
const memberSearch    = ref('');
const inviteEmail     = ref('');

const membershipSettings = ref({
  default_preview_words:  50,
  cta_heading:            '',
  cta_subtext:            '',
  redirect_url:           '',
  accept_annual:          true,
  display_member_count:   false,
  display_earnings:       false,
  membership_active:      true,
  recovery_modal_enabled: true,
  recovery_modal_title:   "Don't lose your benefits",
  recovery_modal_body:    '',
});

const tabs = [
  { key: 'members',  label: 'Members' },
  { key: 'levels',   label: 'Levels' },
  { key: 'recovery', label: 'Recovery' },
  { key: 'settings', label: 'Settings' },
  { key: 'guide',    label: 'Guide' },
];


const previewUrl = window.BuyMeCoffeeAdmin?.preview_url || '#';
const assetsUrl = window.BuyMeCoffeeAdmin?.assets_url || '';
const active = computed(() => vm?.$route?.query?.tab || 'members');

function setTab(key) {
  vm?.$router?.push({ name: 'Memberships', query: { tab: key } });
}

const rewardIcons = [Calendar, BookOpen, Video, Calendar, BookOpen];
function rewardIcon(i) {
  return rewardIcons[i % rewardIcons.length];
}

function formatDate(ts) {
  if (!ts) return '—';
  return new Date(ts).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatPrice(cents) {
  return (cents / 100).toFixed(2).replace(/\.00$/, '');
}

async function fetchLevels() {
  const res = await adminGet('get_membership_levels');
  levels.value = res?.data?.levels || [];
}

async function fetchSettings() {
  const res = await adminGet('get_membership_settings');
  if (res?.data?.settings) {
    membershipSettings.value = { ...membershipSettings.value, ...res.data.settings };
  }
}

async function fetchMembers() {
  membersLoading.value = true;
  const res = await adminGet('get_membership_members', { page: membersPage.value, search: memberSearch.value });
  members.value     = res?.data?.members || [];
  membersTotal.value = res?.data?.total  || 0;
  membersLoading.value = false;
}

function onMembersPageChange(page) {
  membersPage.value = page - 1;
  fetchMembers();
}

async function saveSettings() {
  saving.value = true;
  const res = await adminPost('save_membership_settings', membershipSettings.value);
  saving.value = false;
  if (res?.success) {
    toast.success('Settings saved.');
  } else {
    toast.error(res?.data?.message || 'Failed to save settings.');
  }
}

async function sendInvite() {
  if (!inviteEmail.value) return;
  inviting.value = true;
  const res = await adminPost('send_membership_invite', { email: inviteEmail.value });
  inviting.value = false;
  if (res?.success) {
    toast.success('Invite sent!');
    inviteEmail.value = '';
  } else {
    toast.error(res?.data?.message || 'Failed to send invite.');
  }
}

async function confirmDelete(level) {
  try {
    await ElMessageBox.confirm(
      `Delete the "${level.name}" level? This cannot be undone.`,
      'Delete Level',
      { confirmButtonText: 'Delete', cancelButtonText: 'Cancel', type: 'warning' }
    );
    const res = await adminPost('delete_membership_level', { id: level.id });
    if (res?.success) {
      toast.success('Level deleted.');
      await fetchLevels();
    } else {
      toast.error(res?.data?.message || 'Cannot delete this level.');
    }
  } catch {
    // user cancelled
  }
}

async function handleMemberAction(command, row) {
  if (command === 'view_subscription') {
    vm?.$router?.push({ name: 'SubscriptionDetail', params: { id: row.subscription_id } });
  } else if (command === 'cancel') {
    try {
      await ElMessageBox.confirm(
        `Cancel the membership for "${row.supporters_name || 'this member'}"? This will revoke their access to paid content.`,
        'Cancel Membership',
        { confirmButtonText: 'Cancel Membership', cancelButtonText: 'Keep', type: 'warning' }
      );
      const res = await adminPost('cancel_subscription', { id: row.subscription_id });
      if (res?.success) {
        toast.success('Membership cancelled.');
        await fetchMembers();
      } else {
        toast.error(res?.data?.message || 'Failed to cancel membership.');
      }
    } catch {
      // user cancelled dialog
    }
  }
}

onMounted(async () => {
  await Promise.all([fetchLevels(), fetchSettings(), fetchMembers()]);
  loading.value = false;
});
</script>

<style scoped>
.bmc-tab-nav { display: flex; gap: 0; border-bottom: 1px solid var(--border-secondary); margin-bottom: 24px; }
.bmc-tab-nav__btn { padding: 10px 20px; border: none; background: none; font-size: 14px; font-weight: 500; color: var(--text-secondary); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.bmc-tab-nav__btn--active { color: var(--color-primary-600); border-bottom-color: var(--color-primary-600); }

.bmc-membership-levels-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.bmc-membership-levels-hint { color: var(--text-secondary); font-size: 14px; margin: 0; }

.bmc-levels-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }

.bmc-level-card { background: var(--bg-primary); border: 1px solid var(--border-secondary); border-radius: 12px; display: flex; flex-direction: column; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.bmc-level-card__body { flex: 1; padding: 20px 20px 12px; }
.bmc-level-card__name { font-size: 15px; font-weight: 700; margin: 0 0 4px; color: var(--text-primary); }
.bmc-level-card__price { font-size: 14px; color: var(--text-secondary); margin: 0 0 12px; display: flex; align-items: center; gap: 4px; }
.bmc-level-card__price-icon { font-size: 12px; color: var(--text-tertiary); }
.bmc-level-card__desc { font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; border-top: 1px solid var(--border-secondary); padding-top: 10px; }
.bmc-level-card__rewards-label { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-tertiary); margin: 8px 0 6px; }
.bmc-level-card__rewards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.bmc-level-card__reward { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-primary); }
.bmc-level-card__reward-icon { color: var(--color-primary-500); flex-shrink: 0; }

.bmc-level-card__footer { padding: 12px 20px 16px; display: flex; align-items: center; gap: 8px; border-top: 1px solid var(--border-secondary); }
.bmc-level-card__delete { margin-left: auto; }
.bmc-level-card__action { display: flex; align-items: center; justify-content: center; background: none; border: 1px solid var(--border-secondary); border-radius: 6px; padding: 6px 8px; cursor: pointer; color: var(--text-tertiary); text-decoration: none; transition: color .15s, border-color .15s; }
.bmc-level-card__action:hover { color: var(--color-primary-600); border-color: var(--color-primary-400); }
.bmc-level-card__delete { background: none; border: 1px solid var(--border-secondary); border-radius: 6px; padding: 6px 8px; cursor: pointer; color: var(--text-tertiary); transition: color .15s, border-color .15s; }
.bmc-level-card__delete:hover { color: #ef4444; border-color: #ef4444; }

.bmc-badge--green { background: #dcfce7; color: #16a34a; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; margin-left: 8px; }
.bmc-level-badge { background: var(--bg-tertiary); color: var(--text-secondary); font-size: 12px; padding: 2px 8px; border-radius: 20px; }

/* ─── Shared layout classes ──────────────── */
.bmc-card { background: var(--bg-primary); border: 1px solid var(--border-secondary); border-radius: 12px; padding: 16px 20px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.bmc-sc__hd { display: flex; align-items: center; gap: 12px; padding: 0 0 12px; border-bottom: 1px solid var(--border-secondary); }
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

.bmc-invite-row { display: flex; gap: 10px; margin-top: 12px; }
.bmc-save-row { margin-top: 16px; display: flex; justify-content: flex-end; }
.bmc-pagination { display: flex; justify-content: center; margin-top: 16px; }
.bmc-empty-state-inline { grid-column: 1/-1; padding: 40px; text-align: center; color: var(--text-secondary); background: var(--bg-primary); border: 1px dashed var(--border-secondary); border-radius: 12px; }
.bmc-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }

.bmc-status-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 20px; text-transform: capitalize; }
.bmc-status-badge--active { background: #dcfce7; color: #16a34a; }
.bmc-status-badge--cancelled, .bmc-status-badge--canceled { background: #fee2e2; color: #dc2626; }
.bmc-status-badge--incomplete { background: #e0e7ff; color: #4338ca; }
.bmc-status-badge--pending { background: #fef9c3; color: #a16207; }

.bmc-text-muted { color: var(--text-tertiary); font-size: 12.5px; }
.bmc-actions-btn { background: none; border: 1px solid var(--border-secondary); border-radius: 6px; padding: 4px 6px; cursor: pointer; color: var(--text-tertiary); display: flex; align-items: center; transition: color .15s, border-color .15s; }
.bmc-actions-btn:hover { color: var(--text-primary); border-color: var(--border-primary); }

/* ─── Guide tab ─────────────────────────── */
.bmc-guide-hero { display: flex; gap: 24px; align-items: start; }
.bmc-guide-hero__content { flex: 1; min-width: 0; }
.bmc-guide-hero__image { width: 580px; flex-shrink: 0; border-radius: 10px; overflow: hidden; }
.bmc-guide-hero__image img { display: block; width: 100%; height: auto; }
@media (max-width: 900px) {
  .bmc-guide-hero { flex-direction: column-reverse; }
  .bmc-guide-hero__image { width: 100%; }
}
.bmc-guide-steps { display: flex; flex-direction: column; gap: 0; }
.bmc-guide-step { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border-secondary); }
.bmc-guide-step:last-child { border-bottom: none; }
.bmc-guide-step__num { flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; background: var(--color-primary-600); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-top: 1px; }
.bmc-guide-step__title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); margin: 0 0 3px; }
.bmc-guide-step__desc { font-size: 12.5px; color: var(--text-secondary); margin: 0; line-height: 1.55; }
.bmc-guide-step__desc a { color: var(--color-primary-600); text-decoration: none; font-weight: 500; }
.bmc-guide-step__desc a:hover { text-decoration: underline; }

.bmc-shortcode-list { display: flex; flex-direction: column; gap: 0; }
.bmc-shortcode-item { padding: 14px 0; border-bottom: 1px solid var(--border-secondary); }
.bmc-shortcode-item:last-child { border-bottom: none; }
.bmc-shortcode-item__hd { margin-bottom: 6px; }
.bmc-shortcode-item__desc { font-size: 12.5px; color: var(--text-secondary); margin: 0; line-height: 1.55; }
.bmc-shortcode-item__desc code { font-size: 11px; background: var(--bg-tertiary, #f3f4f6); padding: 1px 6px; border-radius: 4px; font-family: var(--font-mono, monospace); }
.bmc-shortcode-code { display: inline-block; background: var(--bg-tertiary, #f3f4f6); color: var(--color-primary-600); font-size: 13px; font-weight: 600; padding: 4px 12px; border-radius: 6px; font-family: var(--font-mono, monospace); border: 1px solid var(--border-secondary); user-select: all; }

.bmc-guide-info { display: flex; flex-direction: column; gap: 0; }
.bmc-guide-info__row { display: flex; gap: 16px; padding: 10px 0; border-bottom: 1px solid var(--border-secondary); font-size: 13px; }
.bmc-guide-info__row:last-child { border-bottom: none; }
.bmc-guide-info__row strong { flex-shrink: 0; width: 140px; color: var(--text-primary); font-weight: 600; }
.bmc-guide-info__row span { color: var(--text-secondary); }

.bmc-guide-links { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.bmc-guide-link { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border: 1px solid var(--border-secondary); border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--text-primary); text-decoration: none; transition: border-color .15s, color .15s; }
.bmc-guide-link:hover { border-color: var(--color-primary-400); color: var(--color-primary-600); }
</style>
