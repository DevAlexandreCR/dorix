# 05 · Rediseño del admin de tenant, pantalla por pantalla

Convenciones usadas abajo:

- `[Editar]` abre edición inline del grupo (SummaryCard expandida) o
  un Drawer si es una entidad de una lista.
- `(?)` = ayuda de una frase visible SIEMPRE bajo el label (no tooltip).
- `◦ chip` = InheritanceChip. `● live` = LiveDot verde.
- Toda pantalla monta: `PanelHeader` → contenido → `DangerZone` (si aplica).

---

## /admin/org/info · Información

```
Admin › Organización › Información
Información de tu negocio
Nombre e identificador con los que opera tu cuenta.

┌ SummaryCard ─────────────────────────────────────────────┐
│ Panadería La Espiga            ● Activa         [Editar] │
│ Identificador: la-espiga  (mono, copiar)                 │
└──────────────────────────────────────────────────────────┘
   ↳ expandida: nombre (input) · identificador (mono, con
     advertencia de que cambia URLs/integraciones) · [Guardar].
     El estado es un badge de solo lectura — nunca editable aquí.
```

- Crear tenants es función de plataforma (`/platform/tenants`).
- DangerZone: "Pausar/Reactivar la organización" (explica: las líneas
  dejan de responder) — es la **única** vía de cambio de estado.

## /admin/org/members · Miembros

```
Admin › Organización › Miembros                [+ Invitar miembro]
Quién puede entrar y qué puede hacer.

┌ DataTable ───────────────────────────────────────────────┐
│ Nombre           Correo              Rol         ⋯       │
│ Ana Ruiz         ana@…               Admin       ⋯       │
│ Luis P.          luis@…              Operador    ⋯       │
└──────────────────────────────────────────────────────────┘
```

- Fila 40 px; menú `⋯`: Cambiar rol / Quitar acceso (confirm).
- Roles con descripción de una frase en el selector:
  "Operador — responde conversaciones, no cambia configuración".
- "+ Invitar miembro" abre Drawer (nombre, correo, contraseña
  temporal, rol con descripciones).

## /admin/connect/lines · Líneas de WhatsApp

```
Admin › Conexiones › Líneas de WhatsApp        [+ Conectar línea]
Los números de WhatsApp por los que tu negocio conversa.

┌ DataTable ───────────────────────────────────────────────┐
│ Línea               Número          Estado       Asistente│
│ Ventas              +57 305 …       ● Conectada  Activo   │
│ Soporte             +57 601 …       ○ Pausada    Heredado ◦│
└──────────────────────────────────────────────────────────┘
```

