# Spec UI/UX Dorix: scroll, branding y lenguaje visible

## Objetivo y no-objetivos

Este documento define una spec ejecutable para futuros cambios de UI/UX en la SPA de `frontend/`. No describe cambios ya aplicados en codigo y no reemplaza `docs/agent-outputs.md`, que sigue enfocado en runtime, policy, outcomes y tools.

Objetivo:

- Resolver de forma explicita el modelo de scroll por ruta y breakpoint.
- Cerrar la direccion visual para que el frontend tenga una referencia concreta de jerarquia, densidad y lenguaje.
- Bajar la copia visible a lenguaje de negocio para usuarios operativos y admins no tecnicos.
- Redefinir `Bindings` como `Herramientas` sin cambiar contratos backend ni nombres internos.

No-objetivos:

- No introducir logica nueva de dominio, WhatsApp, runtime, Excel ni panel admin fuera de presentacion y ergonomia.
- No cambiar contratos backend, rutas API, payloads, enums ni semantica interna.
- No hacer rebranding. Se preservan tokens, tipografia y tono base de Dorix.
- No copiar markup, assets ni librerias React de referencias externas.
- No agregar un tercer panel persistente de contacto o contexto en esta iteracion.

## Estado actual verificado en el repo

Estado real hoy, verificado en `frontend/`:

- `frontend/src/app/AppShell.vue` aplica `xl:h-dvh` y `xl:overflow-hidden` solo cuando recibe `lockViewport=true`.
- `frontend/src/layouts/WorkspaceLayout.vue` activa `lockViewport` solo cuando `route.meta.section` es `operations` o `sandbox`.
- `frontend/src/modules/admin/views/AdminView.vue` no bloquea viewport hoy; sigue en flujo de pagina normal.
- `frontend/src/modules/sandbox/views/SandboxView.vue` ya combina `xl:overflow-hidden` con listas y viewport internos scrollables.
- `frontend/src/modules/operations/views/OperationsView.vue` sigue siendo mayormente flujo de pagina; todavia no implementa el split pane scrollable que el layout objetivo necesita.
- `frontend/src/router/index.ts` ya resetea navegacion con `scrollBehavior() { return { top: 0 }; }`.
- `frontend/src/modules/admin/views/AdminView.vue` renderiza configuraciones de herramientas desde `binding_tools`, aunque `frontend/src/modules/admin/types.ts` ya expone `available_tools` y `supports_data_source_binding`.
- `frontend/src/i18n/locales/es-CO.ts` y `frontend/src/i18n/locales/en.ts` todavia muestran terminologia tecnica visible como `tenant`, `bindings`, `runtime`, `logs`, `scope`, `Phone Number ID`, `chunks` y `credentials`.

Este estado actual debe quedar reflejado en la implementacion. El rediseño no debe asumir que `OperationsView` ya tiene resuelto el scroll interno ni que el admin ya usa `available_tools`.

## Direccion visual elegida

### Marca Dorix a preservar

- Paleta base: mantener los tokens actuales de `frontend/src/style.css`.
- Tipografia: mantener `"IBM Plex Sans", "Segoe UI", sans-serif`.
- Modos: conservar soporte dark/light.
- Tono: operativo, claro, confiable y sobrio.

Tokens a preservar:

- `--background`
- `--surface`
- `--surface-muted`
- `--border`
- `--text`
- `--text-muted`
- `--accent`
- `--success`
- `--warning`
- `--danger`
- `--overlay`
- `--shadow-panel`

Se permiten ajustes finos de contraste, espaciamiento y densidad. No se permite introducir una paleta nueva ni cambiar la identidad base.

### Referencias externas adoptadas

La implementacion futura debe tomar patron y jerarquia de estas referencias:

