<template>
  <!-- markdown-it runs with raw HTML disabled for question package safety. -->
  <div class="quiz-markdown" :class="variantClass" v-html="html" />
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { renderQuizMarkdown } from 'src/services/markdown';

const props = withDefaults(
  defineProps<{
    text: string;
    variant?: 'prompt' | 'option' | 'review';
  }>(),
  {
    variant: 'prompt',
  },
);

const html = computed(() => renderQuizMarkdown(props.text));
const variantClass = computed(() => `quiz-markdown--${props.variant}`);
</script>
