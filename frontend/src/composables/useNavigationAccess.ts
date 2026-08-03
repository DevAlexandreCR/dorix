import { computed } from 'vue';
import type { Ref } from 'vue';
import type { TenantMembership } from '../app/providers/session';

export function useNavigationAccess(selectedMembership: Ref<TenantMembership | null>) {
  const canViewConversations = computed(
    () => selectedMembership.value?.permissions.includes('conversations.view') ?? false,
  );
  const canReplyToConversations = computed(
    () => selectedMembership.value?.permissions.includes('conversations.reply') ?? false,
  );
  const canManageHandoffs = computed(
    () => selectedMembership.value?.permissions.includes('handoffs.manage') ?? false,
  );
  const canManageTenant = computed(
    () => selectedMembership.value?.permissions.includes('tenant.manage') ?? false,
  );
  const canManageTenantUsers = computed(
    () => selectedMembership.value?.permissions.includes('tenant_users.manage') ?? false,
  );
  const canManageAgentConfig = computed(
    () => selectedMembership.value?.permissions.includes('agent_configs.manage') ?? false,
  );
  const canViewCredentialMetadata = computed(
    () => selectedMembership.value?.permissions.includes('credentials.view_metadata') ?? false,
  );
  const canManagePlatform = computed(
    () => selectedMembership.value?.permissions.includes('platform.manage') ?? false,
  );
  const canAccessSandbox = computed(() => canManageAgentConfig.value);
  const canAccessAdmin = computed(
    () =>
      canManageTenant.value ||
      canManageTenantUsers.value ||
      canManageAgentConfig.value ||
      canViewCredentialMetadata.value,
  );

  return {
    canAccessAdmin,
    canAccessSandbox,
    canManageAgentConfig,
    canManageHandoffs,
    canManagePlatform,
    canManageTenant,
    canManageTenantUsers,
    canReplyToConversations,
    canViewConversations,
    canViewCredentialMetadata,
  };
}
