# MVP Technical Specification - Multi-Tenant WhatsApp Automation Platform

## 1. Objective

Build a production-ready MVP for a multi-tenant automation platform focused initially on WhatsApp.

This document is a technical specification for the implementation team. Its purpose is to define:

- fixed architecture decisions
- MVP scope boundaries
- minimum domain contracts
- operational constraints
- decisions that remain intentionally open

The platform must support:

- registering and managing multiple tenants
- configuring one or more WhatsApp lines per tenant
- receiving messages through the official WhatsApp Cloud API
- running a configurable automation agent per tenant
- connecting tenant-specific data sources such as Excel files and other approved integrations
- storing conversations, messages, agent decisions, tool executions, logs, and errors
- supporting internal human handoff without Chatwoot in the MVP
- accepting client-specific integrations without rewriting the platform core

The priority is to ship quickly to production while keeping boundaries clean enough to support additional tenants later.

---

## 2. Document Conventions

Use the following labels consistently when implementing or revising the MVP:

- `Fixed Decision`: architecture or behavior already chosen for the MVP
- `MVP In Scope`: functionality explicitly committed for the MVP
- `Out of Scope`: functionality explicitly excluded from the MVP
- `Decision Needed`: point that remains open and must not be assumed closed by implementers

If a topic is not marked as `Fixed Decision` or `MVP In Scope`, it is not committed by default.

---

## 3. Fixed Architecture Decisions

### 3.1 Platform Core

`Fixed Decision`

- The core platform is custom-built.
- The system of record for tenants, WhatsApp lines, conversations, agent configuration, permissions, audit logs, and production agent behavior lives inside the platform.
- Do not build one separate bot per client.
- Do not build a fully generic automation builder in the MVP.
- Client-specific behavior must be isolated in adapters, tools, configuration, and prompt versions, not in scattered tenant-name conditionals.

### 3.2 MVP Stack

`Fixed Decision`

- Backend: Laravel API
- Frontend: Vue 3 + TypeScript
- Database: PostgreSQL
- Cache and queues: Redis
- Async processing: Laravel Queues with Supervisor or Laravel Horizon
- Channel: WhatsApp Cloud API
- Runtime: custom Agent Runtime
- Handoff: internal human handoff module
- Deployment baseline: Docker Compose, Nginx, SSL, queue worker, persistent logs

### 3.3 Tools That Are Not the Core

`Fixed Decision`

Do not use Flowise, Dify, Langflow, PenguiFlow, n8n, or any visual builder as the core runtime or system of record.
External automation tools may be used later for peripheral workflows only.

`Out of Scope`

- visual builder as the primary orchestration layer
- external automation platform as the source of truth
- visual flow ownership of tenant configuration or conversation state

---

## 4. Canonical Domain Model and Tenancy Rules

### 4.1 Canonical Entities

`Fixed Decision`

The following entities define the minimum canonical domain model:

- `tenant`
  - a client workspace with its own users, WhatsApp lines, agent configuration, data sources, and logs
- `whatsapp_line`
  - a tenant-owned WhatsApp sender identity used to receive and send messages through WhatsApp Cloud API
- `phone_number_id`
  - the canonical external identifier used to resolve the inbound WhatsApp line
- `api_credentials`
  - tenant-scoped or line-scoped secrets required to call approved external services
- `conversation`
  - a tenant-owned customer thread bound to one WhatsApp line
- `conversation_message`
  - an inbound or outbound message inside one conversation
- `conversation_state`
  - the mutable state snapshot used by the runtime for the current conversation

### 4.2 Canonical Relationships

`Fixed Decision`

- One `tenant` owns many `whatsapp_lines`.
- One `whatsapp_line` belongs to exactly one `tenant`.
- One `whatsapp_line` has exactly one active `phone_number_id`.
- One `conversation` belongs to exactly one `tenant` and one `whatsapp_line`.
- One `conversation` has many `conversation_messages`.
- One `conversation` has one current `conversation_state` snapshot in the MVP.
- `api_credentials` must be scoped explicitly either to a tenant or to a tenant-owned line; scope must never be inferred at runtime.

