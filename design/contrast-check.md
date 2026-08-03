# Contrast check — Pulso design tokens

Medición de contraste WCAG 2.1 (relative luminance) de todos los pares
foreground/background que ocurren realmente en la UI (texto sobre
fondo, estado sobre fondo, borde/componente-no-textual sobre fondo),
en ambos temas. Umbrales: **4.5:1** texto normal, **3:1** texto
grande (≥18.66px bold o ≥24px) y componentes no-textuales/bordes/
indicadores de foco (WCAG 1.4.11).

## Metodología

Los valores hex de cada token se parsean directamente de
`frontend/src/style.css` (bloques `:root` y
`:root[data-theme="dark"]`) — no se transcriben a mano. Los colores
derivados que usan los componentes reales vía
`color-mix(in srgb, X P%, Y)` (fondos de `StatusBadge`, `InlineAlert`,
`.btn-danger`) se recalculan con la misma fórmula de interpolación
sRGB por canal que usa CSS Color 4, para medir el fondo/borde
*efectivamente renderizado* y no solo el token semántico crudo.

El ratio de contraste usa la fórmula estándar WCAG:
`(L1 + 0.05) / (L2 + 0.05)` sobre luminancia relativa sRGB
(coeficientes 0.2126/0.7152/0.0722, con la curva de linealización
gamma estándar).

Script: `contrast_check.py` (ver referencia de contenido más abajo,
ejecutado contra el `style.css` real del repo). Se puede reproducir
con:

```bash
python3 contrast_check.py
```

Sale con código 0 si todos los pares pasan, 1 si alguno falla.

## Resultado

**86 pares medidos en total (43 por tema), 0 fallando** tras el
ajuste de tokens descrito en "Tokens ajustados" más abajo. Antes del
ajuste, **18 de 86 pares fallaban** (9 por tema): todos los pares de
`--border`/`--border-st` contra sus tres fondos, y el texto de
`.btn-primary` (blanco fijo) contra `--accent` en el tema oscuro.

### Tema: light

