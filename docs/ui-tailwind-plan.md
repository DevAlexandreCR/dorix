# Plan UI SPA con Tailwind, tema dark/light e idioma ES/EN

## Objetivo

Definir un plan ejecutable para rediseñar toda la SPA de `frontend/` con foco en UX empresarial, simplicidad, legibilidad y responsive desktop-first. El alcance cubre la migración a Tailwind CSS v4, la introducción de navegación por URL con `vue-router`, soporte de tema dark/light con override manual persistido, selector visible de idioma ES/EN y un refactor profundo para sacar la UI del `App.vue` actual hacia una estructura escalable por rutas, módulos y componentes reutilizables.

## Estado actual observado

### Baseline técnico

- `frontend/package.json` solo declara `vue` y `vue-i18n`; no existe `tailwindcss`, `@tailwindcss/vite` ni `vue-router`.
- `frontend/src/main.ts` monta `App.vue` e `i18n` directamente; no hay router, layout root ni bootstrap de preferencias globales.
- `frontend/src/i18n/index.ts` ya soporta `es-CO` y `en`, pero la selección depende de autodetección por `navigator` y no existe selector visible ni persistencia manual.

### Estructura actual de UI

- `frontend/src/App.vue` tiene `2628` líneas y concentra autenticación, selección de tenant, navegación entre áreas, operaciones, sandbox, admin, estados de carga/error y formularios.
- La navegación actual depende de `workspaceSection` en memoria (`operations`, `sandbox`, `admin`) y no de rutas URL.
- Los módulos en `frontend/src/modules/*` ya separan `api.ts` y `types.ts`, pero la capa de presentación todavía no está distribuida por vistas ni componentes del módulo.

### Sistema visual actual

- `frontend/src/style.css` tiene `698` líneas con CSS global artesanal, variables CSS propias y utilidades locales.
- La identidad actual combina acentos cálidos con una base oscura y superficies translúcidas; el look dominante es cercano a glassmorphism.
- No existe sistema centralizado de tokens conectado a un framework utilitario ni estrategia formal por tema.

### Conclusión del gap

El frontend ya tiene lógica funcional y contratos API separados por módulo, pero la experiencia sigue acoplada a un único SFC, con navegación no preservable por URL, i18n sin control explícito del usuario y un sistema visual difícil de escalar. El trabajo requerido es principalmente de arquitectura de UI, shell, layout, componentes, estilos y experiencia, no de backend ni contratos.

## Decisiones cerradas

- El documento final vive en `docs/ui-tailwind-plan.md`.
- El alcance cubre toda la SPA actual: login, shell, operations, sandbox y admin.
- Se permite refactor profundo del frontend para salir del `App.vue` actual.
- No se cambian contratos API, payloads ni lógica de dominio backend.
- Se adopta `vue-router` como parte del rediseño actual, no como mejora futura.
- Se mantiene `vue-i18n` y se expande a shell, navegación, acciones globales y estados vacíos.
- La paleta de acento actual se conserva como identidad de marca; cambian neutrales, contraste y superficies por tema.
- La experiencia es desktop-first, pero completamente responsive en tablet y móvil.
- El estilo buscado es sobrio y corporativo, con densidad media, jerarquía tipográfica clara y menos peso visual de blur/transparencias.
- Las preferencias de tema e idioma deben poder sobrescribir el valor inicial y persistir en almacenamiento local.

## Alcance y fuera de alcance

### Incluido

- Migración del sistema visual a Tailwind CSS v4.
- Nuevo shell con rutas, navegación, top bar y componentes base reutilizables.
- Reorganización visual completa de login, operations, sandbox y admin.
- Persistencia de tema e idioma.
- Repartición de la UI en vistas, layouts, componentes, composables y utilidades.

### Excluido

- Cambios de dominio, permisos backend o contratos API.
- Cambio de payloads, endpoints o semántica de respuestas.
- Introducción de lógica nueva de WhatsApp, runtime, Excel o panel admin más allá de la reorganización visual.
- Incorporación de un state manager nuevo si no resulta estrictamente necesario.