`Fixed Decision`

- `waba_id` is optional operational metadata in the MVP and is not a first-class domain pivot.

### 4.3 Tenancy Rules

`Fixed Decision`

The MVP uses single-database multi-tenancy.

Rules:

- Tenant-owned business tables must include `tenant_id`.
- No business query may execute without explicit tenant context.
- Jobs must receive `tenant_id` explicitly.
- Tools must execute under explicit tenant context.
- Logs and audit events tied to tenant activity must include `tenant_id`.
- Webhooks must resolve the target line by `phone_number_id`, then derive tenant context from that line.
- Credentials must never be loaded without explicit tenant or line scope.

### 4.4 Tenant-Scoped Tables

`Fixed Decision`

The following tables are tenant-scoped in the MVP:

```text
tenant_users
whatsapp_lines
agent_configs
data_sources
conversations
conversation_messages
conversation_states
tenant_tool_configs
tool_executions
handoff_requests
uploaded_files
api_credentials
agent_events
audit_events
```

The following tables are platform-level:

```text
tenants
users
```

### 4.5 Minimum Keys and Constraints

`Fixed Decision`

- `whatsapp_lines.phone_number_id` must be globally unique.
- `conversation_states.conversation_id` must be unique in the MVP if the state model is implemented as one mutable snapshot row per conversation.
- Inbound message deduplication must use a unique key derived from the provider message identifier within tenant scope.
- Foreign key ownership must be logically consistent even if some constraints are deferred at the database level during early MVP implementation.

`Fixed Decision`

- The MVP uses `(tenant_id, provider_message_id)` as the inbound deduplication key unless a provider payload defect is proven in implementation.
- Outbound send attempts require a dedicated `idempotency_key` persisted before the provider call.
- `conversation_state` remains a single mutable snapshot row per conversation in the MVP.

---

## 5. Core Components

### 5.1 Control Plane

`Fixed Decision`

The Control Plane lives in Laravel and is the admin and configuration layer of the platform.

Responsibilities:

- manage tenants
- manage platform users
- manage tenant users
- manage WhatsApp lines
- store WhatsApp credentials securely
- configure tenant agent behavior
- configure data sources
- enable or disable automation at tenant or line scope
- define handoff rules
- store logs and audit events
- manage integration settings

Suggested persistence areas:

```text
tenants
users
tenant_users
whatsapp_lines
agent_configs
data_sources
api_credentials
uploaded_files
tenant_tool_configs
agent_events
tool_executions
handoff_requests
audit_events
```

### 5.2 WhatsApp Gateway

`Fixed Decision`

The platform exposes the webhook endpoint and owns all WhatsApp Cloud API communication.

Responsibilities:

- verify webhook challenge
- validate inbound payload shape
- resolve `whatsapp_line` by `phone_number_id`
- derive tenant context from the resolved line
- store inbound messages before enqueuing agent processing
- deduplicate inbound messages using provider identifiers
- accept delivery status callbacks and attach them to existing outbound records
- dispatch asynchronous processing jobs
- send outbound messages through WhatsApp Cloud API
- store send failures, provider acceptance, and provider status updates

`MVP In Scope`

- webhook verification
- inbound text message handling
- outbound text message sending
- delivery and error status persistence
- inbound deduplication

`Fixed Decision`

- The MVP accepts non-text inbound types and stores them as `unsupported` message records.
- Manual operator replies support free-form text only in the MVP.

### 5.2.1 Gateway Operational Contract

`Fixed Decision`

For inbound messages:

1. Receive webhook.
2. Validate request and payload.
3. Resolve `whatsapp_line` from `phone_number_id`.
4. Derive `tenant_id`.
5. Persist the inbound message and related raw payload required for support.
6. Record an agent event for receipt or dedup hit.
7. Dispatch `ProcessIncomingMessageJob`.

For provider status callbacks:

1. Receive webhook.
2. Resolve `whatsapp_line` and `tenant_id`.
3. Match the callback to an existing outbound message using provider identifiers.
4. Persist status transition or failure payload.
5. Do not enqueue the full agent runtime.