| Par | FG | BG | Ratio | Requerido | Resultado |
|---|---|---|---|---|---|
| --text on --bg | `#16181D` | `#F6F7F9` | 16.57:1 | 4.5:1 | PASS ✅ |
| --text on --surface | `#16181D` | `#FFFFFF` | 17.76:1 | 4.5:1 | PASS ✅ |
| --text on --muted | `#16181D` | `#EFF1F4` | 15.69:1 | 4.5:1 | PASS ✅ |
| --text-soft on --bg | `#3D434E` | `#F6F7F9` | 9.28:1 | 4.5:1 | PASS ✅ |
| --text-soft on --surface | `#3D434E` | `#FFFFFF` | 9.95:1 | 4.5:1 | PASS ✅ |
| --text-soft on --muted | `#3D434E` | `#EFF1F4` | 8.79:1 | 4.5:1 | PASS ✅ |
| --text-mute on --bg | `#5F6672` | `#F6F7F9` | 5.40:1 | 4.5:1 | PASS ✅ |
| --text-mute on --surface | `#5F6672` | `#FFFFFF` | 5.78:1 | 4.5:1 | PASS ✅ |
| --text-mute on --muted | `#5F6672` | `#EFF1F4` | 5.11:1 | 4.5:1 | PASS ✅ |
| --accent on --bg — link/accent text | `#2B4FD8` | `#F6F7F9` | 6.10:1 | 4.5:1 | PASS ✅ |
| --accent on --surface — link/accent text | `#2B4FD8` | `#FFFFFF` | 6.54:1 | 4.5:1 | PASS ✅ |
| --accent on --muted — link/accent text (AdminNav item activo) | `#2B4FD8` | `#EFF1F4` | 5.78:1 | 4.5:1 | PASS ✅ |
| var(--bg) (btn label) on --accent — btn-primary label | `#F6F7F9` | `#2B4FD8` | 6.10:1 | 4.5:1 | PASS ✅ |
| var(--bg) (btn label) on --accent-hover — btn-primary:hover label | `#F6F7F9` | `#2342BE` | 7.55:1 | 4.5:1 | PASS ✅ |
| --live on --surface — LiveDot / success text on card | `#177A47` | `#FFFFFF` | 5.37:1 | 4.5:1 | PASS ✅ |
| --live on --bg — LiveDot on page bg | `#177A47` | `#F6F7F9` | 5.01:1 | 4.5:1 | PASS ✅ |
| --live on --live-subtle — LiveDot label on its subtle bg | `#177A47` | `#E4F5EB` | 4.75:1 | 4.5:1 | PASS ✅ |
| --success badge text on badge fill — StatusBadge tone=success | `#177A47` | `#E3EFE9` | 4.55:1 | 4.5:1 | PASS ✅ |
| --text on success alert fill — InlineAlert tone=success | `#16181D` | `#E3EFE9` | 15.04:1 | 4.5:1 | PASS ✅ |
| --warning badge text on badge fill — StatusBadge tone=warning | `#96570A` | `#F2EBE2` | 4.85:1 | 4.5:1 | PASS ✅ |
| --text on warning alert fill — InlineAlert tone=warning | `#16181D` | `#F2EBE2` | 15.02:1 | 4.5:1 | PASS ✅ |
| --danger badge text on badge fill — StatusBadge tone=danger | `#BB3129` | `#F7E6E5` | 4.86:1 | 4.5:1 | PASS ✅ |
| --danger on btn-danger fill — btn-danger label | `#BB3129` | `#F5E2E1` | 4.70:1 | 4.5:1 | PASS ✅ |
| --text on danger alert fill — InlineAlert tone=danger | `#16181D` | `#F7E6E5` | 14.72:1 | 4.5:1 | PASS ✅ |
| --text on info alert fill — InlineAlert tone=info | `#16181D` | `#E2EEF2` | 15.01:1 | 4.5:1 | PASS ✅ |
| --accent badge text on badge fill — StatusBadge tone=accent | `#2B4FD8` | `#E6EAFA` | 5.45:1 | 4.5:1 | PASS ✅ |
| --text-mute placeholder on --surface — .input-base::placeholder | `#5F6672` | `#FFFFFF` | 5.78:1 | 4.5:1 | PASS ✅ |
| --text-mute table header on --muted — .table-dashstack th | `#5F6672` | `#EFF1F4` | 5.11:1 | 4.5:1 | PASS ✅ |
| --border vs --surface — card/input border | `#7E8BA1` | `#FFFFFF` | 3.45:1 | 3:1 | PASS ✅ |
| --border vs --bg — border on page bg | `#7E8BA1` | `#F6F7F9` | 3.21:1 | 3:1 | PASS ✅ |
| --border vs --muted — border on muted bg | `#7E8BA1` | `#EFF1F4` | 3.04:1 | 3:1 | PASS ✅ |
| --border-st vs --surface — strong border on surface | `#616D83` | `#FFFFFF` | 5.22:1 | 3:1 | PASS ✅ |
| --border-st vs --bg — strong border on page bg | `#616D83` | `#F6F7F9` | 4.87:1 | 3:1 | PASS ✅ |
| --border-st vs --muted — strong border on muted bg | `#616D83` | `#EFF1F4` | 4.61:1 | 3:1 | PASS ✅ |
| --accent-border vs --surface | `#6884E9` | `#FFFFFF` | 3.46:1 | 3:1 | PASS ✅ |
| --accent-border vs --bg | `#6884E9` | `#F6F7F9` | 3.23:1 | 3:1 | PASS ✅ |
| --accent-border vs --muted | `#6884E9` | `#EFF1F4` | 3.06:1 | 3:1 | PASS ✅ |
| success badge border vs fill | `#618688` | `#E3EFE9` | 3.37:1 | 3:1 | PASS ✅ |
| warning badge border vs fill | `#857B74` | `#F2EBE2` | 3.49:1 | 3:1 | PASS ✅ |
| danger badge border vs fill | `#8F727F` | `#F7E6E5` | 3.57:1 | 3:1 | PASS ✅ |
| accent badge border vs fill | `#6579B2` | `#E6EAFA` | 3.55:1 | 3:1 | PASS ✅ |
| btn-danger border vs fill | `#926E7B` | `#F5E2E1` | 3.56:1 | 3:1 | PASS ✅ |
| --accent focus ring vs --bg — focus-visible outer ring | `#2B4FD8` | `#F6F7F9` | 6.10:1 | 3:1 | PASS ✅ |

