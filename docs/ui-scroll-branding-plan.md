# Guia UI/UX Dorix: scroll fluido, branding y lenguaje simple

## Estado del documento

Este documento es una guia de implementacion futura para la SPA de `frontend/`. No describe un cambio ya aplicado en codigo y no reemplaza `docs/agent-outputs.md`, que sigue enfocado en runtime, policy, outcomes y tools.

El objetivo es dejar una referencia clara para futuros cambios de UI/UX sin introducir logica nueva de dominio, WhatsApp, runtime, Excel ni panel admin fuera de presentacion, lenguaje y ergonomia.

## Resumen

- Mantener la identidad Dorix actual: acento naranja calido, superficies sobrias, soporte dark/light, densidad media y tono operativo.
- Aplicar un modelo de **scroll hibrido controlado**: scroll nativo de pagina como regla general, con scroll interno solo en escritorio para listas o chats donde mejore productividad.
- Reescribir la UI visible para usuarios no tecnicos, evitando jerga como `tenant`, `runtime`, `bindings`, `tools`, `logs`, `scope`, `system prompt`, `fallback`, `chunks`, `metadata` y `Phone Number ID`.
- Convertir `Bindings` en **Herramientas** como experiencia de producto: tarjetas compactas con icono, nombre claro, estado, accion principal y soporte avanzado colapsado.
- Usar buenas practicas publicas como referencia, no como clon visual: GOV.UK para claridad y lenguaje, Material para tabs/navegacion de secciones, Lucide para iconos consistentes.

## Principios de producto

- La experiencia principal se escribe para personas operativas y administradores no tecnicos.
- Los detalles tecnicos siguen disponibles para soporte, pero no deben dominar la pantalla.
- La UI debe priorizar lectura rapida, acciones claras y continuidad de flujo.
- Las secciones deben usar nombres de negocio, no nombres de arquitectura interna.
- La app debe sentirse como una herramienta de trabajo estable, no como una demo tecnica.

## Branding Dorix

### Identidad visual base

- Acento principal: naranja calido existente en `--accent`.
- Neutrales: base oscura sobria en dark mode y fondo claro calido en light mode, usando los tokens actuales de `frontend/src/style.css`.
- Estados: mantener `--success`, `--warning` y `--danger` para semantica consistente.
- Tipografia: conservar `"IBM Plex Sans", "Segoe UI", sans-serif`.
- Densidad: media. Evitar pantallas vacias, pero tambien evitar paneles saturados.
- Tono visual: operativo, claro y confiable. Reducir decoracion que compita con formularios, listas y conversaciones.

### Tokens base a preservar

Los futuros cambios deben partir de estos tokens existentes:

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

Se pueden ajustar valores para mejorar contraste o legibilidad, pero no introducir una paleta nueva sin una decision explicita de marca.

### Reglas visuales

- Usar tarjetas solo para items repetidos, modales o herramientas realmente enmarcadas.
- Evitar tarjetas dentro de tarjetas cuando una seccion, borde ligero o agrupacion simple sea suficiente.
- Mantener radios compactos para nuevos componentes, idealmente `8px` a `16px`, salvo donde se reutilice un componente existente.
- Usar encabezados compactos dentro de paneles. Reservar tipografia grande para vistas principales, no para controles internos.
- Los textos largos deben truncar, envolver o pasar a soporte avanzado sin romper layout.

## Scroll y layout

### Modelo objetivo

La regla general es scroll nativo de pagina. El usuario nunca debe quedar atrapado en una zona interna sin poder seguir bajando.

Solo se permite scroll interno en escritorio para:

- Lista de conversaciones en `OperationsView`.
- Hilo o chat en `OperationsView`, si el layout de escritorio necesita mantener acciones visibles.
- Lista de conversaciones de prueba en `SandboxView`.
- Viewport de mensajes en `SandboxView`.

En movil y tablet estrecha, `OperationsView`, `SandboxView` y `AdminView` deben fluir como pagina normal.

### Reglas para `AppShell` y `WorkspaceLayout`

- `AppShell` y `WorkspaceLayout` no deben bloquear el viewport por ruta si la vista hija no define correctamente sus zonas internas.
- Cuando una ruta use viewport bloqueado en desktop, la cadena de contenedores debe incluir `min-h-0`, `flex-1` y `overflow-y-auto` donde corresponda.
- `h-dvh` y `overflow-hidden` deben limitarse a breakpoints de escritorio y solo cuando exista un contenedor interno claro para el contenido desplazable.
- El header global debe conservarse estable, pero no debe impedir que el contenido completo sea accesible en pantallas pequenas.

