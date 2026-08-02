# Diseño Dorix — Sistema "Pulso"

Carpeta centralizada de diseño de la UI de Dorix (tenant admin +
platform admin + shell). Este es el punto único de verdad para
decisiones visuales y de UX.

## Objetivo

Las configuraciones de Dorix son extensas (agente IA, herramientas,
líneas de WhatsApp, fuentes de datos, credenciales). El diseño tiene
un solo mandato: **que cualquier persona, sin entrenamiento, entienda
qué hace cada cosa y para qué sirve**, en una UI compacta y calmada.

## Documentos

| Doc | Contenido |
|---|---|
| [01-diagnostico.md](01-diagnostico.md) | Auditoría del código actual del frontend (contexto para implementar) |
| [02-principios-ux.md](02-principios-ux.md) | Principios de UX y referencias (Linear, Stripe, Attio, settings de élite) |
| [03-design-system.md](03-design-system.md) | Design system "Pulso": tokens, color, tipografía, densidad, componentes, accesibilidad |
| [04-arquitectura-informacion.md](04-arquitectura-informacion.md) | Navegación, rutas, permisos, búsqueda de ajustes |
| [05-tenant-admin.md](05-tenant-admin.md) | Diseño pantalla por pantalla del admin de tenant |
| [06-platform-admin.md](06-platform-admin.md) | Superficie de platform admin (super admin) |
| [07-patrones-ux.md](07-patrones-ux.md) | Patrones transversales: resumen-primero, herencia, zonas de peligro, microcopy |
| [08-plan-implementacion.md](08-plan-implementacion.md) | Plan de implementación por fases |
| [mockups/](mockups/) | Maquetas HTML estáticas del diseño |

## La dirección en 5 líneas

1. **Claro y compacto**: light-first, papel frío + tinta, controles de
   32 px, cuerpo de 13 px, tablas en vez de tarjetas infinitas.
2. **Acento cobalto** para acción e interacción; **verde reservado**:
   solo significa "conectado / asistente activo en WhatsApp", nunca
   decoración.
3. **Resumen primero**: cada pantalla abre con una frase humana del
   estado actual ("El asistente responde automático con el modelo
   Equilibrado en 3 líneas"); la edición se despliega bajo demanda.
4. **Herencia visible**: la configuración por línea muestra chips
   `Heredado de la organización` / `Personalizado` en vez de repetir
   el formulario completo por cada línea.
5. **Todo se explica solo**: cada ajuste tiene título en lenguaje del
   negocio + una frase de "qué hace" + ejemplo cuando aplica. Monospace
   para identificadores técnicos, que se reconocen como técnicos.

## Estado

- v1 (2026-08-02). Cambio OpenSpec de implementación:
  `redesign-ui-pulso`. Maqueta: `mockups/admin-pulso.html`.