43 pares medidos, 0 fallando.

### Tema: dark

| Par | FG | BG | Ratio | Requerido | Resultado |
|---|---|---|---|---|---|
| --text on --bg | `#E9EBEF` | `#101216` | 15.71:1 | 4.5:1 | PASS ✅ |
| --text on --surface | `#E9EBEF` | `#171A20` | 14.60:1 | 4.5:1 | PASS ✅ |
| --text on --muted | `#E9EBEF` | `#1E222A` | 13.35:1 | 4.5:1 | PASS ✅ |
| --text-soft on --bg | `#C2C7D1` | `#101216` | 11.06:1 | 4.5:1 | PASS ✅ |
| --text-soft on --surface | `#C2C7D1` | `#171A20` | 10.28:1 | 4.5:1 | PASS ✅ |
| --text-soft on --muted | `#C2C7D1` | `#1E222A` | 9.40:1 | 4.5:1 | PASS ✅ |
| --text-mute on --bg | `#98A0AE` | `#101216` | 7.12:1 | 4.5:1 | PASS ✅ |
| --text-mute on --surface | `#98A0AE` | `#171A20` | 6.62:1 | 4.5:1 | PASS ✅ |
| --text-mute on --muted | `#98A0AE` | `#1E222A` | 6.05:1 | 4.5:1 | PASS ✅ |
| --accent on --bg — link/accent text | `#8AA3FF` | `#101216` | 7.82:1 | 4.5:1 | PASS ✅ |
| --accent on --surface — link/accent text | `#8AA3FF` | `#171A20` | 7.27:1 | 4.5:1 | PASS ✅ |
| --accent on --muted — link/accent text (AdminNav item activo) | `#8AA3FF` | `#1E222A` | 6.65:1 | 4.5:1 | PASS ✅ |
| var(--bg) (btn label) on --accent — btn-primary label | `#101216` | `#8AA3FF` | 7.82:1 | 4.5:1 | PASS ✅ |
| var(--bg) (btn label) on --accent-hover — btn-primary:hover label | `#101216` | `#A3B6FF` | 9.55:1 | 4.5:1 | PASS ✅ |
| --live on --surface — LiveDot / success text on card | `#4ADE8C` | `#171A20` | 10.06:1 | 4.5:1 | PASS ✅ |
| --live on --bg — LiveDot on page bg | `#4ADE8C` | `#101216` | 10.82:1 | 4.5:1 | PASS ✅ |
| --live on --live-subtle — LiveDot label on its subtle bg | `#4ADE8C` | `#0F2A1D` | 8.85:1 | 4.5:1 | PASS ✅ |
| --success badge text on badge fill — StatusBadge tone=success | `#4ADE8C` | `#1D322D` | 7.84:1 | 4.5:1 | PASS ✅ |
| --text on success alert fill — InlineAlert tone=success | `#E9EBEF` | `#1D322D` | 11.38:1 | 4.5:1 | PASS ✅ |
| --warning badge text on badge fill — StatusBadge tone=warning | `#F0B44C` | `#312C25` | 7.47:1 | 4.5:1 | PASS ✅ |
| --text on warning alert fill — InlineAlert tone=warning | `#E9EBEF` | `#312C25` | 11.59:1 | 4.5:1 | PASS ✅ |
| --danger badge text on badge fill — StatusBadge tone=danger | `#FF8A80` | `#33272C` | 6.27:1 | 4.5:1 | PASS ✅ |
| --danger on btn-danger fill — btn-danger label | `#FF8A80` | `#372A2D` | 6.01:1 | 4.5:1 | PASS ✅ |
| --text on danger alert fill — InlineAlert tone=danger | `#E9EBEF` | `#33272C` | 11.99:1 | 4.5:1 | PASS ✅ |
| --text on info alert fill — InlineAlert tone=info | `#E9EBEF` | `#212F38` | 11.51:1 | 4.5:1 | PASS ✅ |
| --accent badge text on badge fill — StatusBadge tone=accent | `#8AA3FF` | `#252A3B` | 5.95:1 | 4.5:1 | PASS ✅ |
| --text-mute placeholder on --surface — .input-base::placeholder | `#98A0AE` | `#171A20` | 6.62:1 | 4.5:1 | PASS ✅ |
| --text-mute table header on --muted — .table-dashstack th | `#98A0AE` | `#1E222A` | 6.05:1 | 4.5:1 | PASS ✅ |
| --border vs --surface — card/input border | `#616D86` | `#171A20` | 3.35:1 | 3:1 | PASS ✅ |
| --border vs --bg — border on page bg | `#616D86` | `#101216` | 3.61:1 | 3:1 | PASS ✅ |
| --border vs --muted — border on muted bg | `#616D86` | `#1E222A` | 3.07:1 | 3:1 | PASS ✅ |
| --border-st vs --surface — strong border on surface | `#7F8AA1` | `#171A20` | 5.02:1 | 3:1 | PASS ✅ |
| --border-st vs --bg — strong border on page bg | `#7F8AA1` | `#101216` | 5.40:1 | 3:1 | PASS ✅ |
| --border-st vs --muted — strong border on muted bg | `#7F8AA1` | `#1E222A` | 4.59:1 | 3:1 | PASS ✅ |
| --accent-border vs --surface | `#5567B8` | `#171A20` | 3.33:1 | 3:1 | PASS ✅ |
| --accent-border vs --bg | `#5567B8` | `#101216` | 3.59:1 | 3:1 | PASS ✅ |
| --accent-border vs --muted | `#5567B8` | `#1E222A` | 3.05:1 | 3:1 | PASS ✅ |
| success badge border vs fill | `#5B8D88` | `#1D322D` | 3.63:1 | 3:1 | PASS ✅ |
| warning badge border vs fill | `#8C8275` | `#312C25` | 3.67:1 | 3:1 | PASS ✅ |
| danger badge border vs fill | `#8D7584` | `#33272C` | 3.41:1 | 3:1 | PASS ✅ |
| accent badge border vs fill | `#6D7DAA` | `#252A3B` | 3.51:1 | 3:1 | PASS ✅ |
| btn-danger border vs fill | `#947684` | `#372A2D` | 3.38:1 | 3:1 | PASS ✅ |
| --accent focus ring vs --bg — focus-visible outer ring | `#8AA3FF` | `#101216` | 7.82:1 | 3:1 | PASS ✅ |

