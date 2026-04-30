import type {
  ConversationHandoffSnapshot,
  ConversationStateSnapshot,
  ConversationThreadMessage,
} from '../operations/types';

export interface SandboxLineOption {
  id: number;
  name: string;
  display_phone_number: string | null;
  is_enabled: boolean;
}

export interface SandboxSessionSummary {
  id: number;
  source: string;
  label: string;
  status: string;
  whatsapp_line: {
    id: number;
    name: string;
    display_phone_number: string | null;
  } | null;
  last_message_at: string | null;
  last_message_preview: string | null;
  created_at: string | null;
}

export interface SandboxConversationDetail {
  id: number;
  source: string;
  label: string;
  contact_phone: string;
  status: string;
  whatsapp_line: {
    id: number;
    name: string;
    display_phone_number: string | null;
  } | null;
  last_message_at: string | null;
  last_customer_message_at: string | null;
  state: ConversationStateSnapshot | null;
  latest_handoff: ConversationHandoffSnapshot | null;
  created_at: string | null;
}

export interface SandboxTurnToolExecution {
  id: number;
  tool_name: string;
  status: string;
  duration_ms: number | null;
  error_message: string | null;
  next_action: string | null;
  executed_at: string | null;
}

export interface SandboxTurnEvent {
  id: number;
  event_type: string;
  occurred_at: string | null;
}

export interface SandboxLastTurn {
  triggering_message_id: number;
  runtime_outcome: string | null;
  handoff_requested: boolean;
  error_message: string | null;
  tool_executions: SandboxTurnToolExecution[];
  events: SandboxTurnEvent[];
}

export interface SandboxSessionPayload {
  conversation: SandboxConversationDetail;
  messages: ConversationThreadMessage[];
  last_turn: SandboxLastTurn | null;
  new_messages?: ConversationThreadMessage[];
}