For outbound messages:

1. Create a local outbound message record.
2. Send the message through WhatsApp Cloud API.
3. Persist provider acceptance or rejection result.
4. Persist later provider status callbacks on the same message record.

### 5.2.2 Gateway Error Handling

`Fixed Decision`

- The HTTP webhook handler must not run the full agent runtime inline.
- Webhook processing must fail fast on invalid line resolution or invalid payloads and log the failure.
- Runtime processing happens asynchronously with retries.
- Repeated inbound delivery of the same provider message must not create duplicate business processing.

`Fixed Decision`

- Failed outbound sends use `3` retries with exponential backoff and rely on backend operational access rather than a dedicated dead-letter UI.

### 5.3 Agent Runtime

`Fixed Decision`

The MVP uses a custom Laravel domain/service runtime rather than a heavy orchestration framework.

The runtime is responsible for:

- loading tenant agent configuration
- loading relevant conversation history
- loading current conversation state
- loading enabled tools for the tenant and line context
- deciding whether to answer, ask for missing information, call a tool, wait, or request human handoff
- validating response output before sending
- storing runtime events and tool execution logs
- handling a follow-up LLM pass when a retrieval tool returns approved context for the reply

### 5.3.1 Runtime Input Contract

`Fixed Decision`

The minimum runtime context must include:

- `tenant`
- `whatsapp_line`
- `conversation`
- recent `conversation_messages`
- current `conversation_state`
- active `agent_config`
- enabled tenant tools
- optional `retrieved_context` and retrieval metadata when a tool has already returned context

### 5.3.2 Runtime Output Contract

`Fixed Decision`

The runtime must resolve into one of these outcomes:

- `send_message`
- `request_missing_information`
- `call_tool`
- `wait_for_customer`
- `request_handoff`
- `no_reply`
- `error`

The runtime must never access tenant data sources directly. It may only use registered tools and approved adapters behind those tools.

`Fixed Decision`

- Retrieval tools do not produce the final customer-facing answer in the MVP.
- A retrieval tool may return approved context plus metadata.
- The runtime then executes a second pass so the conversational model answers from `retrieved_context`.

### 5.3.3 Runtime Failure Behavior

`Fixed Decision`

- Tool failures must be logged with tenant and conversation context.
- Model failures must be logged with tenant and conversation context.
- The runtime must not send an undefined or partially built response.

`Fixed Decision`

- The default failure fallback moves the conversation to `HUMAN_HANDOFF`.
- Prompt versioning exists in backend configuration storage only; the MVP does not require advanced version history UI.
- The initial MVP provider baseline is OpenAI `gpt-5.1`.

Suggested code structure:

```text
app/
  Domains/
    Agent/
      Runtime/
        AgentRuntime.php
        AgentContext.php
        AgentDecision.php
        AgentResponse.php
        PromptBuilder.php
      Tools/
      DTOs/
```

### 5.4 Tooling Model

`Fixed Decision`

The tooling layer is split conceptually into three parts:

1. `tool definition`
   - stable capability definition
   - name
   - description
   - input schema
   - output schema
   - handler class or service binding
   - default timeout

2. `tenant tool configuration`
   - tenant-specific enablement
   - optional line or data-source binding
   - timeout override
   - parameter overrides
   - enabled flag

3. `tool execution log`
   - tenant context
   - conversation context
   - tool name
   - input summary
   - output summary
   - duration
   - success or failure

`Fixed Decision`

- Tool definitions are code-only in the MVP and are not persisted for admin visibility.

Initial tool set:

```text
search_inventory
create_lead
search_knowledge
handoff_to_human
save_customer_data
```

Implementation baseline:

- `create_lead`, `save_customer_data`, and `handoff_to_human` are functionally implemented in the tooling slice before Excel.
- `search_inventory` and `search_knowledge` become functionally complete when the Excel vertical is delivered.

Bad practice:

```php
if ($tenant->name === 'Trisol') {
    // custom logic here
}
```

Good practice:

