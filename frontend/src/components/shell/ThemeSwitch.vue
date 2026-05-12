<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useTheme } from '../../composables/useTheme';

const { t } = useI18n();
const { isDark, toggleTheme } = useTheme();
</script>

<template>
  <button class="shell-control min-w-0 justify-center sm:min-w-[112px]" type="button" @click="toggleTheme">
    {{ isDark ? t('shell.themeLight') : t('shell.themeDark') }}
  </button>
</template>
