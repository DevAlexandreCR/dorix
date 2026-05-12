export interface TenantRecord {
  id: number;
  name: string;
  slug: string;
  status: string;
  metadata: Record<string, unknown>;
  created_at: string | null;
  updated_at: string | null;
}

export interface TenantUserRecord {
  id: number;
  tenant_id: number;
  role: string | null;
  created_at: string | null;
  updated_at: string | null;
  user: {
    id: number;
    name: string;
    email: string;
  } | null;
}

export interface WhatsAppLineRecord {
  id: number;
  tenant_id: number;
  name: string;
  phone_number_id: string;
  display_phone_number: string | null;
  waba_id: string | null;
  status: string;
  is_enabled: boolean;
  metadata: Record<string, unknown>;
  created_at: string | null;
  updated_at: string | null;
}

export interface CredentialMetadataRecord {
  id: number;
  tenant_id: number;
  whatsapp_line_id: number | null;
  scope_type: string;
  scope_key: string;
  provider: string;
  credential_key: string;
  metadata: Record<string, unknown>;
  last_used_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  has_secret: boolean;
  whatsapp_line: {
    id: number;
    name: string;
    display_phone_number: string | null;
  } | null;
}

export interface AgentConfigRecord {
  id: number;
  tenant_id: number;
  whatsapp_line_id: number | null;
  scope_type: string;
  scope_key: string;
  name: string;
  model: string | null;
  model_key: string | null;
  effective_model_key: string;
  effective_model_label: string;
  effective_model_id: string;
  model_source: 'line' | 'tenant' | 'system_default';
  prompt_version: string | null;
  is_active: boolean;
  settings: Record<string, unknown>;
  automation_enabled: boolean;
  system_prompt: string;
  agent_pack_key: string;
  handoff_customer_message: string;
  created_at: string | null;
  updated_at: string | null;
  whatsapp_line: {
    id: number;
    name: string;
    display_phone_number: string | null;
  } | null;
}

export interface ToolConfigRecord {
  id: number;
  tenant_id: number;
  whatsapp_line_id: number | null;
  scope_type: string;
  scope_key: string;
  tool_name: string;
  enabled: boolean;
  timeout_seconds: number | null;
  bindings: Record<string, unknown>;
  data_source_id: number | null;
  overrides: Record<string, unknown>;
  created_at: string | null;
  updated_at: string | null;
  whatsapp_line: {
    id: number;
    name: string;
    display_phone_number: string | null;
  } | null;
}

export interface DataSourceRecord {
  id: number;
  name: string;
  type: string;
  status: string;
  config: Record<string, unknown>;
  metadata: Record<string, unknown>;
  last_synced_at: string | null;
  chunk_count: number;
  latest_upload: {
    id: number;
    original_name: string;
    mime_type: string | null;
    size_bytes: number;
    checksum: string | null;
    uploaded_by_user_id: number | null;
    created_at: string | null;
  } | null;
  latest_import: {
    id: number;
    status: string;
    attempts_count: number;
    processed_sheet_count: number;
    generated_chunk_count: number;
    error_message: string | null;
    started_at: string | null;
    finished_at: string | null;
    metadata: Record<string, unknown>;
  } | null;
}

export interface AgentEventLog {
  id: number;
  event_type: string;
  conversation_id: number | null;
  conversation_message_id: number | null;
  whatsapp_line_id: number | null;
  payload: Record<string, unknown>;
  occurred_at: string | null;
}

export interface AuditEventLog {
  id: number;
  event_type: string;
  actor_user: {
    id: number;
    name: string;
    email: string;
  } | null;
  target_type: string | null;
  target_id: number | null;
  payload: Record<string, unknown>;
  occurred_at: string | null;
}

export interface ToolExecutionLog {
  id: number;
  tool_name: string;
  status: string;
  conversation_id: number | null;
  conversation_message_id: number | null;
  duration_ms: number | null;
  error_message: string | null;
  input_summary: Record<string, unknown>;
  output_summary: Record<string, unknown>;
  metadata: Record<string, unknown>;
  executed_at: string | null;
  created_at: string | null;
}

export interface AgentModelOption {
  key: string;
  model_id: string;
  label: string;
  description: string;
  recommended: boolean;
  visible_in_ui: boolean;
  sort_order: number;
}

export interface AdminOverview {
  tenant: TenantRecord;
  tenant_users: TenantUserRecord[];
  whatsapp_lines: WhatsAppLineRecord[];
  credential_metadata: CredentialMetadataRecord[];
  agent_configs: AgentConfigRecord[];
  tool_configs: ToolConfigRecord[];
  data_sources: DataSourceRecord[];
  logs: {
    agent_events: AgentEventLog[];
    audit_events: AuditEventLog[];
    tool_executions: ToolExecutionLog[];
  };
  available_roles: string[];
  available_agent_packs: {
    key: string;
    name: string;
  }[];
  available_models: AgentModelOption[];
  binding_tools: string[];
  available_tools: {
    name: string;
    description: string;
    supports_data_source_binding: boolean;
  }[];
}
