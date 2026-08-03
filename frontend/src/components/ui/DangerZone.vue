<script setup lang="ts">
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';

// Destructive-action container (design/07 §4, design/03 §5). Pairs with
// UiModal for the actual confirmation — this component only frames the
// action(s), it never confirms/executes anything itself.
//
// `title` defaults to the internal `common.dangerZone` i18n key rather
// than requiring every one of the ~8 screens that render a DangerZone to
// repeat the same literal "Zona de peligro" string (design/07 always
// uses that exact heading) — same exception class as TechValue's
// copy/copied labels. It stays overridable via prop for the rare case a
// screen needs a different heading.
//
// Background is NOT a `--danger-subtle` token (design/03 §1 defines no
// such token — the mockup's `--danger-subtle` never made it into the
// audited token set). Reuses the already-audited
// `color-mix(in srgb, var(--danger) 12%, var(--surface))` fill from
// InlineAlert's `tone=danger` recipe (design/contrast-check.md: --danger
// and --text both pass AA on this exact mixed background, both themes).
const props = withDefaults(
  defineProps<{
    title?: string;
    description: string;
  }>(),
  {
    title: undefined,
  },
);

const { t } = useI18n();
const headingId = useId();
</script>

<template>
  <section class="danger-zone" role="group" :aria-labelledby="headingId">
    <h4 :id="headingId" class="text-h3 danger-zone-title">{{ title ?? t('common.dangerZone') }}</h4>
    <p class="text-body danger-zone-description">{{ description }}</p>
    <div class="danger-zone-actions">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.danger-zone {
  border: 1px solid var(--danger);
  border-radius: var(--radius-lg);
  padding: 14px 16px;
  background: color-mix(in srgb, var(--danger) 12%, var(--surface));
}

.danger-zone-title {
  margin: 0 0 2px;
  color: var(--danger);
}

.danger-zone-description {
  margin: 0 0 10px;
  color: var(--text);
}

.danger-zone-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