### Movimiento

Agregar en estilos globales:

- `scroll-behavior: smooth` para navegacion natural.
- `scrollbar-gutter: stable` para evitar saltos laterales al aparecer scrollbars.
- Respeto completo a `prefers-reduced-motion`.

En `SandboxView`, el autoscroll del chat debe usar:

```ts
messagesViewport.value.scrollTo({
  top: messagesViewport.value.scrollHeight,
  behavior: prefersReducedMotion ? 'auto' : 'smooth',
});
```

El valor `prefersReducedMotion` debe salir de `window.matchMedia('(prefers-reduced-motion: reduce)')` o de un helper equivalente.

## Lenguaje e i18n

Actualizar `frontend/src/i18n/locales/es-CO.ts` y `frontend/src/i18n/locales/en.ts` juntos. El espanol debe ser claro para usuarios no tecnicos; el ingles debe mantener equivalencia funcional, no traduccion literal de jerga interna.

### Diccionario visible recomendado

| Concepto interno | ES visible | EN visible |
| --- | --- | --- |
| Tenant | Negocio | Business |
| Tenant admin | Admin del negocio | Business admin |
| Runtime | Respuesta del asistente | Assistant response |
| Bindings | Herramientas | Tools |
| Logs | Actividad | Activity |
| Credentials | Conexiones | Connections |
| System prompt | Instrucciones del asistente | Assistant instructions |
| Scope | Alcance avanzado | Advanced scope |
| Metadata | Detalles tecnicos | Technical details |
| Chunks | Fragmentos procesados | Processed fragments |
| Fallback | Opcion automatica | Automatic option |
| Handoff | Revision humana | Human review |
| Phone Number ID | ID tecnico del numero | Technical phone ID |

### Reglas de contenido

- Los nombres internos pueden seguir en codigo, tipos, payloads y logs tecnicos.
- La UI principal no debe mostrar nombres internos como texto principal.
- Si un valor interno es necesario para soporte, mostrarlo dentro de **Soporte avanzado**.
- Evitar frases que expliquen arquitectura. Preferir acciones concretas y resultados visibles.
- Mantener mensajes de error comprensibles y accionables.

## Admin y herramientas

### Navegacion admin objetivo

Renombrar los paneles visibles del admin a:

- `Negocio`
- `Equipo`
- `WhatsApp`
- `Asistente`
- `Conocimiento`
- `Herramientas`
- `Conexiones`
- `Actividad`

Los nombres de rutas, query params y claves internas pueden mantenerse si no afectan la UI visible.

### Herramientas como experiencia de producto

La seccion **Herramientas** debe usar `adminOverview.available_tools` como catalogo completo. No debe limitarse a `binding_tools`, porque `binding_tools` solo representa las herramientas vinculables a fuentes.

Mostrar las 5 tools MVP como tarjetas:

| Tool interna | Nombre visible | Icono Lucide | Fuente asociada |
| --- | --- | --- | --- |
| `create_lead` | Crear cliente potencial | `UserPlus` | No |
| `save_customer_data` | Guardar datos del cliente | `ClipboardList` | No |
| `handoff_to_human` | Pasar a un asesor | `Headset` | No |
| `search_inventory` | Consultar inventario | `PackageSearch` | Si |
| `search_knowledge` | Buscar respuestas | `BookOpen` | Si |

La tarjeta debe incluir:

- Icono.
- Nombre claro.
- Descripcion corta.
- Estado habilitada/deshabilitada.
- Accion principal para guardar cambios.
- Fuente asociada solo cuando `supports_data_source_binding === true`.
- **Soporte avanzado** colapsado con nombre interno, timeout, override por linea y detalles tecnicos.

### Capa de presentacion

Agregar en una implementacion futura una capa frontend como `toolPresentation.ts` para mapear nombres internos a UI:

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

El texto real debe vivir en i18n si el componente se entrega en ES/EN.

## Soporte avanzado

Usar un bloque colapsado llamado **Soporte avanzado** para informacion que ayuda a admins tecnicos o soporte, pero no a la operacion diaria.

Contenido permitido dentro de soporte avanzado:

