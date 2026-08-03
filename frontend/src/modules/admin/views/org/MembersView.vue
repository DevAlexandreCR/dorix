<script setup lang="ts">
import { Check, ChevronLeft, MoreVertical, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import DataTable from '../../../../components/ui/DataTable.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiDrawer from '../../../../components/ui/UiDrawer.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import UiModal from '../../../../components/ui/UiModal.vue';
import UiPopover from '../../../../components/ui/UiPopover.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import PanelHeader from '../../components/PanelHeader.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type { TenantUserRecord } from '../../types';

// design/05 "org/members": DataTable + a per-row `UiPopover` menu (change
// role / remove access with a `UiModal` confirmation) + an invite `UiDrawer`
// where each role is described in plain business language, not just named
// (design.md: roles come from the fixed `TenantRole` set — see
// `available_roles` in the overview payload, always exactly
// tenant_admin/operator/viewer per backend/app/Enums/TenantRole.php).
// "Cambiar rol" reuses that same described-role list inside the row menu
// itself (design/05's "Roles con descripción de una frase en el selector"
// isn't scoped only to the invite drawer) instead of a bare native <select>.

type InviteForm = {
  name: string;
  email: string;
  password: string;
  role: string;
};

type RowMenuState = {
  memberId: number;
  mode: 'actions' | 'roles';
};

const { t } = useI18n();
const { selectedMembership } = useTenantSelection();
const { canManageTenantUsers } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading, members } = useAdminResource();
const { loading: saving, error, success } = members;

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

const roleOptions = computed(() => {
  const overview = adminOverview.value;

  if (!overview) {
    return [];
  }

  return overview.available_roles.map((role) => ({
    value: role,
    label: translateRole(role),
    description: t(`admin.org.members.roleDescriptions.${role}`),
  }));
});

// --- row menu (change role / remove access) ---------------------------------

const rowMenu = ref<RowMenuState | null>(null);

function isRowMenuOpen(memberId: number): boolean {
  return rowMenu.value?.memberId === memberId;
}

function toggleRowMenu(memberId: number): void {
  rowMenu.value = isRowMenuOpen(memberId) ? null : { memberId, mode: 'actions' };
}

function onRowMenuOpenChange(memberId: number, open: boolean): void {
  if (!open && rowMenu.value?.memberId === memberId) {
    rowMenu.value = null;
  }
}

function showRoleOptions(): void {
  if (rowMenu.value) {
    rowMenu.value = { ...rowMenu.value, mode: 'roles' };
  }
}

function backToActions(): void {
  if (rowMenu.value) {
    rowMenu.value = { ...rowMenu.value, mode: 'actions' };
  }
}

async function changeRole(member: TenantUserRecord, role: string): Promise<void> {
  rowMenu.value = null;

  if (member.role === role) {
    return;
  }

  await members.update(member.id, { role }, { successMessage: t('admin.success.roleUpdated') });
}

// --- remove access confirmation ----------------------------------------------

const removeConfirmOpen = ref(false);
const removeCandidate = ref<TenantUserRecord | null>(null);

function requestRemove(member: TenantUserRecord): void {
  rowMenu.value = null;
  removeCandidate.value = member;
  removeConfirmOpen.value = true;
}

function cancelRemove(): void {
  removeConfirmOpen.value = false;
}

async function confirmRemove(): Promise<void> {
  const member = removeCandidate.value;

  if (!member) {
    removeConfirmOpen.value = false;
    return;
  }

  const removed = await members.remove(member.id, { successMessage: t('admin.success.tenantUserRemoved') });

  removeConfirmOpen.value = false;

  if (removed) {
    removeCandidate.value = null;
  }
}

// --- invite drawer -------------------------------------------------------------

const inviteOpen = ref(false);
const inviteForm = ref<InviteForm>(defaultInviteForm());

function defaultInviteForm(): InviteForm {
  return { name: '', email: '', password: '', role: 'operator' };
}

const canSubmitInvite = computed(
  () => inviteForm.value.email.trim() !== '' && inviteForm.value.role.trim() !== '',
);

function openInviteDrawer(): void {
  inviteForm.value = defaultInviteForm();
  inviteOpen.value = true;
}

function closeInviteDrawer(): void {
  inviteOpen.value = false;
}

function onInviteOpenChange(value: boolean): void {
  if (!value) {
    closeInviteDrawer();
  }
}

