# ui-admin — Admin de tenant

Fuente de diseño: `design/04-arquitectura-informacion.md` y
`design/05-tenant-admin.md`.

## ADDED Requirements

### Requirement: Layout de admin con sub-rutas
`/admin` SHALL renderizar un `AdminLayout` con AdminNav (sidebar en
`lg+`, drill-down de pantalla completa en `<lg`), PanelHeader
(breadcrumb + título + una frase + slot de acciones) y un
`<RouterView/>` donde cada sub-ruta monta su propia vista. El panel
monolítico (`AdminView.vue`) SHALL NOT existir, y el parámetro
`?panel=` SHALL NOT tener ningún efecto.

#### Scenario: Sub-ruta renderiza su vista
- **WHEN** el usuario navega a `/admin/org/members`
- **THEN** se monta `MembersView` dentro de `AdminLayout`, con su
  breadcrumb `Admin › Organización › Miembros`

#### Scenario: /admin sin sub-ruta
- **WHEN** el usuario visita `/admin`
- **THEN** es redirigido a la primera sub-ruta permitida según el
  orden fijo `ADMIN_FALLBACK_ORDER`, o ve `ForbiddenState` si ninguna
  pasa

### Requirement: Gating por permisos
Cada sub-ruta de admin SHALL declarar `meta.requires` (claves de
`useNavigationAccess`, semántica OR) y el guard del router SHALL
evaluarlas en cada navegación. AdminNav SHALL filtrar con la misma
tabla (`ADMIN_ROUTE_REQUIRES`), ocultando (no deshabilitando) los
grupos sin ningún panel permitido.

#### Scenario: Acceso directo sin permiso
- **WHEN** un usuario con solo `agent_configs.manage` navega
  directamente a `/admin/org/members`
- **THEN** ve `ForbiddenState` y AdminNav solo muestra el grupo
  Asistente

### Requirement: Búsqueda de ajustes
AdminNav SHALL ofrecer una búsqueda fuzzy (atajo `/`) que indexa
títulos, labels y textos de ayuda de todos los paneles permitidos;
elegir un resultado SHALL navegar al panel y resaltar la ficha
destino.

#### Scenario: Encontrar un ajuste por su ayuda
- **WHEN** el usuario escribe "modelo" en la búsqueda y elige el
  resultado "Modelo — Comportamiento del asistente"
- **THEN** navega a `/admin/assistant/behavior` y la ficha Modelo
  queda resaltada temporalmente

### Requirement: Pantallas resumen-primero
Las pantallas de configuración (`org/info`, `assistant/behavior`,
`assistant/tools`) SHALL presentar cada grupo de ajustes como
`SummaryCard` con una frase de estado en lenguaje del negocio y
edición bajo demanda, con guardado por ficha, según los wireframes de
`design/05`. Toda etiqueta y ayuda SHALL existir en `es-CO` y `en`, y
ningún identificador crudo del sistema (snake_case, IDs técnicos)
SHALL aparecer como copy salvo dentro de `TechValue`.

#### Scenario: Estado del asistente legible
- **WHEN** el usuario abre `/admin/assistant/behavior` con el
  asistente activo
- **THEN** la primera ficha muestra "Respondiendo automáticamente"
  con `LiveDot` y la frase de efecto de apagarlo, sin ningún
  formulario expandido

#### Scenario: Selección de modelo explicada
- **WHEN** el usuario edita la ficha Modelo
- **THEN** ve tres opciones (Ahorro / Equilibrado / Máxima precisión)
  con una frase y costo relativo cada una, y el ID técnico solo como
  `TechValue`

### Requirement: Herencia organización→línea por diff
`assistant/behavior` y `assistant/tools` SHALL ofrecer un selector de
ámbito (organización | línea). En ámbito línea, cada ficha SHALL
mostrar `InheritanceChip` (`Heredado de la organización` mostrando el
valor general, o `Personalizado` con acción "Restaurar al general"), y
SHALL persistir únicamente los campos personalizados vía los endpoints
de línea existentes; restaurar SHALL eliminar el override. Cambiar de
ámbito con cambios sin guardar SHALL pedir confirmación.

