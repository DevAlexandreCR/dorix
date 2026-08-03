# ui-admin Specification (delta)

## MODIFIED Requirements

### Requirement: Entidades en tablas con drawer
Toda colección SHALL listarse en `DataTable` (filas de 40 px, estados
con `LiveDot`/badges) y editarse/crearse en `UiDrawer` — aplica a
miembros, líneas, credenciales, fuentes de datos y catálogo — con las
secciones y zonas de peligro definidas en `design/05`. Las acciones
destructivas SHALL confirmar nombrando la entidad y su efecto real.

El catálogo SHALL vivir en la sub-ruta `/admin/assistant/catalog`
(entrada propia en `ADMIN_ROUTE_REQUIRES`, gateada por el permiso de
gestión de configuración del agente). Su drawer de creación/edición
SHALL adaptar los campos según `kind` (producto: sin duración ni
valoración) y según `price_type`, y SHALL permitir vincular un ítem de
valoración existente (solo ítems agendables del mismo tenant, sin
cadenas).

#### Scenario: Conectar una línea
- **WHEN** el usuario pulsa "Conectar línea"
- **THEN** un drawer pide nombre + datos de Meta (cada campo con su
  ayuda) y al guardar la tabla muestra la nueva línea con toast
  "Línea conectada"

#### Scenario: Eliminar una línea
- **WHEN** el usuario pulsa "Eliminar línea" en el drawer
- **THEN** la confirmación indica el número afectado y que dejará de
  responder mensajes, y el botón repite el verbo "Eliminar línea"

#### Scenario: Crear servicio con valoración
- **WHEN** un `tenant_admin` crea un servicio en el drawer de catálogo y
  selecciona un ítem de valoración vinculado
- **THEN** el ítem aparece en la tabla marcando que requiere valoración
  previa

#### Scenario: Producto oculta campos de agendamiento
- **WHEN** el usuario selecciona `kind: product` en el drawer de catálogo
- **THEN** los campos de duración y valoración no se muestran ni se
  envían

## ADDED Requirements

### Requirement: Conexión de Google Calendar por línea
La vista de líneas (`LinesView`) SHALL mostrar por línea el estado de la
conexión de calendario (`connected` | `broken` | `none`) con badge, y su
drawer SHALL ofrecer la acción de conectar/reconectar que inicia el flujo
OAuth. Una conexión `broken` SHALL mostrarse con tono de alerta y
explicación de cómo restaurarla. Esta superficie SHALL NOT vivir en
`CredentialsView` (que mantiene su invariante de solo lectura).

#### Scenario: Conectar una línea al calendario
- **WHEN** un `tenant_admin` pulsa "Conectar Google Calendar" en el
  drawer de una línea sin conexión
- **THEN** el navegador es dirigido a la URL de consentimiento de Google
  y al volver la línea muestra estado conectado

#### Scenario: Conexión rota visible
- **WHEN** la credencial de calendario de una línea fue revocada
- **THEN** la línea muestra el estado roto con acción de reconectar

### Requirement: Copy de tools de agendamiento
Las tools `get_service_details`, `check_availability` y
`create_appointment` SHALL tener copy de negocio en `toolCopyKeys` de la
vista de tools y traducciones en `es_CO` y `en`, y las etiquetas de
provider de credenciales (admin y platform) SHALL reconocer
`google_calendar` con etiqueta legible.

#### Scenario: Tools nuevas con nombre de negocio
- **WHEN** un admin abre la vista de herramientas del asistente
- **THEN** las tools de agendamiento aparecen con título y descripción de
  negocio en el idioma activo, no con su nombre técnico