- Click en fila → **Drawer de línea** con 3 secciones:
  1. **General**: nombre visible (lo demás de solo lectura arriba).
  2. **Datos técnicos de Meta** (colapsado): Phone Number ID, WABA ID
     en `TechValue` mono + copiar. Ayuda: "Los encuentras en Meta
     Business Manager". Solo se piden al conectar.
  3. **Asistente en esta línea**: resumen de herencia
     (`◦ Heredado de la organización` + link "Personalizar →
     /admin/assistant/behavior?line=X").
  - DangerZone del drawer: Pausar línea / Eliminar línea (nombra el
    número y el efecto).
- `is_enabled` checkbox → Switch "Línea activa" con frase de efecto.
- "Conectar línea" = Drawer con los 4 campos de Meta + nombre, con
  ayuda contextual por campo.

## /admin/connect/credentials · Credenciales

```
Admin › Conexiones › Credenciales
Llaves que conectan Dorix con Meta y otros proveedores.
Solo el equipo de la plataforma puede cambiarlas.

┌ DataTable ───────────────────────────────────────────────┐
│ Proveedor   Llave (mono)     Ámbito     Estado      Uso  │
│ Meta        wa_token         Global     ● Configurada 2d │
└──────────────────────────────────────────────────────────┘
```

- Para tenant: 100% lectura + explicación de a quién pedir cambios.
- El upsert de secretos vive en `/platform/credentials`.

## /admin/connect/data · Fuentes de datos

```
Admin › Conexiones › Fuentes de datos            [+ Subir archivo]
Catálogos y documentos que el asistente usa para responder.

┌ DataTable ───────────────────────────────────────────────┐
│ Fuente          Tipo    Estado        Usada por      ⋯   │
│ menu-2026.pdf   PDF     ● Lista       Buscar en menú ⋯   │
│ precios.xlsx    Excel   ⟳ Procesando  —              ⋯   │
└──────────────────────────────────────────────────────────┘
```

- Estados con verbo humano: Lista / Procesando / Falló (+ botón
  Reintentar en la fila cuando falla, con la causa en una frase).
- Drawer de fuente: metadatos legibles (páginas/fragmentos,
  actualizada, intentos) — **sin JSON crudo**; "Usada por" lista las
  herramientas/líneas que la referencian.
- La asignación de fuentes a herramientas vive en
  /admin/assistant/tools (una sola casa por concepto).

## /admin/assistant/behavior · Comportamiento del asistente

La pantalla más crítica del producto.

```
Admin › Asistente › Comportamiento
Cómo responde el asistente en WhatsApp.

Ámbito:  [ Organización ▾ ]   ◦ las líneas heredan esto
─────────────────────────────────────────────────────────────
┌ SummaryCard · Estado ────────────────────────────────────┐
│ ● El asistente responde automáticamente        [Editar]  │
│ "Si lo apagas, los mensajes quedan esperando a tu equipo"│
└──────────────────────────────────────────────────────────┘
┌ SummaryCard · Modelo ────────────────────────────────────┐
│ Equilibrado — recomendado                      [Editar]  │
│ Buen balance entre calidad y costo.                      │
└──────────────────────────────────────────────────────────┘
   ↳ expandida: 3 tarjetas seleccionables (Ahorro /
     Equilibrado / Máxima precisión) con 1 frase + precio
     relativo ($ / $$ / $$$). El ID técnico del modelo va en
     mono, pequeño, dentro de la tarjeta.
┌ SummaryCard · Personalidad e instrucciones ──────────────┐
│ "Eres el asistente de La Espiga…" (140 chars…) [Editar]  │
└──────────────────────────────────────────────────────────┘
   ↳ expandida: textarea con placeholder-ejemplo real y 3
     "recetas" clicables (tono formal / vendedor / soporte).
┌ SummaryCard · Cuando pide ayuda humana ──────────────────┐
│ Mensaje al cliente: "Ya te conecto con…"       [Editar]  │
└──────────────────────────────────────────────────────────┘
▸ Opciones avanzadas (nombre interno, versión de prompt,
  pack de comportamiento)                        ← colapsado
```

**Ámbito por línea** (`[ Organización ▾ ]` → elegir línea):

- Las mismas fichas, pero cada una lleva `InheritanceChip`:
  `◦ Heredado` (muestra el valor de la organización, atenuado) o
  `◦ Personalizado` (+ acción "Restaurar al de la organización").
- Solo se guarda el diff → desaparece el formulario repetido ×N.
- Cambiar de ámbito con cambios sin guardar → confirmación.

## /admin/assistant/tools · Herramientas

```
Admin › Asistente › Herramientas
Lo que el asistente puede hacer además de conversar.

Ámbito:  [ Organización ▾ ]
┌ SettingRow ──────────────────────────────────────────────┐
│ Buscar en el menú/catálogo                    [Switch ✓] │
│ Responde precios y disponibilidad usando tus archivos.   │
│   Fuente: menu-2026.pdf ▾        ▸ avanzado (timeout)    │
├──────────────────────────────────────────────────────────┤
│ Guardar datos del cliente                     [Switch ✓] │
│ Anota nombre y dirección cuando el cliente los da.       │
├──────────────────────────────────────────────────────────┤
│ Pasar con un humano                           [Switch ✓] │
│ Transfiere la conversación a tu equipo cuando hace falta.│
└──────────────────────────────────────────────────────────┘
```

- Nombres de herramienta SIEMPRE en lenguaje de negocio (mapa
  `toolCopyKeys` en i18n; nunca snake_case visible).
- La config anidada (fuente, timeout) aparece indentada solo si el
  switch está encendido (disclosure).
- Ámbito por línea: mismas filas + InheritanceChip, igual que behavior.

## /admin/activity · Actividad

```
Admin › Actividad
Qué ha hecho el asistente y tu equipo.

[ Todos ▾ ] [ Últimas 24h ▾ ] [ Línea ▾ ]        🔍 filtrar
┌ Timeline ────────────────────────────────────────────────┐
│ 14:02  ● Asistente respondió a +57 300… (Ventas)         │
│ 13:58  ⚑ Pasó a humano: "cliente pidió factura"          │
│ 13:40  ⚙ Ana cambió el modelo a Equilibrado              │
└──────────────────────────────────────────────────────────┘
```

- Un solo timeline mezclado (agente + auditoría + herramientas) con
  filtro por tipo, en frases humanas; payload técnico en drawer.

---

## Reglas comunes

- **Guardado**: botón por ficha (no un "Guardar" global); toast de
  confirmación con el mismo verbo; el resumen de la ficha se
  actualiza con el flash sutil (ver 03 §4).
- **Errores**: inline en el campo + resumen arriba de la ficha; nunca
  solo un banner global.
- **Skeletons** por ficha/tabla en carga (no spinner de página).
- **Datos**: composable compartido de feedback (loading/error/éxito)
  y fetch por recurso — nunca un overview completo por vista y por
  mutación.
