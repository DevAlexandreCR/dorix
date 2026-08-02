# 02 · Principios de UX y referencias

## Quién usa Dorix

| Perfil | Contexto | Necesita |
|---|---|---|
| Dueño/admin de negocio (tenant admin) | No técnico, es_CO, a menudo en móvil | Entender qué hace el asistente y ajustarlo sin miedo a dañar nada |
| Operador | Todo el día en Operaciones, ritmo de inbox | Que el admin no le estorbe; densidad y velocidad |
| Super admin (plataforma) | Técnico, gestiona todos los tenants | Vista global, acciones cross-tenant, credenciales |

## Referencias investigadas (2026)

Patrones extraídos de la investigación de los mejores paneles admin y
settings actuales:

- **Linear** — densidad sin ruido: filas ~36 px, casi cero "chrome",
  navegación por teclado. Referencia de compacidad.
- **Stripe** — progressive disclosure: la complejidad se esconde tras
  clics, no en el primer render; tablas como forma primaria de listar
  entidades. Referencia de claridad.
- **Attio / paneles AI-native** — el estado del agente IA es una
  superficie de primera clase, no un formulario más.
- **Settings de élite (Notion, Slack, Stripe)** — sidebar vertical
  predecible con grupos; búsqueda fuzzy arriba; microcopy inline bajo
  cada control; "danger zone" aislada; en móvil, drill-down de pantalla
  completa.
- **Configuración de agentes IA para no técnicos** — "la mayoría
  necesita 3 opciones, no 30"; acciones reversibles; nunca obligar a
  editar el prompt crudo como única interfaz.

## Principios (en orden de prioridad)

### P1 · Nadie necesita manual
Cada pantalla, grupo y control lleva: nombre en lenguaje del negocio
(nunca jerga del sistema), una frase de "qué hace" y, cuando el efecto
no es obvio, un ejemplo concreto. Regla de copy: si un ajuste no se
puede explicar en una frase, la pantalla está mal dividida.

### P2 · Resumen primero, edición después
El primer render de cada pantalla responde "¿cómo está esto hoy?" en
una frase humana + chips de estado. Editar es una acción deliberada
(expandir, drawer). Nunca 36 inputs en el primer paint.

### P3 · Compacto sin apretado
Cuerpo 13 px, controles 32 px, filas 40 px, espaciado base 4. La
jerarquía se hace con tipografía y agrupación, no con aire infinito ni
tarjetas anidadas. Máximo 2 niveles de contenedor visible.

### P4 · La herencia se ve, no se repite
Organización define el default; una línea puede personalizar. La UI
muestra la cadena (`Organización → Línea 305…`) y chips
`Heredado` / `Personalizado`, y solo pide los campos que cambian.

### P5 · Verde = conectado
El verde (mundo WhatsApp) queda reservado para semántica de conexión y
actividad del asistente: línea conectada, asistente activo, mensaje
enviado. Jamás como decoración. El acento de interacción es cobalto.

### P6 · Reversible y sin sustos
Toda acción destructiva vive en una zona de peligro aislada con
confirmación que nombra el efecto real ("Se dejarán de responder los
mensajes de la línea 305…"). Guardar confirma con el mismo verbo del
botón. Cambios sin guardar avisan antes de cambiar de ámbito.

### P7 · Móvil de verdad
`<lg`: el AdminNav se vuelve lista drill-down de pantalla completa,
targets ≥44 px, acciones pegajosas abajo. Nada de sidebars encogidos.

### P8 · Ambos temas de primera clase
Light-first (oficinas, móvil en calle), dark completo. Todo par
texto/fondo cumple WCAG AA (verificación medida, como el
`contrast-check.md` del cambio anterior).

## Anti-patrones prohibidos

- Formularios repetidos por entidad (el formulario de agente ×N líneas).
- Texto libre para valores enumerados (hoy `status` es un input de texto).
- Identificadores crudos en copy de UI (hoy: `` `search_inventory` ``
  aparece literal en un título).
- JSON crudo como contenido de usuario (hoy: metadata de importación
  en un `<details>`).
- Ocultar la única explicación de un ajuste dentro de "Opciones
  avanzadas".
