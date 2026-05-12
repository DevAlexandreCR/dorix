# Plan de implementacion frontend: DashStack para SPA Dorix

## Resumen

Este documento es la fuente unica para implementar el diseno DashStack en la SPA existente de Dorix. El alcance es documental: prepara la implementacion visual futura sobre lo que ya existe en `frontend/`, sin agregar dominio, backend, endpoints, payloads ni features nuevas.

Referencias Figma obligatorias:

- Light: https://www.figma.com/design/yLYb3gnDobfSVsufBJ4d2u/DashStack---Free-Admin-Dashboard-UI-Kit---Admin---Dashboard-Ui-Kit---Admin-Dashboard--Community-?node-id=0-11556&t=gAjjWiU43jDEJlte-1
- Dark: https://www.figma.com/design/yLYb3gnDobfSVsufBJ4d2u/DashStack---Free-Admin-Dashboard-UI-Kit---Admin---Dashboard-Ui-Kit---Admin-Dashboard--Community-?node-id=0-29765&p=f&t=wV5hmnFeXybBhROl-0

DashStack se usa como referencia visual y de layout, no como autorizacion para copiar assets, agregar modulos del kit o cambiar contratos de producto.

## Alcance

La implementacion futura debe redisenar solo la SPA ya implementada:

- `/login`
- shell autenticado
- `/operations`
- `/sandbox`
- `/admin`

Fuera de alcance:

- No agregar paginas DashStack inexistentes como products, orders, todo, calendar, invoices o file manager.
- No agregar dominio, WhatsApp, runtime, Excel, panel admin nuevo, endpoints ni payloads.
- No cambiar rutas backend, permisos, enums, query params ni semantica de respuestas API.
- No reemplazar Vue, Tailwind, Vue Router ni Vue I18n.
- No depender de assets exportados del kit Figma; usarlo como guia visual.

## Direccion visual

DashStack Light Style 2 y Dark Style 2 son la referencia principal para:

- fondo de aplicacion claro/oscuro;
- sidebar lateral icon-only;
- topbar superior de baja altura;
- tarjetas con borde suave y sombra contenida;
- tablas densas con headers limpios;
- formularios sobrios con inputs consistentes;
- badges de estado compactos;
- espaciado de dashboard administrativo en 1440px.

El diseno debe sentirse como una herramienta operativa de negocio. Evitar landing pages, heroes, decoracion atmosferica, blobs, gradientes dominantes y pantallas que sugieran capacidades aun no implementadas.

## Shell objetivo

El shell actual basado en `TopBar + SectionNav` debe migrar a un layout DashStack:

- sidebar lateral icon-only en escritorio, con rail aproximado de 84-86px como la referencia;
- topbar superior persistente para identidad, usuario, tenant, idioma, tema y acciones de sesion;
- contenido principal alineado al area restante, sin tarjetas envolventes innecesarias;
- navegacion activa marcada con un indicador vertical y estado de icono;
- mobile/tablet con navegacion compacta equivalente, sin perder tenant, tema, idioma ni logout.

Navegacion permitida en sidebar:

- `operations`
- `sandbox`
- `admin`
- logout y settings solo cuando correspondan a controles existentes

Cualquier icono o item del kit DashStack que represente un modulo no implementado debe omitirse.

## Tema y tokens

Se conserva soporte dark/light y no se elimina `ThemeSwitch`.

La implementacion futura debe alinear `frontend/src/style.css` con DashStack usando las variables existentes siempre que sea razonable:

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

Reglas:

- Light debe seguir la referencia `Stack Admin Light Style 2`.
- Dark debe seguir la referencia `Stack Admin Dark Style 2`.
- Mantener contraste AA para texto principal, controles y estados.
- Reducir el look glassmorphism actual: menos blur/transparencia, mas superficies limpias.
- Botones, inputs, tablas, cards y badges deben compartir radios, bordes y sombras consistentes.

## Componentes base

Adaptar los componentes existentes en lugar de crear un sistema paralelo:

- `SurfaceCard`: card DashStack con borde suave, radio controlado y sombra discreta.
- `DataTable`: headers compactos, filas escaneables, separadores suaves y estados vacios consistentes.
- `StatusBadge`: pills compactas para success, warning, danger y neutral.
- `FormField`: labels claros, hints discretos, inputs de altura consistente y errores visibles.
- `InlineAlert`: alertas contenidas, sin ocupar protagonismo excesivo.
- `EmptyState`: estados sobrios, sin ilustraciones ni contenido de marketing.
- `LoadingState`: loaders discretos que no cambien layout.
- `TenantSelector`: selector integrado a topbar, no como bloque pesado.
- `LocaleSwitch`: control compacto `ES/EN`.
- `ThemeSwitch`: control compacto light/dark, siempre visible cuando hay sesion.

