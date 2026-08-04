# ui-admin — delta

## MODIFIED Requirements

### Requirement: Entidades en tablas con drawer
Toda colección SHALL listarse en `DataTable` (filas de 40 px, estados
con `LiveDot`/badges) y editarse/crearse en `UiDrawer` — aplica a
miembros, líneas, credenciales y fuentes de datos — con las secciones
y zonas de peligro definidas en `design/05`. Las acciones
destructivas SHALL confirmar nombrando la entidad y su efecto real.
La vía principal para conectar una línea SHALL ser el flujo de
Embedded Signup de Meta; el drawer manual de datos técnicos queda
como acción secundaria.

#### Scenario: Conectar una línea con Meta
- **WHEN** el usuario pulsa "Conectar con WhatsApp"
- **THEN** elige el modo (coexistencia o API directa, cada uno con su
  explicación), se abre el popup de autorización de Meta y, al
  completarse, la tabla muestra la nueva línea con toast
  "Línea conectada" — sin pedirle Phone Number ID ni WABA ID

#### Scenario: Conexión manual (fallback)
- **WHEN** el usuario elige la acción secundaria "Conexión manual"
- **THEN** un drawer pide nombre + datos de Meta (cada campo con su
  ayuda) y al guardar la tabla muestra la nueva línea con toast
  "Línea conectada"

#### Scenario: Eliminar una línea
- **WHEN** el usuario pulsa "Eliminar línea" en el drawer
- **THEN** la confirmación indica el número afectado y que dejará de
  responder mensajes, y el botón repite el verbo "Eliminar línea"

## ADDED Requirements

### Requirement: Estados del flujo de conexión con Meta
El flujo de Embedded Signup SHALL comunicar su progreso y fallos sin
estados mudos: mientras el backend procesa la conexión se muestra un
estado de "conectando"; los errores del intercambio se muestran con
mensaje accionable; cerrar el popup de Meta cancela sin mostrar
error. El modo de conexión de cada línea SHALL mostrarse como badge
de solo lectura (Coexistencia / API directa) en la tabla y el drawer.

Este requirement extiende el flujo introducido en "Entidades en
tablas con drawer" (scenario "Conectar una línea con Meta") con los
estados de progreso y error.

#### Scenario: Conexión en progreso
- **WHEN** el popup de Meta se completa y el backend está
  intercambiando el token
- **THEN** el botón muestra estado de carga y no puede relanzarse el
  flujo hasta resolver

#### Scenario: Autorización expirada
- **WHEN** el backend responde 422 porque el code expiró
- **THEN** el usuario ve un mensaje que indica reintentar la conexión,
  no un error técnico

#### Scenario: Popup cancelado
- **WHEN** el usuario cierra el popup de Meta sin completar el flujo
- **THEN** la vista vuelve a su estado inicial sin toast de error

#### Scenario: Modo visible como badge
- **WHEN** el usuario abre el drawer de una línea conectada
- **THEN** ve el modo de conexión como badge de solo lectura junto a
  los datos técnicos de Meta, nunca como campo editable
