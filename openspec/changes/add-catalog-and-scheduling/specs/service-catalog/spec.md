# service-catalog Specification (delta)

## ADDED Requirements

### Requirement: Modelo de catálogo tenant-scoped
El sistema SHALL persistir un catálogo por tenant en `catalog_items`
(modelo `CatalogItem` que extiende `TenantScopedModel`), donde cada ítem
tiene `kind` (`service` | `product`), `name`, `category` opcional,
`description` opcional, precio (`price_type`: `fixed` | `from` | `range`,
con `price_amount` o `price_min`/`price_max` según el tipo, `currency`
default `COP`), `duration_minutes` opcional, `assessment_item_id`
opcional (FK auto-referencial), `active` y `metadata` JSON.

#### Scenario: Ítems aislados por tenant
- **WHEN** un usuario del tenant A consulta el catálogo
- **THEN** solo recibe ítems con `tenant_id` del tenant A (scope
  automático de `HasTenantScope`)

#### Scenario: Producto sin campos de agendamiento
- **WHEN** se crea un ítem con `kind: product`
- **THEN** se persiste con `duration_minutes` y `assessment_item_id`
  nulos y nunca es agendable

### Requirement: Valoración como ítem vinculado
Un ítem con `assessment_item_id` no nulo SHALL considerarse "requiere
valoración previa": no es agendable directo y su ítem vinculado (la
valoración) es un `CatalogItem` normal, agendable, con su propio precio y
`duration_minutes`. El sistema SHALL rechazar cadenas (un ítem de
valoración no puede tener a su vez `assessment_item_id`) y vínculos entre
tenants distintos. Si `assessment_item_id` no es nulo y `price_amount` es
nulo, el precio SHALL comunicarse como "según valoración".

#### Scenario: Vínculo válido compartido
- **WHEN** dos procedimientos apuntan al mismo ítem de valoración del
  mismo tenant
- **THEN** ambos vínculos se persisten correctamente

#### Scenario: Cadena de valoraciones rechazada
- **WHEN** se intenta asignar `assessment_item_id` a un ítem que ya es
  referenciado como valoración, o cuyo destino tiene a su vez
  `assessment_item_id`
- **THEN** la operación falla con error de validación

#### Scenario: Precio según valoración
- **WHEN** un ítem tiene `assessment_item_id` no nulo y `price_amount`
  nulo
- **THEN** las representaciones del ítem (API, índice de prompt) lo
  describen como precio según valoración, no como precio 0

### Requirement: API admin CRUD de catálogo
El sistema SHALL exponer bajo `/api/v1/admin` endpoints tenant-scoped para
listar, crear, actualizar y eliminar ítems de catálogo, protegidos por el
permiso de gestión de configuración del agente. La validación SHALL exigir
coherencia de precio (`fixed` requiere `price_amount`; `range` requiere
`price_min < price_max`) y `duration_minutes` positivo cuando el ítem es
agendable (servicio sin valoración vinculada, o ítem de valoración).

#### Scenario: Crear servicio con precio fijo
- **WHEN** un `tenant_admin` envía `POST` con `kind: service`,
  `price_type: fixed`, `price_amount` y `duration_minutes`
- **THEN** el ítem se crea y se retorna con status 201

#### Scenario: Rango de precio incoherente
- **WHEN** se envía `price_type: range` con `price_min >= price_max`
- **THEN** la API responde 422 con error de validación

#### Scenario: Rol sin permiso
- **WHEN** un usuario con rol `viewer` intenta crear un ítem
- **THEN** la API responde 403