async function submitInvite(): Promise<void> {
  const created = await members.create(
    {
      name: inviteForm.value.name.trim(),
      email: inviteForm.value.email.trim(),
      password: inviteForm.value.password.trim() || undefined,
      role: inviteForm.value.role,
    },
    { successMessage: t('admin.success.tenantUserAdded') },
  );

  if (created) {
    closeInviteDrawer();
  }
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.org"
      panel="admin.org.members.title"
      description="admin.org.members.description"
    >
      <template #actions>
        <UiButton variant="primary" :disabled="!canManageTenantUsers" @click="openInviteDrawer">
          <template #icon>
            <Plus class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('admin.org.members.inviteAction') }}
        </UiButton>
      </template>
    </PanelHeader>

    <div v-if="loading && !adminOverview">
      <SurfaceCard>
        <LoadingState :label="t('admin.loading')" />
      </SurfaceCard>
    </div>

    <template v-else-if="adminOverview">
      <div class="grid gap-3">
        <InlineAlert v-if="error" :message="error" tone="danger" />
        <InlineAlert v-if="success" :message="success" tone="success" />
      </div>

      <DataTable
        data-settings-key="org.members.panel"
        :columns="[
          { key: 'name', label: t('admin.org.members.columns.name') },
          { key: 'email', label: t('admin.org.members.columns.email') },
          { key: 'role', label: t('admin.org.members.columns.role') },
          { key: 'actions', label: t('admin.org.members.columns.actions') },
        ]"
      >
        <template #body>
          <tr v-if="adminOverview.tenant_users.length === 0">
            <td colspan="4" class="data-table-empty">{{ t('admin.org.members.empty') }}</td>
          </tr>
          <tr v-for="member in adminOverview.tenant_users" :key="member.id">
            <td>{{ member.user?.name || t('admin.org.members.noProfile') }}</td>
            <td>{{ member.user?.email }}</td>
            <td>
              <StatusBadge :label="translateRole(member.role)" tone="neutral" />
            </td>
            <td class="member-actions-cell">
              <UiPopover
                :open="isRowMenuOpen(member.id)"
                placement="bottom-end"
                panel-class="w-[260px]"
                role="menu"
                :aria-label="t('admin.org.members.rowMenu.ariaLabel')"
                @update:open="onRowMenuOpenChange(member.id, $event)"
              >
                <template #trigger>
                  <button
                    type="button"
                    class="row-menu-trigger"
                    :aria-expanded="isRowMenuOpen(member.id)"
                    aria-haspopup="true"
                    :disabled="!canManageTenantUsers"
                    @click="toggleRowMenu(member.id)"
                  >
                    <MoreVertical class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                    <span class="sr-only">
                      {{ t('admin.org.members.rowMenu.trigger', { name: member.user?.name || member.user?.email || '' }) }}
                    </span>
                  </button>
                </template>

                <div v-if="rowMenu?.mode === 'roles'" class="role-menu">
                  <button type="button" class="row-menu-back" @click="backToActions">
                    <ChevronLeft class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                    {{ t('common.back') }}
                  </button>
                  <button
                    v-for="option in roleOptions"
                    :key="option.value"
                    type="button"
                    class="role-menu-option"
                    role="menuitemradio"
                    :aria-checked="option.value === member.role"
                    :disabled="saving"
                    @click="changeRole(member, option.value)"
                  >
                    <span class="role-menu-option-text">
                      <span class="role-menu-option-name">{{ option.label }}</span>
                      <span class="role-menu-option-description">{{ option.description }}</span>
                    </span>
                    <Check v-if="option.value === member.role" class="h-4 w-4 shrink-0" aria-hidden="true" />
                  </button>
                </div>
                <div v-else class="row-menu-actions">
                  <button type="button" class="row-menu-item" role="menuitem" @click="showRoleOptions">
                    {{ t('admin.org.members.rowMenu.changeRole') }}
                  </button>
                  <button
                    type="button"
                    class="row-menu-item row-menu-item--danger"
                    role="menuitem"
                    @click="requestRemove(member)"
                  >
                    {{ t('admin.org.members.rowMenu.removeAccess') }}
                  </button>
                </div>
              </UiPopover>
            </td>
          </tr>
        </template>
      </DataTable>
    </template>

    <!-- Invite drawer -->
    <UiDrawer
      :open="inviteOpen"
      :title="t('admin.org.members.inviteDrawer.title')"
      :close-label="t('common.close')"
      @update:open="onInviteOpenChange"
    >
      <FormField :label="t('admin.org.members.inviteDrawer.nameLabel')">
        <UiInput v-model="inviteForm.name" type="text" :disabled="saving" />
      </FormField>
      <FormField :label="t('admin.org.members.inviteDrawer.emailLabel')">
        <UiInput v-model="inviteForm.email" type="email" required :disabled="saving" />
      </FormField>
      <FormField
        :label="t('admin.org.members.inviteDrawer.passwordLabel')"
        :hint="t('admin.org.members.inviteDrawer.passwordHint')"
      >
        <UiInput v-model="inviteForm.password" type="password" :disabled="saving" />
      </FormField>

      <fieldset class="invite-role-fieldset">
        <legend class="text-small font-semibold">{{ t('admin.org.members.inviteDrawer.roleLabel') }}</legend>
        <div class="invite-role-list">
          <label
            v-for="option in roleOptions"
            :key="option.value"
            class="invite-role-option"
            :class="{ 'invite-role-option--active': inviteForm.role === option.value }"
          >
            <input
              v-model="inviteForm.role"
              type="radio"
              name="invite-role"
              class="sr-only"
              :value="option.value"
              :disabled="saving"
            />
            <span class="invite-role-option-name">{{ option.label }}</span>
            <span class="invite-role-option-description">{{ option.description }}</span>
          </label>
        </div>
      </fieldset>

      <template #footer>
        <UiButton variant="secondary" :disabled="saving" @click="closeInviteDrawer">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton variant="primary" :loading="saving" :disabled="!canSubmitInvite" @click="submitInvite">
          {{ t('admin.org.members.inviteDrawer.submitAction') }}
        </UiButton>
      </template>
    </UiDrawer>

    <!-- Remove access confirmation -->
    <UiModal
      :open="removeConfirmOpen"
      :title="t('admin.org.members.removeConfirm.title')"
      :message="
        removeCandidate
          ? t('admin.org.members.removeConfirm.message', {
              name: removeCandidate.user?.name || removeCandidate.user?.email || '',
            })
          : ''
      "
      :confirm-label="t('admin.org.members.removeConfirm.action')"
      :cancel-label="t('common.cancel')"
      danger
      :confirm-loading="saving"
      @confirm="confirmRemove"
      @cancel="cancelRemove"
    />
  </div>
