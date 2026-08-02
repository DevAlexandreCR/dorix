# 01 · Diagnóstico de la UI actual

Auditoría de `frontend/src` (2026-08-02). 9,229 LOC, 35 componentes
Vue, 12 vistas. Tailwind v4, sin librería de componentes, sin tests de
frontend.

## 1. El admin está funcionalmente roto hoy

Una reestructuración del admin quedó a medio camino en el código y
dejó cuatro roturas activas:

1. **Las 8 sub-rutas de `/admin` no renderizan sus vistas.**
   `AdminView.vue` es el componente padre de todas pero no tiene
   `<RouterView/>`. `InfoView`, `MembersView`, `LinesView` y
   `CredentialsView` están escritas pero son inalcanzables: la URL
   cambia y el contenido sigue siendo el panel legacy de 7 tabs.
2. **`AdminNav.vue` (357 líneas, el sidebar agrupado nuevo) no lo
   importa nadie.** Código muerto.
3. **Faltan todas las claves i18n nuevas** (`admin.nav.*`,
   `admin.org.*`, `admin.connect.*`, …) en `en.ts` y `es-CO.ts`.
4. **`meta.requires` nunca se evalúa** en `router/guards.ts`. El
   gating por permisos de las sub-rutas no existe; solo
   `CredentialsView` se auto-protege.

## 2. Densidad y sobrecarga cognitiva

`AdminView.vue` tiene **1,853 líneas (20% de todo el frontend)** y
concentra 7 paneles vía `?panel=`. Controles editables por pantalla
con N líneas de WhatsApp y T herramientas:

| Pantalla | Controles | Con 3 líneas |
|---|---|---|
| Organización | 3 (+3 platform) | 6 |
| Miembros | 1×miembro + 4 | ~10 |
| Líneas | 6 × (N+1) | 24 |
| **Agente** | **9 × (N+1)** | **36** |
| Fuentes/herramientas | 2 + 3×T×(N+1) | 26 |
| Credenciales | 5 | 5 |

Todo se renderiza plano, expandido y con el mismo peso visual: un
nombre de tenant pesa lo mismo que un `prompt_version`. No hay tablas
(las entidades repetidas son `<article>` bordeados siempre expandidos),
no hay drill-down, y el único progressive disclosure es `<details>`
"Opciones avanzadas". El formulario de agente **se repite completo por
cada línea** en vez de mostrar solo las diferencias.

## 3. No existe platform admin

`platform.manage` solo habilita bloques inline dentro de pantallas de
tenant: crear tenant (en Organización), y upsert de secretos (en
Credenciales). `fetchAdminTenants()` se usa únicamente para pintar un
contador. No hay lista de tenants, ni vista cross-tenant, ni gestión
global. El cambio de tenant es un `<select>` en el TopBar visible solo
con >1 membresía.

## 4. Lenguaje visual inconsistente

Tres idiomas de estilo conviven:

- `:style="{ borderColor: 'var(--border)' }"` inline (~60 veces solo
  en AdminView).
- Clases arbitrarias Tailwind con tokens (`text-[var(--text-mute)]`).
- Radios pre-migración (`rounded-[24px]`, `rounded-[20px]`,
  `rounded-[18px]`) mezclados con la escala nueva (`rounded-lg`).

Faltan primitivas: no hay componente Button, Modal, Select, Tabs,
Toast ni Popover unificado (el outside-click está duplicado 3 veces).
`DataTable.vue` se construyó y nadie lo importa. `FormField` no maneja
errores ni `id` accesible.

## 5. La paleta actual es el "default IA"

"Sabio Cálido" (crema `#FAF7F0` + verde salvia) coincide con el look
genérico #1 que produce la IA por defecto (crema cálida + acento
verde/terracota). No comunica nada del sujeto (WhatsApp, negocio,
conversaciones) y el usuario ya la identificó como "el mismo diseño de
siempre".

## 6. Datos: refetch total en cada mutación

Cada sub-vista llama `fetchAdminOverview()` (payload completo: tenant
+ usuarios + líneas + credenciales + configs + fuentes + 3 streams de
logs) al montar y tras **cada** guardado. El estado
loading/error/success se re-declara en cada vista.

## 7. Lo que sí está bien y se conserva

- La IA de 4 grupos (`org / connect / assistant / activity`) con
  sub-rutas y redirects legacy: es correcta, solo está sin terminar.
- El mapa único permiso→ruta (`ADMIN_ROUTE_REQUIRES`) compartido entre
  nav y guard.
- Tokens CSS con dark/light por `data-theme`, tipografía por clases
  utilitarias, Lucide icons.
- El patrón `xxxLabel`/`xxxHelp` en i18n con ayuda en lenguaje simple.
- Convención de módulos (`api.ts`, `types.ts`, `views/`).
