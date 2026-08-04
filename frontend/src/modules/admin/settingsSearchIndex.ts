// Static index for AdminNav's settings search (design.md decision 8, task
// 4.9): a hand-curated list of i18n keys (title/help of the settings that
// actually exist as visible cards/rows/panels across the 8 admin screens),
// not an automatic walk of the whole i18n tree — the full tree also holds
// activity-log sentences, column headers, confirm-dialog copy, etc. that
// would just be noise in a settings search.
//
// `panelPath` matches `ADMIN_ROUTE_REQUIRES` keys (router.ts) so visibility
// filtering never has to duplicate that table. `highlightKey` is the value
// views expose via a `data-settings-key` attribute on the DOM node that
// should scroll into view + flash when this entry is chosen — several
// entries in a screen without individual setting cards (DataTable-based
// screens) intentionally share the same `highlightKey` (the panel's main
// content block), since there is no finer-grained target to point at.
export interface SettingsSearchEntry {
  /** Unique key for this entry (list rendering + dedup), not shown to users. */
  id: string;
  panelPath: string;
  panelTitleKey: string;
  titleKey: string;
  helpKey?: string;
  highlightKey: string;
}

export const SETTINGS_SEARCH_INDEX: SettingsSearchEntry[] = [
  // org/info
  {
    id: 'org.info.card',
    panelPath: '/admin/org/info',
    panelTitleKey: 'admin.org.info.title',
    titleKey: 'admin.org.info.card.title',
    highlightKey: 'org.info.card',
  },
  {
    id: 'org.info.name',
    panelPath: '/admin/org/info',
    panelTitleKey: 'admin.org.info.title',
    titleKey: 'admin.org.info.nameLabel',
    highlightKey: 'org.info.card',
  },
  {
    id: 'org.info.identifier',
    panelPath: '/admin/org/info',
    panelTitleKey: 'admin.org.info.title',
    titleKey: 'admin.org.info.identifierLabel',
    helpKey: 'admin.org.info.identifierHint',
    highlightKey: 'org.info.card',
  },
  {
    id: 'org.info.dangerZone',
    panelPath: '/admin/org/info',
    panelTitleKey: 'admin.org.info.title',
    titleKey: 'admin.org.info.dangerZone.pauseAction',
    helpKey: 'admin.org.info.dangerZone.description',
    highlightKey: 'org.info.dangerZone',
  },

  // org/members
  {
    id: 'org.members.panel',
    panelPath: '/admin/org/members',
    panelTitleKey: 'admin.org.members.title',
    titleKey: 'admin.org.members.title',
    helpKey: 'admin.org.members.description',
    highlightKey: 'org.members.panel',
  },
  {
    id: 'org.members.invite',
    panelPath: '/admin/org/members',
    panelTitleKey: 'admin.org.members.title',
    titleKey: 'admin.org.members.inviteAction',
    highlightKey: 'org.members.panel',
  },
  {
    id: 'org.members.roles',
    panelPath: '/admin/org/members',
    panelTitleKey: 'admin.org.members.title',
    titleKey: 'admin.org.members.columns.role',
    helpKey: 'admin.org.members.roleDescriptions.tenant_admin',
    highlightKey: 'org.members.panel',
  },

  // connect/lines
  {
    id: 'connect.lines.panel',
    panelPath: '/admin/connect/lines',
    panelTitleKey: 'admin.connect.lines.title',
    titleKey: 'admin.connect.lines.title',
    helpKey: 'admin.connect.lines.description',
    highlightKey: 'connect.lines.panel',
  },
  {
    id: 'connect.lines.connect',
    panelPath: '/admin/connect/lines',
    panelTitleKey: 'admin.connect.lines.title',
    titleKey: 'admin.connect.lines.connectAction',
    highlightKey: 'connect.lines.panel',
  },
  {
    id: 'connect.lines.assistant',
    panelPath: '/admin/connect/lines',
    panelTitleKey: 'admin.connect.lines.title',
    titleKey: 'admin.connect.lines.detail.assistantTitle',
    highlightKey: 'connect.lines.panel',
  },
  {
    id: 'connect.lines.active',
    panelPath: '/admin/connect/lines',
    panelTitleKey: 'admin.connect.lines.title',
    titleKey: 'admin.connect.lines.dangerZone.activeSwitchLabel',
    helpKey: 'admin.connect.lines.dangerZone.activeSwitchHelp',
    highlightKey: 'connect.lines.panel',
  },

  // connect/credentials
  {
    id: 'connect.credentials.panel',
    panelPath: '/admin/connect/credentials',
    panelTitleKey: 'admin.connect.credentials.title',
    titleKey: 'admin.connect.credentials.title',
    helpKey: 'admin.connect.credentials.description',
    highlightKey: 'connect.credentials.panel',
  },
  {
    id: 'connect.credentials.whoToAsk',
    panelPath: '/admin/connect/credentials',
    panelTitleKey: 'admin.connect.credentials.title',
    titleKey: 'admin.connect.credentials.whoToAsk',
    highlightKey: 'connect.credentials.panel',
  },

  // connect/data
  {
    id: 'connect.data.panel',
    panelPath: '/admin/connect/data',
    panelTitleKey: 'admin.connect.data.title',
    titleKey: 'admin.connect.data.title',
    helpKey: 'admin.connect.data.description',
    highlightKey: 'connect.data.panel',
  },
  {
    id: 'connect.data.upload',
    panelPath: '/admin/connect/data',
    panelTitleKey: 'admin.connect.data.title',
    titleKey: 'admin.connect.data.uploadAction',
    highlightKey: 'connect.data.panel',
  },

  // assistant/behavior
  {
    id: 'behavior.estado',
    panelPath: '/admin/assistant/behavior',
    panelTitleKey: 'admin.assistant.behavior.title',
    titleKey: 'admin.agentConfig.estado.title',
    helpKey: 'admin.agentConfig.estado.help',
    highlightKey: 'behavior.estado',
  },
  {
    id: 'behavior.modelo',
    panelPath: '/admin/assistant/behavior',
    panelTitleKey: 'admin.assistant.behavior.title',
    titleKey: 'admin.agentConfig.modelo.title',
    helpKey: 'admin.agentConfig.modelHelp',
    highlightKey: 'behavior.modelo',
  },
  {
    id: 'behavior.personalidad',
    panelPath: '/admin/assistant/behavior',
    panelTitleKey: 'admin.assistant.behavior.title',
    titleKey: 'admin.agentConfig.personalidad.title',
    helpKey: 'admin.agentConfig.personalidad.help',
    highlightKey: 'behavior.personalidad',
  },
  {
    id: 'behavior.handoff',
    panelPath: '/admin/assistant/behavior',
    panelTitleKey: 'admin.assistant.behavior.title',
    titleKey: 'admin.agentConfig.handoff.title',
    helpKey: 'admin.agentConfig.handoffHelp',
    highlightKey: 'behavior.handoff',
  },

  // assistant/tools
  {
    id: 'tools.search_knowledge',
    panelPath: '/admin/assistant/tools',
    panelTitleKey: 'admin.assistant.tools.title',
    titleKey: 'admin.assistant.tools.toolLabels.search_knowledge.title',
    helpKey: 'admin.assistant.tools.toolLabels.search_knowledge.effect',
    highlightKey: 'tools.search_knowledge',
  },
  {
    id: 'tools.search_inventory',
    panelPath: '/admin/assistant/tools',
    panelTitleKey: 'admin.assistant.tools.title',
    titleKey: 'admin.assistant.tools.toolLabels.search_inventory.title',
    helpKey: 'admin.assistant.tools.toolLabels.search_inventory.effect',
    highlightKey: 'tools.search_inventory',
  },
  {
    id: 'tools.save_customer_data',
    panelPath: '/admin/assistant/tools',
    panelTitleKey: 'admin.assistant.tools.title',
    titleKey: 'admin.assistant.tools.toolLabels.save_customer_data.title',
    helpKey: 'admin.assistant.tools.toolLabels.save_customer_data.effect',
    highlightKey: 'tools.save_customer_data',
  },
  {
    id: 'tools.create_lead',
    panelPath: '/admin/assistant/tools',
    panelTitleKey: 'admin.assistant.tools.title',
    titleKey: 'admin.assistant.tools.toolLabels.create_lead.title',
    helpKey: 'admin.assistant.tools.toolLabels.create_lead.effect',
    highlightKey: 'tools.create_lead',
  },
  {
    id: 'tools.handoff_to_human',
    panelPath: '/admin/assistant/tools',
    panelTitleKey: 'admin.assistant.tools.title',
    titleKey: 'admin.assistant.tools.toolLabels.handoff_to_human.title',
    helpKey: 'admin.assistant.tools.toolLabels.handoff_to_human.effect',
    highlightKey: 'tools.handoff_to_human',
  },

  // assistant/catalog
  {
    id: 'catalog.panel',
    panelPath: '/admin/assistant/catalog',
    panelTitleKey: 'admin.assistant.catalog.title',
    titleKey: 'admin.assistant.catalog.title',
    helpKey: 'admin.assistant.catalog.description',
    highlightKey: 'catalog.panel',
  },
  {
    id: 'catalog.create',
    panelPath: '/admin/assistant/catalog',
    panelTitleKey: 'admin.assistant.catalog.title',
    titleKey: 'admin.assistant.catalog.createAction',
    highlightKey: 'catalog.panel',
  },

  // activity
  {
    id: 'activity.panel',
    panelPath: '/admin/activity',
    panelTitleKey: 'admin.activity.title',
    titleKey: 'admin.activity.title',
    helpKey: 'admin.activity.description',
    highlightKey: 'activity.panel',
  },
  {
    id: 'activity.type',
    panelPath: '/admin/activity',
    panelTitleKey: 'admin.activity.title',
    titleKey: 'admin.activity.filters.typeLabel',
    highlightKey: 'activity.panel',
  },
  {
    id: 'activity.period',
    panelPath: '/admin/activity',
    panelTitleKey: 'admin.activity.title',
    titleKey: 'admin.activity.filters.periodLabel',
    highlightKey: 'activity.panel',
  },
];