- [Untitled UI Dashboard 07](https://www.untitledui.com/react/components/dashboards/dashboard-07): referencia principal para headers, densidad, metricas compactas y polish de dashboard SaaS.
- [Tailwind Catalyst Sidebar](https://catalyst.tailwindui.com/docs/sidebar): referencia para claridad de navegacion, estados activos y agrupacion visual del shell.
- [Invent Inbox](https://docs.useinvent.com/guides/inbox): referencia para el patron inbox con lista izquierda y hilo principal.
- [TalkJS Inbox](https://talkjs.com/docs/Guides/JavaScript/Classic/Add_Inbox/): referencia para inbox como pagina dedicada con lista a la izquierda y conversacion principal.
- [TailAdmin Vue](https://tailadmin.com/vue): sanity check de stack Vue + Tailwind para confirmar que el lenguaje visual elegido es compatible con el stack actual.

Estas referencias se usan para decisiones de patron, ritmo visual y organizacion. No se deben clonar assets, componentes React, markup ni estructura literal.

### Reglas visuales cerradas

- Subir la jerarquia visual hacia un estilo mas cercano a Untitled UI: encabezados mas limpios, bloques de resumen mas compactos y menos decoracion compitiendo con la informacion.
- Reducir anidacion innecesaria: evitar tarjeta dentro de tarjeta cuando un borde, una seccion o un encabezado sean suficientes.
- El `TopBar` actual se mantiene como shell, pero debe adelgazar su presencia visual:
  - menos altura total;
  - separacion mas clara entre identidad, switches y navegacion;
  - menor peso decorativo;
  - mas espacio util para el contenido principal.
- No introducir sidebars persistentes nuevas.
- No introducir un tercer rail fijo de contacto/contexto en `OperationsView` o `SandboxView`.
- Los radios para elementos nuevos deben mantenerse compactos, en general entre `8px` y `16px`, salvo que se reutilice un componente existente.

## Arquitectura de scroll por ruta y breakpoint

### Decision global

La regla general es scroll nativo de pagina. El scroll interno solo se permite donde aumente productividad y este controlado por un contenedor claramente responsable.

Breakpoint oficial del documento: `xl`.

No usar "desktop" como termino ambiguo. Toda decision de viewport lock o split panes debe hablar en terminos de `xl`.

### Regla de ownership

- Solo puede existir un duenio de `overflow-y` por eje visual.
- Queda prohibido encadenar `overflow-hidden` en varios contenedores si no existe un contenedor hijo claramente definido como viewport desplazable.
- Si una ruta usa viewport bloqueado en `xl`, la cadena de layout debe incluir `min-h-0`, `flex-1` y el contenedor exacto que toma `overflow-y-auto`.
- Si no existe ese contenedor, la ruta debe volver a flujo de pagina normal.

### AppShell y WorkspaceLayout

- `AppShell` y `WorkspaceLayout` mantienen scroll de documento en `mobile` y `tablet < xl`.
- `lockViewport` solo puede seguir aplicandose en `xl` para `operations` y `sandbox`.
- `AdminView` no debe activar viewport lock.
- `h-dvh` y `overflow-hidden` deben existir solo como soporte del split pane en `xl`, nunca como condicion general del shell.

### Mobile y tablet menor a `xl`

En `mobile` y `tablet < xl`, estas vistas deben fluir como documento normal:

- `AppShell`
- `WorkspaceLayout`
- `OperationsView`
- `SandboxView`
- `AdminView`

Criterio obligatorio:

- el usuario debe poder recorrer cada pantalla completa con scroll de pagina;
- no debe quedar atrapado dentro de listas, paneles o chats internos;
- el header global no debe impedir acceso al contenido restante.

### `OperationsView` en `xl`

Layout objetivo:

- split en 2 columnas;
- columna izquierda para filtros + lista de conversaciones;
- columna derecha para hilo, resumen y acciones.

Ownership del scroll:

- columna izquierda: scroll interno permitido y obligatorio si excede altura;
- columna derecha: el timeline del hilo es el scroll principal del panel derecho;
- header de la conversacion, resumenes y acciones deben quedar dentro del panel derecho y mantenerse visibles sin crear un segundo scroll competidor.

No permitido:

- que toda la vista siga dependiendo del scroll del documento en `xl`;
- que header, resumen y formulario de respuesta se mezclen con varios contenedores scrollables independientes;
- que existan dos zonas `overflow-y-auto` compitiendo dentro del panel derecho.

### `SandboxView` en `xl`

Layout objetivo:

- split en 2 columnas;
- columna izquierda para lista de conversaciones de prueba;
- columna derecha para viewport de mensajes y composer.

Ownership del scroll:

- lista izquierda: scroll interno permitido;
- viewport de mensajes: scroll principal derecho;
- panel tecnico: debe ir debajo del viewport principal o dentro de un bloque colapsable; no puede convertirse en un segundo scroll vertical competidor dentro del mismo panel derecho.

Autoscroll:

- mantener autoscroll del chat;
- `scroll-behavior: smooth` solo como mejora general;
- la logica de autoscroll debe seguir gobernada por `prefers-reduced-motion`.

Implementacion esperada:

```ts
messagesViewport.value.scrollTo({
  top: messagesViewport.value.scrollHeight,
  behavior: prefersReducedMotion ? 'auto' : 'smooth',
});
```

`prefersReducedMotion` debe salir de `window.matchMedia('(prefers-reduced-motion: reduce)')` o de un helper frontend equivalente.

### `AdminView`

- `AdminView` debe seguir con scroll de pagina en todos los breakpoints.
- No debe introducir viewport lock.
- Las tabs, resumenes y formularios deben resolverse con flujo normal y navegacion clara, no con layout atrapado.

### Movimiento y navegacion

- Mantener `route.scrollBehavior -> { top: 0 }`.
- Agregar `scroll-behavior: smooth` solo como mejora global no critica.
- Agregar `scrollbar-gutter: stable` para reducir saltos laterales al aparecer scrollbars.
- Respetar completamente `prefers-reduced-motion`.

## Lenguaje visible e i18n

### Regla general

La copia visible debe escribirse para usuarios operativos y admins no tecnicos. El implementador debe actualizar `frontend/src/i18n/locales/es-CO.ts` y `frontend/src/i18n/locales/en.ts` juntos.

El espanol debe priorizar claridad operativa. El ingles debe mantener equivalencia funcional, no traduccion literal de jerga interna.

### Glosario visible obligatorio

| Concepto interno | ES visible | EN visible |
| --- | --- | --- |
| Tenant | Negocio | Business |
| Tenant admin | Admin del negocio | Business admin |
| Bindings | Herramientas | Tools |
| Credentials | Conexiones | Connections |
| Logs | Actividad | Activity |
| Runtime state | Estado del asistente | Assistant state |
| System prompt | Instrucciones del asistente | Assistant instructions |
| Phone Number ID | ID tecnico del numero | Technical phone ID |
| Chunks | Fragmentos procesados | Processed fragments |
| Metadata | Detalles tecnicos | Technical details |
| Fallback | Opcion automatica | Automatic option |
| Scope | Alcance avanzado | Advanced scope |
| Handoff | Revision humana | Human review |

### Prioridad de reemplazo

El cambio de lenguaje visible cubre toda la SPA:

- shell y top bar;
- operations;
- sandbox;
- admin.

No basta con renombrar tabs del admin. La implementacion debe limpiar texto visible en `common`, `operations`, `sandbox` y `admin`.

### Regla de clasificacion de copy

Todo termino tecnico visible debe caer en una de estas dos categorias:

- Visible por defecto si ayuda a operar.
- Movido a `Soporte avanzado` si solo sirve para soporte, debugging o configuracion experta.

### Reglas de contenido

- Los nombres internos pueden seguir existiendo en codigo, tipos, payloads, query params y logs tecnicos.
- La UI principal no debe mostrar nombres internos como texto dominante.
- Evitar explicar arquitectura en labels, ayudas o empty states.
- Preferir texto accionable y orientado a resultado.
- Los errores visibles deben ser comprensibles y accionables.

## Admin / Herramientas / Soporte avanzado

### Navegacion visible del admin

Los tabs visibles del admin deben ser:

- `Negocio`
- `Equipo`
- `WhatsApp`
- `Asistente`
- `Conocimiento`
- `Herramientas`
- `Conexiones`
- `Actividad`

Los nombres internos de panel, las claves y los `query params` pueden mantenerse. El cambio es de presentacion.

### Herramientas como experiencia de producto

La seccion `Herramientas` debe tomar `adminOverview.available_tools` como catalogo principal.

Reglas:

- `available_tools` es el source of truth de la UI para el catalogo visible.
- `binding_tools` no desaparece del contrato; queda como compatibilidad y migracion.
- `supports_data_source_binding` decide si la UI muestra selector de fuente.

La UI objetivo para herramientas es de tarjetas compactas, no de lista tecnica de bindings.

Cada tarjeta debe mostrar:

- icono;
- nombre visible claro;
- descripcion corta;
- estado habilitada/deshabilitada;
- accion principal para guardar;
- fuente asociada solo cuando `supports_data_source_binding === true`;
- acceso a `Soporte avanzado`.

### Catalogo MVP de tools visibles

| Tool interna | Nombre visible | Icono Lucide | Fuente asociada |
| --- | --- | --- | --- |
| `create_lead` | Crear cliente potencial | `UserPlus` | No |
| `save_customer_data` | Guardar datos del cliente | `ClipboardList` | No |
| `handoff_to_human` | Pasar a un asesor | `Headset` | No |
| `search_inventory` | Consultar inventario | `PackageSearch` | Si |
| `search_knowledge` | Buscar respuestas | `BookOpen` | Si |

### Contrato frontend recomendado

La implementacion futura debe introducir una capa de presentacion equivalente a `toolPresentation.ts` para mapear nombre interno a nombre visible, icono y descripcion.

Contrato esperado:

```ts
export const toolPresentation = {
  create_lead: {
    label: 'Crear cliente potencial',
    icon: UserPlus,
    description: 'Registra una oportunidad para seguimiento comercial.',
  },
  save_customer_data: {
    label: 'Guardar datos del cliente',
    icon: ClipboardList,
    description: 'Actualiza datos utiles de contacto y contexto.',
  },
  handoff_to_human: {
    label: 'Pasar a un asesor',
    icon: Headset,
    description: 'Pide revision humana cuando el asistente no debe continuar solo.',
  },
  search_inventory: {
    label: 'Consultar inventario',
    icon: PackageSearch,
    description: 'Busca disponibilidad o datos de productos en una fuente conectada.',
  },
  search_knowledge: {
    label: 'Buscar respuestas',
    icon: BookOpen,
    description: 'Consulta informacion cargada para responder preguntas frecuentes.',
  },
} as const;
```

El texto visible final debe vivir en i18n si el componente se entrega en ES/EN.

### Soporte avanzado

`Soporte avanzado` debe ser el unico contenedor permitido para informacion cruda o interna.

Contenido permitido dentro de `Soporte avanzado`:

- nombres internos de tools;
- `scope_type`, `scope_key` y alcances internos;
- timeouts;
- overrides por linea;
- payload previews;
- tool executions;
- eventos de agente;
- metadatos;
- IDs tecnicos de proveedor;
- errores crudos cuando hagan falta para diagnostico.

Contenido que debe quedar visible por defecto:

- nombre claro de la seccion o herramienta;
- estado de negocio;
- accion principal;
- fuente asociada cuando aplica;
- resultado o alerta que el usuario deba entender.

### Dependencia futura de iconos

`lucide-vue-next` no existe hoy en `frontend/package.json`. Debe tratarse como dependencia futura opcional del rediseño y agregarse dentro de Docker:

```bash
docker compose exec frontend npm install lucide-vue-next
```

Reglas:

- importar iconos concretos;
- no importar el paquete completo;
- usar iconos como apoyo visual, no como unica senal;
- mantener texto visible en acciones principales.

## Compatibilidad y no-cambios

- No cambiar contratos backend, rutas API, payloads ni enums.
- No cambiar semantica interna de `tool_name`, `scope_type`, `route.meta.section` ni `query params`.
- No renombrar internamente tools, tipos ni claves de configuracion.
- `available_tools` pasa a ser la referencia principal de presentacion para la UI de herramientas.
- `binding_tools` se mantiene como parte del contrato mientras siga siendo necesario para compatibilidad.
- No introducir nuevas capacidades de dominio ni integraciones.
- No mover la app a otro sistema de componentes ni a otro framework.

Contratos frontend esperados como shape futura:

- `toolPresentation.ts` o equivalente como mapa de presentacion de tools.
- Helper de reduced motion para autoscroll de chat.
- `available_tools` como source of truth de UI para herramientas.
- `supports_data_source_binding` como switch de selector de fuente.

## Criterios de aceptacion

### Validacion documental

- El archivo distingue explicitamente estado actual del repo y estado objetivo.
- El documento ya no usa `desktop`, `dashboard moderno` o `scroll hibrido` como conceptos vagos; cada decision queda atada a `xl`, rutas y contenedores concretos.
- El documento termina con criterios de aceptacion completos y no queda truncado.

### Aceptacion UX futura

- En `mobile` y `tablet < xl`, el usuario puede recorrer completa cada vista con scroll de pagina sin quedar atrapado dentro de paneles internos.
- En `xl`, `OperationsView` usa split en 2 columnas con lista izquierda scrollable y timeline derecho como scroll principal del panel derecho.
- En `xl`, `SandboxView` usa split en 2 columnas con lista izquierda scrollable y viewport de mensajes como scroll principal derecho.
- En `xl`, `AdminView` sigue en scroll de pagina y no introduce viewport lock.
- El `TopBar` ocupa menos altura visual y deja mas protagonismo al contenido principal.
- La UI de `Herramientas` usa nombres claros, descripcion corta y estado accionable; el detalle crudo aparece solo en `Soporte avanzado`.
- El reemplazo de copy no tecnica cubre shell, operations, sandbox y admin en `es-CO` y `en`.

### Comprobaciones de compatibilidad

- No se cambian contratos API.
- No se cambia la semantica interna de `tool_name`, `scope_type`, `route.meta.section` ni `query params`.
- La incorporacion de `lucide-vue-next` queda documentada como dependencia opcional instalada via Docker.

### Assumptions operativas

- El deliverable de esta tarea documental es un unico archivo: `docs/ui-scroll-branding-plan.md`.
- Se preservan los tokens actuales de `frontend/src/style.css`.
- La inspiracion Untitled UI se aplica a jerarquia, densidad, headers, metricas y settings pages; no a React Aria ni a copiar componentes textualmente.
- El patron de inbox de Invent/TalkJS se usa para resolver `OperationsView` y `SandboxView` en dos paneles; no se agrega un tercer rail en esta iteracion.
- La implementacion posterior debe seguir ejecutandose dentro de Docker, pero este cambio documental no requiere correr builds ni editar codigo ahora.