Si se agregan iconos, deben representar acciones o secciones existentes. No usar iconos como unica senal accesible.

## Vistas

### `LoginView`

- Formulario sobrio centrado o alineado a un panel simple.
- Sin hero, landing, marketing copy ni modulos falsos.
- Mantener el flujo de autenticacion actual y los textos i18n existentes salvo ajustes de claridad visual.

### `OperationsView`

- Resolver como inbox operativo: lista/filtros de conversaciones y thread principal.
- En escritorio, usar una composicion de dos areas que recuerde el dashboard/inbox de DashStack.
- Mantener todas las acciones actuales: tomar, reasignar, handoff, respuesta manual y reactivar bot.
- No introducir perfiles, paneles, metricas o acciones que no existan.

### `SandboxView`

- Resolver como consola de prueba tipo chat, con lista de sesiones, thread, composer y detalles tecnicos existentes.
- Mantener el autoscroll y los controles actuales.
- Los detalles tecnicos deben conservarse como soporte/diagnostico, no como contenido dominante.

### `AdminView`

- Usar patrones DashStack de formularios, tablas, tabs y cards densas.
- Mantener paneles existentes: negocio, equipo, WhatsApp, asistente, fuentes, conexiones y actividad.
- Fuentes, herramientas, credenciales y logs deben seguir usando los contratos actuales.
- No agregar dashboards, graficas, ecommerce, file manager ni settings que no existan en la app.

## i18n y copy visible

Conservar ES/EN con `vue-i18n`.

La implementacion futura puede ajustar copy visible solo para alinear la experiencia al rediseño y mejorar claridad. Todo texto nuevo o modificado debe actualizarse en `frontend/src/i18n/locales/es-CO.ts` y `frontend/src/i18n/locales/en.ts` juntos.

Los nombres internos pueden permanecer en codigo, tipos, logs tecnicos y payloads. La UI principal no debe exponerlos como labels dominantes si ya existe una alternativa de negocio.

## Interfaces publicas

No se definen interfaces publicas nuevas.

No se cambian:

- contratos backend;
- rutas API;
- payloads;
- enums;
- query params;
- permisos;
- nombres internos de tools;
- semantica de respuestas.

El documento puede guiar cambios futuros de componentes frontend, pero siempre sobre datos, rutas y capacidades existentes.

## Plan de implementacion futura

1. Actualizar tokens globales y reglas base de `frontend/src/style.css` con light/dark DashStack.
2. Refactorizar shell autenticado para introducir sidebar icon-only y topbar superior.
3. Adaptar componentes base reutilizables a la nueva densidad visual.
4. Redisenar `LoginView`, `OperationsView`, `SandboxView` y `AdminView` sin cambiar contratos.
5. Revisar i18n solo donde el rediseño requiera copy visible mas claro.
6. Validar responsive, tema dark/light y accesibilidad basica.

## Validacion futura

Cuando se implemente el rediseño en codigo, validar dentro de Docker:

```bash
docker compose exec frontend npm run build
```

Verificacion manual minima:

- `http://localhost:5173/login`
- `http://localhost:5173/operations`
- `http://localhost:5173/sandbox`
- `http://localhost:5173/admin`

Criterios:

- Light y dark se ven alineados con los links Figma.
- Sidebar no muestra modulos inexistentes.
- Topbar conserva usuario, tenant, idioma, tema y logout.
- La SPA mantiene rutas, permisos y query params actuales.
- No se agregan datos mock como si fueran producto real.

## Validacion documental de este cambio

- Solo cambian archivos `.md`.
- Este archivo contiene ambos links Figma.
- No quedan documentos de diseno anteriores como fuentes competidoras.
- La unica fuente documental vigente para el rediseño frontend es este archivo.

## Supuestos

- La implementacion real del diseno ocurrira despues, fuera de este cambio documental.
- La inspeccion de Figma confirmo paginas separadas para `Stack Admin Light Style 2` y `Stack Admin Dark Style 2`.
- DashStack se adopta como referencia visual y de layout, no como paquete de componentes ni mapa de features.