```php
$tool = ToolRegistry::forTenant($tenant)->get('search_inventory');
$result = $tool->execute($input);
```

### 5.5 Data Source Adapters

`Fixed Decision`

Data source adapters are the integration boundary between platform tools and underlying data providers.

The adapter layer serves both as:

- a domain abstraction used by tools
- a technical contract for integrations

The agent should not care whether data comes from Excel, an API, Google Sheets, an ERP, or a database.

MVP interfaces:

```php
interface DataSourceReader
{
    public function search(DataSource $dataSource, array $filters): array;
}

interface DataSourceImporter
{
    public function sync(DataSourceImport $import): DataSourceImportResult;
}
```

Clarification:

- `search()` is the MVP read contract expected by retrieval tools.
- `find()` is not part of the MVP contract and remains deferred until a real use case exists.
- `sync()` belongs to `DataSourceImporter` in the MVP and is the indexing contract for one import attempt.
- Live read-through integrations are deferred and do not shape the MVP interfaces.

Initial adapter candidates:

```text
ExcelDataSourceAdapter
```

`MVP In Scope`

- Excel upload
- Excel parsing into retrievable chunks
- product or service retrieval over indexed data
- documentary knowledge retrieval over indexed data
- second-pass model answers from retrieved context

`Fixed Decision`

- Generic API data sources are deferred to the next milestone.
- Custom database adapters are deferred until after the MVP proves the need.

### 5.6 Conversations and State

`Fixed Decision`

Every conversation must have persistent message history and persistent runtime state.

The conversation model must avoid overlapping ownership flags.

For the MVP:

- tenant or line level automation enablement belongs in configuration, not in per-conversation duplicated flags
- handoff ownership belongs in conversation status and assignment, not in a separate duplicate boolean

#### 5.6.1 Conversations

Suggested fields:

```text
id
tenant_id
whatsapp_line_id
contact_phone
contact_name
status
assigned_to_user_id
last_message_at
last_customer_message_at
metadata
```

Suggested statuses:

```text
BOT_ACTIVE
WAITING_CUSTOMER
HUMAN_HANDOFF
CLOSED
ERROR
```

Clarification:

- `BOT_ACTIVE`: bot owns the next action
- `WAITING_CUSTOMER`: bot has responded and awaits customer input
- `HUMAN_HANDOFF`: a human operator owns the next action and the runtime must not auto-send replies
- `CLOSED`: conversation intentionally closed
- `ERROR`: conversation requires operational review before automated continuation

`Fixed Decision`

- Do not use `bot_enabled` and `handoff_required` as per-conversation source-of-truth flags in the MVP document.
- `assigned_to_user_id` represents current operator ownership only when a human is actively responsible for the conversation.

#### 5.6.2 Conversation Messages

Suggested fields:

```text
id
tenant_id
conversation_id
direction
provider_message_id
message_type
body
payload
status
sent_at
received_at
failed_at
error_code
error_message
```

Clarification:

- `direction` must distinguish inbound and outbound messages
- `provider_message_id` is required for provider-mapped messages whenever Meta returns one
- `payload` stores the support-relevant provider payload, not decrypted secrets

#### 5.6.3 Conversation State

Suggested fields:

```text
tenant_id
conversation_id
current_intent
collected_data
last_agent_action
memory_summary
expires_at
updated_at
```

Clarification:

- `conversation_states` is the runtime snapshot table for the current state
- it must include `tenant_id` for tenancy consistency
- `memory_summary` is a short operational memory, not a full transcript replacement

`Fixed Decision`

- Conversation state expiration clears `memory_summary` only and preserves `collected_data`.

### 5.7 Internal Human Handoff

`Fixed Decision`

The MVP includes a simple internal handoff module and does not include Chatwoot.

`MVP In Scope`

- authenticated operator console with minimum SPA `auth/session`
- view active conversations
- open a conversation thread
- view inbound and outbound messages
- mark a conversation as `HUMAN_HANDOFF`
- let an operator send manual replies from the platform
- pause bot automation while a human owns the conversation
- manually reactivate the bot
- log who took the conversation and when

