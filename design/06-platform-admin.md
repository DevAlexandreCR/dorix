# 06 · Platform admin (super admin) — superficie nueva

La casa del super admin: **/platform**, visible solo con
`canManagePlatform`, separada en el SectionNav bajo un divisor
(identidad visual: mismo sistema Pulso, con eyebrow `PLATAFORMA` y
borde `--border-st`, para que nunca se confunda ámbito global con
ámbito de un tenant).

La API necesaria ya existe: `fetchAdminTenants`, `createTenant`,
`updateTenant`, upsert de credenciales.

## /platform/tenants · Tenants

```
Plataforma › Tenants                          [+ Crear tenant]
Todas las organizaciones de la plataforma.

🔍 buscar por nombre o slug          [ Estado ▾ ]
┌ DataTable ────────────────────────────────────────────────┐
│ Tenant             Slug (mono)   Estado      Creado        │
│ Panadería Espiga   la-espiga     ● Activa    may 26        │
│ Vet Del Prado      del-prado     ○ Pausada   jun 14        │
└───────────────────────────────────────────────────────────┘
```

Columnas: solo los campos que entrega `GET /admin/tenants`
(`serializeTenant`: nombre, slug, estado, creado). Sin contadores de
líneas/miembros — requerirían backend nuevo.

- Click en fila → Drawer de tenant:
  - **Resumen**: nombre, slug (TechValue), estado como badge de solo
    lectura.
  - **Acciones**: "Entrar como admin" → navega a
    `/admin/org/info?tenant=<id>` (siempre disponible: los platform
    admins tienen membresía sintética en todos los tenants).
  - DangerZone: Pausar/Reactivar tenant (efecto explícito: sus líneas
    dejan de responder) — única vía de cambio de estado.
- "+ Crear tenant" = Drawer (nombre, slug autogenerado editable,
  estado inicial). Al crear: toast + fila resaltada, sin salto
  automático de `?tenant=`.

## /platform/credentials · Credenciales

Gestión de llaves de proveedores (el panel del tenant es de solo
lectura). Los endpoints de credenciales son tenant-scoped, así que la
pantalla opera sobre **un tenant a la vez** con un selector propio
siempre visible (el TopBar de `/platform/**` no lleva pill de tenant;
el contexto vive aquí).

```
Plataforma › Credenciales                 [+ Guardar credencial]
Llaves de proveedores del tenant seleccionado.

Tenant:  [ Panadería La Espiga ▾ ]
┌ DataTable ────────────────────────────────────────────────┐
│ Proveedor  Llave (mono)   Ámbito           Estado    Uso  │
│ Meta       wa_token       Global           ● Config.  2d  │
│ Meta       wa_token       Línea: Ventas    ● Config.  1h  │
└───────────────────────────────────────────────────────────┘
```

- Cambiar el tenant del selector recarga la tabla con ese tenant.
- Drawer de upsert (escribe contra el tenant seleccionado): ámbito
  (Global del tenant / Línea →select), proveedor, llave (mono),
  secreto (textarea write-only con aviso "no se vuelve a mostrar").
  Tras guardar, el campo secreto se limpia y nunca se vuelve a
  mostrar.
- El panel del tenant (`/admin/connect/credentials`) queda 100%
  lectura para todos; el platform admin ve ahí un link "Gestionar en
  Plataforma →".

## Regla de separación

Las pantallas de admin de tenant no contienen acciones de plataforma:
crear tenants y gestionar secretos existe únicamente bajo
`/platform/**`. Un platform admin que navega el admin de un tenant ve
exactamente lo que ve un tenant admin, más links "Gestionar en
Plataforma →" donde aplique.

## Futuro (fuera de alcance v1, dejar espacio en el nav)

- `/platform/health`: estado de webhooks/colas por tenant.
- `/platform/usage`: consumo de modelo por tenant (cuando exista API).