## Dependencias nuevas

- `tailwindcss` v4 como base del sistema visual.
- `@tailwindcss/vite` como integración oficial con Vite.
- `vue-router` v4 para navegación principal por URL.

Notas:

- No introducir librerías de componentes pesadas; la intención es construir un sistema propio liviano sobre Tailwind.
- Mantener `vue-i18n` como solución de localización actual.
- Reusar las APIs y tipos ya existentes en `frontend/src/modules/*`.

## Principios de diseño

- Desktop-first: priorizar productividad en escritorio y luego degradar con orden en tablet y móvil.
- Legibilidad primero: contraste claro, tipografía sobria, formularios y tablas fáciles de escanear.
- Densidad controlada: evitar pantallas vacías y también evitar saturación visual.
- Consistencia: estados, badges, acciones, espacios y paneles deben responder al mismo sistema.
- Estabilidad: navegación, tenant, idioma y tema deben estar siempre visibles y predecibles.
- URL como fuente de estado navegable: filtros, selección de vistas y detalle deben ser preservables por ruta o query string.

## Estructura objetivo del frontend

```text
frontend/src/
  app/
    AppShell.vue
    providers/
      theme.ts
      locale.ts
      session.ts
  router/
    index.ts
    guards.ts
    routes.ts
  layouts/
    AuthLayout.vue
    WorkspaceLayout.vue
  components/
    shell/
      TopBar.vue
      TenantSelector.vue
      ThemeSwitch.vue
      LocaleSwitch.vue
      SectionNav.vue
    ui/
      SurfaceCard.vue
      StatusBadge.vue
      EmptyState.vue
      InlineAlert.vue
      DataTable.vue
      FormField.vue
      LoadingState.vue
      ForbiddenState.vue
  composables/
    useTheme.ts
    useLocale.ts
    useSession.ts
    useTenantSelection.ts
    useNavigationAccess.ts
  modules/
    auth/
      views/LoginView.vue
    operations/
      views/OperationsView.vue
      components/
    sandbox/
      views/SandboxView.vue
      components/
    admin/
      views/AdminView.vue
      components/
  i18n/
    index.ts
    locales/
      es-CO.ts
      en.ts
  style.css
```

### Rutas objetivo

- `/login`
- `/operations`
- `/sandbox`
- `/admin`

### Reglas de routing

- `/login` usa layout de autenticación.
- `/operations`, `/sandbox` y `/admin` usan layout de workspace compartido.
- Las rutas autenticadas validan sesión antes de renderizar.
- El acceso a sandbox y admin debe respetar permisos ya derivados de la sesión actual.
- Cuando un usuario no tenga acceso a una ruta, la app debe renderizar estado consistente de permisos restringidos o redirigir según la situación, no dejar áreas vacías.

### Estado navegable por URL

- `tenant` debe poder reflejarse en query string si no rompe el flujo actual.
- `operations` debe preservar filtros y selección de conversación por URL.
- `sandbox` debe preservar la sesión abierta por URL.
- `admin` debe preservar subsección o panel activo por URL o tabs navegables.

## Shell global objetivo

El shell reemplaza el uso actual de `App.vue` como contenedor de todo. Debe proveer estructura estable y mínima para que las vistas solo resuelvan su contenido funcional.

### Responsabilidades del shell

- header superior persistente;
- navegación principal por secciones;
- selector de tenant visible;
- switch de tema visible;
- switch de idioma visible `ES / EN`;
- acción de logout visible;
- manejo de estados globales de sesión;
- superficie consistente para cargas globales, errores y permisos.

### Comportamiento esperado

- En escritorio, la navegación y controles globales viven en la parte superior con agrupación clara.
- En móvil, la navegación colapsa en un patrón compacto sin ocultar tema, idioma, tenant ni logout.
- El shell no contiene la lógica detallada de cada módulo; solo orquesta layout, estado global y acceso.

