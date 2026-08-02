# 04 · Arquitectura de información y navegación

## 1. Mapa global

```
┌────────────────────────────────────────────────────────────────┐
│ SectionNav (220px, colapsable 64px)     TopBar                 │
│                                                                │
│  ● Operaciones      /operations         título + tenant pill   │
│  ● Sandbox          /sandbox            + AvatarMenu           │
│  ● Administración   /admin/…                                   │
│  ─────────────                                                 │
│  ◆ Plataforma       /platform/…   ← solo canManagePlatform     │
│                                                                │
│  <lg: BottomNav (3–4 iconos, 56px)                             │
└────────────────────────────────────────────────────────────────┘
```

Shell: sidebar de 220 px colapsable a 64, TopBar de una fila,
BottomNav móvil y AvatarMenu, todo con tokens Pulso. La sección
**Plataforma** (separada en el nav con un divisor y estilo propio)
aparece solo con `canManagePlatform`.

## 2. Admin de tenant — estructura de 4 grupos (se conserva)

```
/admin                        → redirect al primer grupo permitido
/admin/org/info               Organización · Información
/admin/org/members            Organización · Miembros
/admin/connect/lines          Conexiones  · Líneas de WhatsApp
/admin/connect/credentials    Conexiones  · Credenciales
/admin/connect/data           Conexiones  · Fuentes de datos
/admin/assistant/behavior     Asistente   · Comportamiento
/admin/assistant/tools        Asistente   · Herramientas
/admin/activity               Actividad
```

`/admin` sin sub-ruta redirige al primer panel permitido según el
orden fijo `ADMIN_FALLBACK_ORDER` (`modules/admin/router.ts`). No hay
soporte de URLs con `?panel=`.

### AdminNav (sidebar secundario del admin)

```
┌─────────────────────────┐
│ 🔍 Buscar ajustes…      │  ← fuzzy: busca en títulos, labels y
├─────────────────────────┤     ayudas de TODOS los paneles; el
│ ORGANIZACIÓN            │     resultado navega y resalta la fila
│   Información           │
│   Miembros              │
│ CONEXIONES              │
│   Líneas de WhatsApp    │
│   Credenciales          │
│   Fuentes de datos      │
│ ASISTENTE               │
│   Comportamiento        │
│   Herramientas          │
│ ACTIVIDAD               │
└─────────────────────────┘
```

- Grupos sin permiso: **ocultos** (no deshabilitados) — regla vigente.
- `<lg`: drill-down de pantalla completa (lista de grupos → lista de
  paneles), no dropdown-popover como está hoy diseñado.
- La búsqueda de ajustes es el atajo para "las configuraciones son muy
  extensas": escribir "horario", "modelo", "teléfono" lleva directo.

## 3. Plataforma (nuevo — ver 06-platform-admin.md)

```
/platform                     → redirect a /platform/tenants
/platform/tenants             Tabla global de tenants (detalle en drawer)
/platform/credentials         Secretos por proveedor, por tenant
```

Los bloques inline que hoy viven en pantallas de tenant se mudan aquí:
"crear tenant" sale de `/admin/org/info`, el upsert de secretos sale
de `/admin/connect/credentials` (que queda de solo lectura para el
tenant, como ya es para no-platform).

## 4. Permisos

Cada ruta declara `meta.requires` con claves de `useNavigationAccess`
(semántica OR). El guard del router evalúa esas claves en cada
navegación y AdminNav filtra con la misma tabla, de modo que nav y
guard no pueden divergir. Sin permiso → `ForbiddenState`.

| Ruta | requires |
|---|---|
| `/admin/org/info` | `canManageTenant` |
| `/admin/org/members` | `canManageTenantUsers` |
| `/admin/connect/lines` | `canManageTenant` |
| `/admin/connect/credentials` | `canViewCredentialMetadata` |
| `/admin/connect/data` | `canManageTenant` |
| `/admin/assistant/behavior` | `canManageAgentConfig` |
| `/admin/assistant/tools` | `canManageAgentConfig` |
| `/admin/activity` | `canManageTenant` |
| `/platform/**` | `canManagePlatform` |

## 5. Encabezado de panel

`PanelHeader`: breadcrumb + h1 + una frase de contexto + slot de
**acciones de página** a la derecha (ej. "Conectar línea"), para que
los botones primarios de creación no vivan al fondo del scroll.

## 6. Tenant en la URL

`?tenant=<id>` se mantiene (los links preservan query). El TopBar
muestra el tenant como pill siempre; el selector aparece con >1
membresía. En Plataforma, el TopBar muestra `Plataforma` en lugar del
pill de tenant.