Explicit handoff flow:

1. The runtime or an operator triggers handoff.
2. The conversation status becomes `HUMAN_HANDOFF`.
3. `assigned_to_user_id` is set when a specific operator takes ownership.
4. Automated outbound replies are blocked while the conversation remains in `HUMAN_HANDOFF`.
5. The operator may send manual replies from the platform.
6. A user with the correct permission may reactivate automation.
7. Reactivation returns the conversation to `BOT_ACTIVE` or `WAITING_CUSTOMER` based on the next expected actor.

`Fixed Decision`

- Manual replies in the MVP support only text messages.
- `assigned_to_user_id` is cleared automatically when bot control resumes.

`Out of Scope`

- team routing
- advanced assignment rules
- SLA dashboards
- macros
- omnichannel inbox
- advanced reporting

### 5.8 Knowledge and Retrieval Policy

`Fixed Decision`

The MVP uses documentary retrieval first.

Retrieval policy:

- keep the uploaded file as canonical source material
- parse Excel workbooks into retrievable chunks with row and sheet references
- use PostgreSQL search for MVP retrieval needs
- let the conversational model answer from retrieved context
- do not introduce a vector database in the MVP baseline

`Decision Needed`

- Whether embeddings are approved for a later MVP extension if PostgreSQL search proves insufficient.

`Out of Scope`

- Pinecone
- Weaviate
- Qdrant
- complex hybrid retrieval infrastructure

### 5.9 Peripheral Automations

`Fixed Decision`

External automation tools may support non-critical workflows only. The platform must continue operating if those tools are removed.

Allowed later use cases:

- Google Sheets synchronization
- internal notifications
- CRM updates
- email alerts
- back-office tasks
- non-critical webhooks

`Out of Scope`

- deciding bot responses in n8n
- storing conversations in n8n
- storing tenants in n8n
- controlling the main WhatsApp webhook flow in n8n
- running the primary agent logic in n8n

---

## 6. Security, Roles, Observability, and Reliability

### 6.1 Security Requirements

`Fixed Decision`

Minimum security requirements:

- encrypt WhatsApp access tokens at rest
- encrypt tenant API keys and external service credentials at rest
- do not store secrets in plain text
- validate WhatsApp webhook requests and payloads
- scope credentials explicitly to tenant or line
- use role-based access control
- separate platform admins from tenant users

Log handling requirements:

- raw credentials must never appear in logs
- audit and runtime logs should avoid copying full secret-bearing payloads
- support payload storage must keep only the provider fields required for debugging and traceability

Initial roles:

```text
platform_admin
tenant_admin
operator
viewer
```

`Fixed Decision`

- Tenant admins can view credential metadata only and cannot rotate or replace encrypted credentials in the MVP UI.

### 6.2 Observability

`Fixed Decision`

The system must emit enough events to reconstruct what happened in a conversation without guessing.

Minimum event set:

```text
webhook_received
webhook_rejected
message_deduplicated
message_saved
processing_job_dispatched
processing_job_started
processing_job_completed
processing_job_failed
agent_started
agent_response_generated
tool_called
tool_succeeded
tool_failed
whatsapp_message_send_requested
whatsapp_message_sent
whatsapp_message_rejected
whatsapp_status_updated
handoff_triggered
handoff_accepted
bot_resumed
conversation_marked_error
```

### 6.3 Reliability Constraints

`Fixed Decision`

- WhatsApp webhooks must not run full agent logic inline.
- Inbound duplicate provider messages must not trigger duplicate business processing.
- Processing of the same conversation must not run concurrently without protection against race conditions.
- Failed async jobs must be retryable without creating duplicate customer-visible replies.
- Runtime failures must end in a persisted operational state, not silent loss.

Ordering and concurrency baseline:

- assume inbound messages can arrive close together
- serialize or lock processing per conversation before runtime execution
- persist enough local state to detect already-processed inbound events

`Fixed Decision`