## Sistema visual con Tailwind

### Objetivo del sistema visual

Pasar de CSS global artesanal a un sistema basado en tokens y utilidades, con Tailwind como base y variables CSS como fuente de verdad para identidad y tematización.

### Estrategia

- Mantener `frontend/src/style.css` como punto de entrada del sistema, pero reducirlo a import de Tailwind, definición de tokens CSS, reglas base del documento y ajustes muy puntuales fuera del alcance natural de utilidades.
- Usar Tailwind para layout, espaciado, tipografía, bordes, estados y composición de pantallas.
- Evitar volver a crear un catálogo grande de clases semánticas ad hoc.

### Tokens de diseño requeridos

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

### Criterios visuales

- Conservar acentos actuales como identidad de marca.
- Reemplazar el protagonismo de blur y transparencias por superficies más limpias y contrastes más controlados.
- Definir escalas consistentes de espaciado, radios, sombras y tipografía.
- Tablas, badges, inputs y paneles deben compartir reglas de densidad y contraste.

## Estrategia de tema dark/light

### Objetivo

Soportar tema oscuro y claro sin recarga, con valor inicial por preferencia del sistema y override manual persistido.

### Decisiones

- El tema activo se representará en el root del documento con un atributo estable, por ejemplo `data-theme="dark"` o `data-theme="light"`.
- La preferencia persistida se guardará en `localStorage`.
- Si no existe preferencia persistida, el valor inicial saldrá de `prefers-color-scheme`.
- El switch de tema vive en el shell global y debe estar disponible en todas las rutas autenticadas.
- El tema debe aplicarse antes del mount principal para evitar flash visual incorrecto.

### Flujo propuesto

1. Leer preferencia persistida en bootstrap.
2. Si no existe, resolver con `matchMedia('(prefers-color-scheme: dark)')`.
3. Aplicar `data-theme` al `document.documentElement`.
4. Montar la app con el tema ya resuelto.
5. Permitir toggle manual y persistir inmediatamente.

### Resultado esperado

- El modo claro no es una inversión pobre del oscuro; tiene neutrales propios y contraste suficiente.
- El modo oscuro conserva la personalidad actual, pero con menor dependencia de glassmorphism.
- Estados `success`, `warning`, `danger` y overlays son consistentes en ambos temas.

## Estrategia de i18n ES/EN

### Objetivo

Mantener `vue-i18n`, dejar español como idioma por defecto del producto y hacer explícita la selección de idioma mediante switch visible y persistido.

### Decisiones

- Idioma por defecto: `es-CO`.
- Idiomas soportados: `es-CO` y `en`.
- El selector debe mostrarse como `ES / EN` en el shell global.
- La selección se persiste en `localStorage`.
- La autodetección por navegador deja de ser la fuente principal de UX; como máximo puede usarse en primer arranque si el producto decide no fijar español absoluto, pero la decisión por defecto de este plan es iniciar en `es-CO`.

### Alcance de traducción

- shell global;
- navegación principal;
- acciones de tema e idioma;
- login;
- operations;
- sandbox;
- admin;
- estados vacíos;
- estados de carga, error, éxito y permisos.

### Ajustes estructurales

- Mantener `frontend/src/i18n/index.ts` como punto de bootstrap.
- Reorganizar mensajes si hace falta para que reflejen shell, rutas y módulos, sin mezclar nuevas claves dentro de un archivo inmanejable.
- Exponer un composable `useLocale()` para lectura, cambio y persistencia.

## Rediseño por módulo

### Login

#### Objetivo

Reducir el login a una experiencia clara, rápida y con foco total en acceso.

#### Lineamientos

- Tarjeta simple, centrada y con copy corto.
- Jerarquía clara de título, ayuda y error.
- Inputs, labels y CTA con contraste consistente.
- Validaciones visibles sin saturar la pantalla.
- Mantener acceso al branding y al estado general del producto sin competir con el formulario.

### Operations

#### Objetivo

Mantener productividad operativa y lectura rápida de contexto, especialmente en escritorio.

