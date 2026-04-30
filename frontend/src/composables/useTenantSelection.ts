import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSession } from './useSession';

function queryValue(value: unknown): string | null {
  if (typeof value === 'string') {
    return value;
  }

  if (Array.isArray(value) && typeof value[0] === 'string') {
    return value[0];
  }

  return null;
}

function parseTenantId(value: unknown): number | null {
  const raw = queryValue(value);

  if (!raw) {
    return null;
  }

  const parsed = Number(raw);

  return Number.isInteger(parsed) ? parsed : null;
}

export function useTenantSelection() {
  const route = useRoute();
  const router = useRouter();
  const { memberships } = useSession();

  const selectedTenantId = computed<number | null>({
    get: () => parseTenantId(route.query.tenant),
    set: (value) => {
      router.replace({
        query: {
          ...route.query,
          tenant: value ? String(value) : undefined,
        },
      });
    },
  });

  const selectedMembership = computed(() =>
    memberships.value.find((membership) => membership.tenant_id === selectedTenantId.value) ?? null,
  );

  watch(
    memberships,
    (availableMemberships) => {
      if (availableMemberships.length === 0) {
        if (selectedTenantId.value !== null) {
          selectedTenantId.value = null;
        }
        return;
      }

      const isValidSelection = availableMemberships.some(
        (membership) => membership.tenant_id === selectedTenantId.value,
      );

      if (!isValidSelection) {
        selectedTenantId.value = availableMemberships[0]?.tenant_id ?? null;
      }
    },
    {
      immediate: true,
    },
  );

  return {
    selectedMembership,
    selectedTenantId,
    setSelectedTenantId: (tenantId: number | null) => {
      selectedTenantId.value = tenantId;
    },
  };
}