- Nombres internos de tools.
- Timeouts.
- Overrides por linea.
- `scope_type`, `scope_key` y otros alcances internos.
- Eventos de agente.
- Ejecuciones de tools.
- Payload previews.
- Metadatos.
- IDs tecnicos de proveedor.
- Errores crudos cuando sean necesarios para diagnostico.

Contenido que debe permanecer visible:

- Estado de negocio.
- Nombre claro de la herramienta o seccion.
- Accion principal.
- Fuente asociada cuando aplica.
- Resultado o alerta que el usuario deba entender.

## Iconos y dependencia

`lucide-vue-next` no existe todavia en `frontend/package.json`. Debe tratarse como dependencia futura y agregarse dentro de Docker:

```bash
docker compose exec frontend npm install lucide-vue-next
```

Reglas:

- Importar iconos concretos, no todo el paquete.
- Usar iconos como apoyo visual, no como unica senal.
- Mantener texto visible en acciones principales.
- Evitar iconos decorativos sin funcion.

## Interfaces y compatibilidad

- No cambiar contratos backend, rutas API ni payloads.
- No cambiar nombres internos de tools, tipos o enums.
- Usar `adminOverview.available_tools` para el catalogo completo de herramientas.
- Usar `supports_data_source_binding` para decidir si se muestra selector de fuente.
- Mantener `binding_tools` solo como compatibilidad si una parte existente todavia lo necesita durante la migracion.
- No introducir nuevas capacidades de dominio ni integraciones.

## Plan de implementacion futuro

1. Ajustar estilos globales de scroll y movimiento en `frontend/src/style.css`.
2. Revisar `AppShell` y `WorkspaceLayout` para que el bloqueo de viewport solo aplique con contenedores internos correctos.
3. Ajustar `OperationsView` y `SandboxView` para que desktop mantenga listas/chat productivos y movil use scroll de pagina.
4. Cambiar autoscroll del chat de `SandboxView` a `scrollTo({ top, behavior })` con `prefers-reduced-motion`.
5. Reescribir i18n ES/EN con lenguaje no tecnico.
6. Agregar `lucide-vue-next` desde Docker.
7. Crear la capa `toolPresentation.ts`.
8. Redisenar la seccion admin de herramientas con tarjetas para las 5 tools MVP.
9. Mover configuracion tecnica a **Soporte avanzado**.
10. Validar build y recorridos manuales en desktop y movil.

## Validacion

### Validacion documental

- Este documento existe en `docs/ui-scroll-branding-plan.md`.
- El documento no reemplaza `docs/agent-outputs.md`.
- El documento deja claro que los cambios futuros son UI/UX y presentacion.
- El documento no pide cambios de backend, rutas API, payloads ni logica de dominio.

### Validacion futura de implementacion

Ejecutar dentro de Docker:

```bash
docker compose exec frontend npm install lucide-vue-next
docker compose exec frontend npm run build
```

Validar manualmente:

- `/operations` tiene scroll fluido en desktop y movil.
- `/sandbox` tiene scroll fluido en desktop y movil.
- `/admin` tiene scroll nativo y no queda atrapado en paneles internos.
- En movil, listas y chats fluyen como pagina normal.
- En escritorio, listas/chat con scroll interno no bloquean el resto del flujo.
- La UI visible en espanol no muestra jerga tecnica salvo dentro de **Soporte avanzado**.
- La seccion **Herramientas** muestra las 5 tools MVP.
- Solo `search_inventory` y `search_knowledge` muestran selector de fuente.
- Cada herramienta tiene icono, nombre claro, estado y accion principal.

## Fuentes UX

- GOV.UK Design Principles: https://www.gov.uk/guidance/government-design-principles
- GOV.UK Design System Patterns: https://design-system.service.gov.uk/patterns/
- Material Design 3 Tabs: https://m3.material.io/components/tabs/overview
- Lucide Vue package: https://www.npmjs.com/package/lucide-vue-next

## Supuestos

- El documento nuevo vive separado de `docs/ui-tailwind-plan.md`, pero toma ese plan como contexto visual previo.
- Espanol e ingles se actualizan juntos porque el frontend ya usa i18n.
- Dorix conserva su identidad visual actual salvo decision explicita posterior.
- La experiencia principal es para usuarios no tecnicos.
- Soporte avanzado sigue disponible para diagnostico y administracion tecnica.