#### Lineamientos

- Layout principal de lista + detalle en escritorio.
- Filtros compactos, visibles y persistibles por URL.
- Encabezado del thread con estado, dueño, handoff y contexto resumido.
- Acciones críticas agrupadas, con sticky cuando ayude a la productividad.
- Mensajes con jerarquía clara de dirección, timestamp, origen y estado.
- Estados vacío/error/loading consistentes con el resto de la app.

#### Decisión de layout

- Desktop: dos columnas persistentes.
- Tablet: prioridad al detalle con lista comprimible o apilada.
- Móvil: paneles apilados o navegación por selección explícita, sin perder acciones clave.

### Sandbox

#### Objetivo

Hacer más legible la separación entre sesiones, metadata operativa y conversación.

#### Lineamientos

- Panel claro para crear sesión.
- Lista de sesiones con status y última actividad.
- Vista de detalle con separación evidente entre metadata de sesión, resultados del último turno, mensajes y acciones.
- Evitar mezclar controles de creación, ejecución y trazabilidad en el mismo bloque visual.

### Admin

#### Objetivo

Reducir ruido visual y volver más escaneables los formularios y listados largos.

#### Lineamientos

- Reorganizar áreas extensas en secciones, tabs o paneles consistentes.
- Agrupar por intención: tenant, usuarios, líneas, agent config, tool bindings, credenciales, data sources, logs.
- Formularios largos divididos en bloques con títulos y ayuda breve.
- Tablas/listados con columnas estables, badges claros y acciones predecibles.
- Estados de guardado, error y éxito visibles sin romper el flujo.

## Componentes e interfaces nuevas

### Componentes base esperados

- `AppShell`
- `TopBar`
- `ThemeSwitch`
- `LocaleSwitch`
- `TenantSelector`
- `SectionTabs` o `SectionNav`
- `SurfaceCard`
- `StatusBadge`
- `EmptyState`
- `InlineAlert`
- `DataTable`
- `FormField`

### Composables y utilidades esperadas

- `useTheme`
- `useLocale`
- `useShellLayout`
- `useNavigationAccess`
- `useTenantSelection`
- utilidades de sincronización entre estado UI y ruta

### Interfaces públicas nuevas

- rutas SPA navegables por URL;
- preferencia persistida de tema;
- preferencia persistida de idioma;
- tokens de diseño centralizados para Tailwind;
- shell reutilizable para áreas autenticadas.

## Plan de implementación sugerido

### Fase 1. Bootstrap visual y routing

- agregar `tailwindcss`, `@tailwindcss/vite` y `vue-router`;
- integrar plugin de Tailwind en `vite.config.ts`;
- reemplazar el CSS base para importar Tailwind y definir tokens;
- crear `router/index.ts` y rutas de alto nivel;
- convertir `main.ts` para montar `App.vue` con router + i18n;
- reducir `App.vue` a un root mínimo con `RouterView`.

### Fase 2. Shell y preferencias globales

- crear `WorkspaceLayout` y `AuthLayout`;
- extraer `TopBar`, `TenantSelector`, `ThemeSwitch` y `LocaleSwitch`;
- implementar `useTheme` con persistencia y bootstrap sin flash;
- implementar `useLocale` con persistencia y default `es-CO`;
- mover la selección de sección desde estado local a navegación por ruta.

### Fase 3. Migración de Operations

- crear `OperationsView.vue`;
- separar lista, detalle, acciones y estados auxiliares en componentes del módulo;
- mover filtros y selección a estado sincronizado con URL;
- preservar lógica existente de `modules/operations/api.ts`.

### Fase 4. Migración de Sandbox

- crear `SandboxView.vue`;
- separar creación de sesión, lista, metadata y conversación;
- sincronizar sesión seleccionada con URL;
- preservar lógica existente de `modules/sandbox/api.ts`.

### Fase 5. Migración de Admin