#### Scenario: Personalizar una línea
- **WHEN** en ámbito "Línea: Ventas" el usuario personaliza solo el
  modelo y guarda
- **THEN** se persiste únicamente el override de modelo para esa línea
  y las demás fichas siguen mostrando "Heredado de la organización"

#### Scenario: Restaurar al general
- **WHEN** el usuario pulsa "Restaurar al general" en una ficha
  personalizada
- **THEN** el override de la línea se elimina y la ficha vuelve a
  mostrar el valor de la organización como heredado

#### Scenario: Cambio de ámbito con cambios sin guardar
- **WHEN** el usuario cambia de ámbito con un formulario sucio
- **THEN** ve una confirmación; cancelar mantiene el ámbito y los
  cambios, confirmar descarta y cambia

### Requirement: Entidades en tablas con drawer
Las colecciones (miembros, líneas, credenciales, fuentes de datos)
SHALL listarse en `DataTable` (filas de 40 px, estados con
`LiveDot`/badges) y editarse/crearse en `UiDrawer`, con las secciones
y zonas de peligro definidas en `design/05`. Las acciones
destructivas SHALL confirmar nombrando la entidad y su efecto real.

#### Scenario: Conectar una línea
- **WHEN** el usuario pulsa "Conectar línea"
- **THEN** un drawer pide nombre + datos de Meta (cada campo con su
  ayuda) y al guardar la tabla muestra la nueva línea con toast
  "Línea conectada"

#### Scenario: Eliminar una línea
- **WHEN** el usuario pulsa "Eliminar línea" en el drawer
- **THEN** la confirmación indica el número afectado y que dejará de
  responder mensajes, y el botón repite el verbo "Eliminar línea"

### Requirement: Estados nunca como texto libre
Ningún valor enumerado (estado de tenant, estado de línea) SHALL
editarse como texto libre. Los estados SHALL mostrarse como badge de
solo lectura y cambiarse únicamente mediante la acción dedicada
correspondiente (p. ej. Pausar/Reactivar en la zona de peligro).

#### Scenario: Estado del tenant
- **WHEN** el usuario abre la información de la organización
- **THEN** el estado aparece como badge (Activa/Pausada) y solo puede
  cambiarse con la acción "Pausar/Reactivar la organización" de la
  zona de peligro, nunca en un input de texto

### Requirement: Actividad como timeline unificado
`/admin/activity` SHALL mostrar eventos de agente, auditoría y
herramientas en un solo timeline con filtros por tipo/período/línea,
en frases humanas; el payload técnico SHALL verse solo bajo demanda
(drawer).

#### Scenario: Evento legible
- **WHEN** el asistente pasó una conversación a un humano
- **THEN** el timeline muestra una frase del estilo «Pasó a humano:
  "cliente pidió factura"» con hora y línea

### Requirement: Fuentes de datos sin JSON crudo
`/admin/connect/data` SHALL listar las fuentes con estados en verbos
humanos (Lista / Procesando / Falló + causa + reintentar) y metadatos
legibles; SHALL NOT renderizar JSON crudo de importación. La
asignación de fuentes a herramientas SHALL vivir únicamente en
`/admin/assistant/tools`.

#### Scenario: Fuente fallida
- **WHEN** el procesamiento de un archivo falla
- **THEN** la fila muestra "Falló" con la causa en una frase y una
  acción "Reintentar"

### Requirement: Datos por recurso
Las vistas de admin SHALL obtener datos mediante el composable
compartido (estado loading/error/éxito unificado y toasts): el
overview se carga **una vez** por sesión de admin o cambio de tenant,
y cada mutación SHALL actualizar su recurso en memoria a partir de la
respuesta de la propia mutación — sin re-llamar el overview completo
ni hacer GETs de seguimiento.

#### Scenario: Guardar sin refetch global
- **WHEN** el usuario guarda el rol de un miembro
- **THEN** la colección de miembros se actualiza desde la respuesta de
  la mutación y no se dispara ninguna recarga del overview