</template>

<style scoped>
.member-actions-cell {
  text-align: right;
}

.row-menu-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: var(--radius-md);
  border: 1px solid transparent;
  background: transparent;
  color: var(--text-mute);
  cursor: pointer;
  transition: background 120ms ease, color 120ms ease;
}

.row-menu-trigger:hover:not(:disabled) {
  background: var(--muted);
  color: var(--text);
}

.row-menu-trigger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.row-menu-actions,
.role-menu {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.row-menu-item {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--radius-md);
  border: none;
  background: transparent;
  color: var(--text);
  font-size: 0.8125rem;
  text-align: left;
  cursor: pointer;
  transition: background 120ms ease;
}

.row-menu-item:hover {
  background: var(--muted);
}

.row-menu-item--danger {
  color: var(--danger);
}

.row-menu-back {
  display: flex;
  align-items: center;
  gap: 6px;
  width: 100%;
  padding: 8px 10px;
  margin-bottom: 4px;
  border: none;
  border-bottom: 1px solid var(--border);
  border-radius: 0;
  background: transparent;
  color: var(--text-mute);
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
}

.row-menu-back:hover {
  color: var(--text);
}

.role-menu-option {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--radius-md);
  border: none;
  background: transparent;
  color: var(--text);
  text-align: left;
  cursor: pointer;
  transition: background 120ms ease;
}

.role-menu-option:hover:not(:disabled) {
  background: var(--muted);
}

.role-menu-option:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.role-menu-option-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.role-menu-option-name {
  font-size: 0.8125rem;
  font-weight: 600;
}

.role-menu-option-description {
  font-size: 0.75rem;
  color: var(--text-mute);
}

.invite-role-fieldset {
  border: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}

.invite-role-list {
  display: grid;
  gap: 8px;
}

.invite-role-option {
  display: grid;
  gap: 2px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: border-color 120ms ease, background 120ms ease;
}

.invite-role-option:hover {
  background: var(--muted);
}

.invite-role-option--active {
  border-color: var(--accent);
  background: var(--accent-subtle);
}

.invite-role-option-name {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text);
}

.invite-role-option-description {
  font-size: 0.75rem;
  color: var(--text-mute);
}
</style>
