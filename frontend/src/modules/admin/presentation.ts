export const adminPanelKeys = [
  'tenant',
  'users',
  'lines',
  'agent',
  'sources',
  'credentials',
  'logs',
] as const;

export type AdminPanelKey = (typeof adminPanelKeys)[number];

const panelAliases: Record<string, AdminPanelKey> = {
  bindings: 'sources',
};

export function normalizeAdminPanel(panel: string | null | undefined): AdminPanelKey {
  const normalized = (panel ?? '').trim();

  if (normalized in panelAliases) {
    return panelAliases[normalized];
  }

  return adminPanelKeys.includes(normalized as AdminPanelKey)
    ? (normalized as AdminPanelKey)
    : 'tenant';
}

export function isLegacyAdminPanel(panel: string | null | undefined): boolean {
  return (panel ?? '').trim() === 'bindings';
}