- Queue retry counts are `3` with exponential backoff.
- Failed runtime processing first attempts automatic handoff fallback to `HUMAN_HANDOFF`.
- The MVP relies on backend operational access and does not require a dead-letter review UI.

---

## 7. Suggested Backend Structure

```text
app/
  Domains/
    Tenancy/
    WhatsApp/
      Controllers/
      Services/
      Jobs/
      DTOs/
    Conversations/
      Models/
      Services/
      Jobs/
    Agent/
      Runtime/
      Tools/
      Prompts/
      DTOs/
    DataSources/
      Adapters/
      Importers/
      Services/
    Handoff/
    Audit/
    Shared/
```

---

## 8. Main Message Flow

`Fixed Decision`

```text
1. Customer sends WhatsApp message.
2. Meta sends webhook to Laravel.
3. Laravel validates the webhook and payload.
4. Platform resolves the WhatsApp line by phone_number_id.
5. Platform derives tenant context from the resolved line.
6. Platform stores the inbound message or records a dedup hit.
7. Platform dispatches the processing job.
8. Worker loads conversation, conversation state, agent configuration, and enabled tools.
9. Agent Runtime decides the next action.
10. Runtime calls tools if data is needed.
11. Runtime resolves one outcome: reply, wait, handoff, no-reply, or error.
12. Platform sends an outbound WhatsApp message only if the runtime outcome allows it.
13. Platform stores outbound message results and later provider status callbacks.
14. If human attention is required, the conversation is moved to HUMAN_HANDOFF or ERROR according to policy.
```

---

## 9. MVP Scope

### 9.1 Admin

`MVP In Scope`

- login
- tenant creation
- WhatsApp line creation
- WhatsApp credential storage
- agent prompt configuration
- bot enable or disable at tenant or line scope
- conversation viewer
- internal manual reply
- handoff status visibility
- basic logs

### 9.2 WhatsApp

`MVP In Scope`

- webhook verification
- inbound text message handling
- outbound text message sending
- message deduplication
- tenant resolution by `phone_number_id`
- provider status and error logging

### 9.3 Agent Runtime

`MVP In Scope`

- tenant-specific prompt
- short conversation history
- conversation state loading
- basic intent handling
- tool execution
- retrieval continuation with `retrieved_context`
- human handoff decision
- event logging

### 9.4 Data Sources

`MVP In Scope`

- Excel upload
- Excel parsing to retrievable chunks
- `search_inventory` over indexed data
- `search_knowledge` over indexed data
- binding document sources to retrieval tools
- `create_lead`
- `save_customer_data`
- `handoff_to_human`

### 9.5 Production Setup

`MVP In Scope`

- Docker Compose
- PostgreSQL
- Redis
- Laravel queue worker
- Nginx
- SSL
- Supervisor or Horizon
- persistent logs

---

## 10. Explicitly Out of Scope for the MVP

`Out of Scope`

- Chatwoot
- billing
- visual flow builder
- marketplace of templates
- multi-database tenancy
- Temporal
- Flowise embedded
- Dify embedded
- advanced analytics
- mobile app
- omnichannel support
- fully generic automation builder

Because vector infrastructure is not approved for the MVP baseline, the following are also out of scope unless a later decision changes that:

- Qdrant
- Pinecone
- Weaviate

---

## 11. Decision Needed Register

The following items remain open and must not be silently assumed closed during implementation:

- final inbound dedup unique key shape only if `(tenant_id, provider_message_id)` proves insufficient during implementation
- whether embeddings are approved for a later MVP extension if PostgreSQL search proves insufficient

---

## 12. Final Architecture Baseline

`Fixed Decision`

Build the MVP with:

```text
Laravel API
Vue 3 + TypeScript
PostgreSQL
Redis
Laravel Queues
WhatsApp Cloud API
Custom Agent Runtime
Tool Registry
Data Source Adapters
Excel integration for MVP
Internal Human Handoff
Docker Compose
```

`Fixed Decision`

- Generic API integration is not part of the MVP baseline.

The product must not depend on a visual agent builder or external automation platform as its core.

Start simple, keep boundaries clean, and leave open decisions explicitly visible rather than implied.