- crear `AdminView.vue`;
- dividir panel monolítico en subsecciones navegables o tabs consistentes;
- extraer tablas, formularios y bloques de configuración reutilizables;
- preservar lógica existente de `modules/admin/api.ts`.

### Fase 6. Pulido transversal

- homogeneizar estados vacíos, loading, error, éxito y permisos;
- revisar responsive real de escritorio, tablet y móvil;
- ajustar contraste de ambos temas;
- completar traducciones nuevas de shell y componentes compartidos;
- eliminar estilos legacy que ya no tengan uso.

## Criterios de aceptación

- `frontend/` compila con Tailwind, Router y la nueva estructura sin errores.
- La SPA carga un shell consistente en `http://localhost:5173`.
- Existen rutas navegables por URL para `/login`, `/operations`, `/sandbox` y `/admin`.
- El usuario puede cambiar entre dark/light sin recarga.
- La preferencia de tema persiste al recargar y al volver a abrir la app.
- El usuario puede cambiar entre `es-CO` y `en` sin recarga.
- La preferencia de idioma persiste al recargar y al volver a abrir la app.
- El modo claro mantiene contraste y legibilidad sin perder el accent branding actual.
- `Operations` conserva productividad desktop con vista de lista + detalle.
- En tablet y móvil, paneles y acciones se apilan o colapsan sin romper formularios ni tablas.
- Estados de carga, error, vacío, éxito y permisos restringidos son consistentes en las cuatro áreas.
- No se alteran contratos backend ni payloads.

## Validación

- `docker compose exec frontend npm run build`
- `docker compose exec frontend npm run typecheck`
- abrir `http://localhost:5173`
- navegar por URL entre `/login`, `/operations`, `/sandbox` y `/admin`
- cambiar tema y verificar persistencia
- cambiar idioma y verificar persistencia
- revisar layout desktop en operations con lista + detalle
- revisar responsive en tablet y móvil

## Checklist de implementación

- [ ] Agregar dependencias `tailwindcss`, `@tailwindcss/vite` y `vue-router`.
- [ ] Configurar plugin oficial de Tailwind en Vite.
- [ ] Sustituir la base visual artesanal por tokens + utilidades Tailwind.
- [ ] Reducir `App.vue` a root con `RouterView`.
- [ ] Crear layouts `AuthLayout` y `WorkspaceLayout`.
- [ ] Implementar `AppShell`, `TopBar`, `ThemeSwitch`, `LocaleSwitch` y `TenantSelector`.
- [ ] Implementar rutas `/login`, `/operations`, `/sandbox`, `/admin`.
- [ ] Implementar guards de sesión y acceso por permisos.
- [ ] Implementar persistencia de tema en `localStorage`.
- [ ] Implementar persistencia de idioma en `localStorage`.
- [ ] Reorganizar `i18n` para cubrir shell y componentes compartidos.
- [ ] Migrar operations a vista y componentes propios.
- [ ] Migrar sandbox a vista y componentes propios.
- [ ] Migrar admin a vista y componentes propios.
- [ ] Unificar estados vacíos, loading, error, éxito y permisos.
- [ ] Validar desktop, tablet y móvil.
- [ ] Ejecutar `docker compose exec frontend npm run build`.
- [ ] Ejecutar `docker compose exec frontend npm run typecheck`.

## Riesgos y mitigaciones

- Riesgo: migración grande desde un `App.vue` monolítico puede romper flujos por regresiones de estado.
  Mitigación: mover módulo por módulo y preservar las APIs actuales sin reescribir contratos.

- Riesgo: el tema puede parpadear al cargar si la preferencia se resuelve tarde.
  Mitigación: aplicar preferencia en bootstrap antes del mount.

- Riesgo: las rutas pueden duplicar estado que hoy vive en refs locales.
  Mitigación: definir desde el inicio qué vive en query string y qué sigue siendo estado efímero de la vista.

- Riesgo: admin puede seguir viéndose pesado si solo se “trocea” sin rediseño.
  Mitigación: reorganizar por intención y no solamente por archivo.
