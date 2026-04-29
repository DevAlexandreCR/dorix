export interface SessionUser {
  id: number;
  name: string;
  email: string;
  platform_role: string | null;
}

export interface SessionMembership {
  tenant_id: number | null;
  tenant_name: string | null;
  tenant_slug: string | null;
  role: string;
  permissions: string[];
}

export interface SessionResponse {
  authenticated: boolean;
  user: SessionUser | null;
  memberships: SessionMembership[];
}

export interface ConversationParticipant {
  id: number;
  name: string;
  email: string;
}

export interface WhatsAppLineSummary {
  id: number;
  name: string;
  display_phone_number: string | null;
}

export interface ConversationSummary {
  id: number;
  contact_name: string | null;
  contact_phone: string;
  status: string;
  assigned_to_user: ConversationParticipant | null;
  whatsapp_line: WhatsAppLineSummary | null;
  last_message_at: string | null;
  last_customer_message_at: string | null;
  last_message_preview: string | null;
  last_message_direction: string | null;
}

export interface ConversationStateSnapshot {
  current_intent: string | null;
  last_agent_action: string | null;
  collected_data: Record<string, unknown> | null;
  memory_summary: string | null;
  expires_at: string | null;
}

export interface ConversationHandoffSnapshot {
  id: number;
  status: string;
  reason: string | null;
  assigned_to_user_id: number | null;
  accepted_at: string | null;
  resolved_at: string | null;
}

export interface ConversationDetail extends ConversationSummary {
  state: ConversationStateSnapshot | null;
  latest_handoff: ConversationHandoffSnapshot | null;
}

export interface ConversationThreadMessage {
  id: number;
  direction: string;
  message_type: string;
  body: string | null;
  status: string | null;
  provider_message_id: string | null;
  error_message: string | null;
  source: string | null;
  sent_at: string | null;
  received_at: string | null;
  failed_at: string | null;
  created_at: string | null;
}

export interface OperatorOption {
  user_id: number;
  name: string;
  email: string;
  role: string | null;
}

export interface ConversationThreadPayload {
  conversation: ConversationDetail;
  messages: ConversationThreadMessage[];
  available_operators: OperatorOption[];
}
