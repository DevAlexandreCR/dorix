# 07 · Patrones UX transversales

Los cinco patrones que hacen que "cualquier persona sepa qué hace cada
cosa". Son la firma del sistema; se aplican igual en tenant admin y
platform admin.

## 1. Resumen primero (`SummaryCard`)

Toda configuración se presenta como ficha en dos estados:

```
Colapsada (default)                 Expandida (al editar)
┌───────────────────────────┐      ┌───────────────────────────┐
│ Modelo                    │      │ Modelo                    │
│ Equilibrado — recomendado │  →   │ ( ) Ahorro        $       │
│ Buen balance calidad/costo│      │ (•) Equilibrado   $$  ✔   │
│                  [Editar] │      │ ( ) Máx.precisión $$$     │
└───────────────────────────┘      │ claude-… (mono)           │
                                   │ [Cancelar]  [Guardar]     │
                                   └───────────────────────────┘
```

Reglas:
- La frase de resumen se genera del estado real y usa lenguaje del
  negocio ("Responde automáticamente", no "automation_enabled=true").
- Guardar es por ficha; al guardar, la ficha colapsa y el resumen hace
  el flash sutil.
- Una pantalla es una columna de fichas (max-width ~720 px); nunca
  grid de inputs sueltos.

## 2. Herencia visible (`InheritanceChip`)

Modelo mental: **la organización define el default, la línea puede
personalizar**. La UI lo dice, no lo repite:

```
Ámbito: [ Línea: Ventas ▾ ]
┌ Personalidad e instrucciones ────────────────────────────┐
│ ◦ Heredado de la organización                            │
│ "Eres el asistente de La Espiga…" (atenuado)             │
│                                        [Personalizar]    │
└──────────────────────────────────────────────────────────┘
┌ Modelo ──────────────────────────────────────────────────┐
│ ◦ Personalizado en esta línea    [Restaurar al general]  │
│ Máxima precisión                              [Editar]   │
└──────────────────────────────────────────────────────────┘
```

- Solo se persiste el diff (endpoints `updateLineAgentConfig` /
  `deleteLineAgentConfig` ya existen; "Restaurar" = delete override).
- El selector de ámbito emite `request-switch` antes de cambiar para
  interceptar cambios sin guardar (contrato del ScopePicker).

## 3. Ayuda en el flujo, no en tooltip

Jerarquía de explicación por control:

1. **Label** = qué es, en palabras del negocio.
2. **Frase de ayuda** (siempre visible, `text-small text-mute`) = qué
   pasa si lo cambias. Máx ~90 caracteres.
3. **Ejemplo** (solo cuando el efecto no es obvio): placeholder real o
   link "ver ejemplo" que expande.
4. **Detalle técnico** (`TechValue` mono + copiar) solo si el usuario
   necesita copiar/pegar el valor en otro sistema.

Prohibido: tooltips como única explicación; jerga (`prompt_version`,
`WABA`) sin traducción al lado; párrafos de más de 2 líneas.

## 4. Zona de peligro (`DangerZone`)

```
┌ Zona de peligro ─────────────────────────── borde --danger ┐
│ Eliminar la línea Ventas                                   │
│ El número +57 305… dejará de responder mensajes.           │
│                                        [Eliminar línea]    │
└────────────────────────────────────────────────────────────┘
```

- Siempre al final de la pantalla/drawer, nunca mezclada con ajustes.
- La confirmación (UiModal) nombra la entidad y el efecto real, y el
  botón repite el verbo ("Eliminar línea", no "Aceptar").
- Pausar ≠ eliminar: ofrecer siempre la opción reversible primero.

## 5. Estados del sistema en frases

| Situación | Patrón |
|---|---|
| Cargando | Skeleton con la forma del contenido (no spinner global) |
| Vacío | EmptyState = invitación: "Aún no has conectado líneas. Conecta la primera para que el asistente empiece a responder." + botón |
| Error de carga | Qué falló + [Reintentar]; sin códigos crudos |
| Error de campo | Bajo el campo, en rojo, con cómo corregir |
| Guardado OK | Toast "Línea guardada" (mismo verbo del botón) |
| Procesando (fuentes) | Estado con verbo + qué esperar ("Procesando… suele tardar 1-2 min") |
| Sin permiso | ForbiddenState traducido + a quién pedir acceso |

## 6. Búsqueda de ajustes

Input fuzzy fijo arriba del AdminNav. Indexa: títulos de panel, labels
y frases de ayuda (i18n). Seleccionar un resultado navega al panel y
hace scroll + resalta la ficha 2 s. Atajo `/` enfoca la búsqueda.

## 7. Copy — reglas rápidas

- Sentence case, voz activa, verbos exactos: "Conectar línea",
  "Guardar cambios", "Quitar acceso".
- El mismo verbo vive en botón → confirmación → toast.
- Nunca nombres del sistema: "webhook config" → "conexión con Meta".
- es_CO primero; en.ts es traducción, no fuente.
- Los estados enumerados usan los mapas i18n existentes
  (`common.lineStatus`, `dataSourceStatuses`…); renombrar un estado
  exige actualizar esos mapas.