43 pares medidos, 0 fallando.

## Tokens ajustados

Dos problemas reales aparecieron en la primera pasada (antes de
cualquier ajuste); ambos se corrigieron manteniendo el tono/intención
original (papel frío + tinta, cobalto de interacción) y se
sincronizaron en `frontend/src/style.css` y `design/03-design-system.md`.

### 1. `--border`, `--border-st`, `--accent-border` — hairline invisible

El hairline original (`#E2E5EA` / `#C9CED7` / `#B9C6F5` en light,
`#2A2F3A` / `#3B4250` / `#33407A` en dark) dependía casi enteramente
del color del propio borde para separar tarjetas, inputs, filas de
tabla y botones de su fondo (design/03 §3: *"la separación se hace
con borde de 1px, no con sombra"*) — pero medía solo **1.18–1.92:1**
contra `--bg`/`--surface`/`--muted`, muy por debajo del 3:1 no-textual
de WCAG 1.4.11. Como no hay sombra que compense, esta era una falla
real de percepción de límites de componentes (inputs y botones
serían casi indistinguibles de su fondo para un usuario con baja
visión).

Se oscureció (light) / aclaró (dark) cada token manteniendo su tono y
saturación (búsqueda binaria en HSL, ajustando solo L) hasta alcanzar
el mínimo que cubre el peor caso de sus tres fondos, con un margen
pequeño para sobrevivir el redondeo a hex:

| Token | Antes (light) | Después (light) | Antes (dark) | Después (dark) |
|---|---|---|---|---|
| `--border` | `#E2E5EA` (1.18–1.26:1) | `#7E8BA1` (3.04–3.45:1) | `#2A2F3A` (1.30–1.40:1) | `#616D86` (3.07–3.61:1) |
| `--border-st` | `#C9CED7` (1.47–1.58:1) | `#616D83` (4.61–5.22:1) | `#3B4250` (1.73–1.86:1) | `#7F8AA1` (4.59–5.40:1) |
| `--accent-border` | `#B9C6F5` (1.49–1.69:1) | `#6884E9` (3.06–3.46:1) | `#33407A` (1.64–1.92:1) | `#5567B8` (3.05–3.59:1) |

`--border-st` se resolvió a un objetivo más alto (~4.6:1 mínimo) que
`--border` (~3.0:1 mínimo) a propósito: si ambos tokens convergieran
al mismo mínimo quedarían visualmente idénticos y perderían su
propósito semántico (borde "fuerte" para estados de énfasis/hover
que aún no tiene consumidores en el código — se deja listo para las
primitivas de la tarea 2.4). El cambio en `--border`/`--border-st`/
`--accent-border` propaga automáticamente a los bordes de badges
(`success/warning/danger/accent badge border vs fill`,
`btn-danger border vs fill`) porque esas mezclas usan
`color-mix(in srgb, <estado> P%, var(--border))`; se verificaron por
separado arriba y las 5 pasan con margen (3.37–3.67:1) sin tocar los
porcentajes de mezcla.

### 2. `.btn-primary` — texto blanco fijo ilegible en tema oscuro

`.btn-primary` fijaba `color: #FFFFFF` sin importar el tema. En light
eso da 6.54:1 contra `--accent` (`#2B4FD8`, azul oscuro) — bien. Pero
en dark, `--accent` es un periwinkle claro (`#8AA3FF`) pensado para
leerse como texto/link sobre fondos oscuros, no como fondo de botón
con texto blanco encima: blanco sobre `#8AA3FF` mide **2.40:1** (label
normal) y blanco sobre `--accent-hover` (`#A3B6FF`) **1.96:1** — muy
por debajo de 4.5:1.

Fix mínimo sin tokens nuevos: `.btn-primary`/`:hover` ahora usan
`color: var(--bg)` en vez de `#FFFFFF`. `--bg` ya está calibrado por
tema para tener buen contraste contra `--accent` (es exactamente la
fila "`--accent` on `--bg`" de las tablas de arriba, simétrica en
ratio a "texto `--bg` on fondo `--accent`"): light `#F6F7F9` sobre
`#2B4FD8`/`#2342BE` da 6.10:1/7.55:1; dark `#101216` sobre
`#8AA3FF`/`#A3B6FF` da 7.82:1/9.55:1. No se introduce ningún token
nuevo — `--bg` ya existía y ya se usa en el anillo de foco por el
mismo motivo (separador antes del anillo de `--accent`).

## Notas de alcance

- El estado "disabled" no se midió como un par de contraste fijo:
  los controles deshabilitados de este repo bajan opacidad (`opacity:
  0.6` sobre un color ya accesible, ver `AvatarMenu.vue`) en vez de
  usar un token de color propio, y WCAG 1.4.3/1.4.11 exceptúan
  explícitamente los componentes inactivos. Si una primitiva futura
  (tarea 2.4) introduce un token `--text-disabled` o similar, debe
  añadirse a este documento y a `contrast_check.py`.
- `--accent-border` no tiene consumidores en el código todavía (solo
  está declarado en `style.css`), pero es un token nombrado del
  inventario de `design/03` §1 pensado para bordes de énfasis de
  acento (ej. inputs seleccionados/con error de foco); se midió y
  corrigió igual que los demás para que las primitivas de la tarea
  2.4 no hereden un valor que ya se sabe que falla.
- Los valores de texto sobre "alert fill" (`InlineAlert`) siempre usan
  `--text` como color de letra (el tono solo cambia el borde
  izquierdo y el fondo tenue) — por eso esos cuatro pares miden
  contraste de `--text` contra un fondo casi idéntico a `--surface`
  (mezcla al 12%) y pasan con muchísimo margen; no hay un modo donde
  el texto tome el color del estado (`--danger`, etc.) directamente
  sobre su propio fondo tenue de alert, así que ese caso no se
  incluyó como par aparte (sí se cubre para badges, donde el texto
  *sí* toma el color de estado).
