<script setup lang="ts">
import { renderQuizMarkdown } from '../utils/markdown'
const props = defineProps<{ content: string }>()
const rendered = computed(() => renderQuizMarkdown(props.content))
</script>
<template>
  <!-- Raw HTML and unsafe URL schemes are disabled by renderQuizMarkdown. -->
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div class="quiz-markdown" v-html="rendered" />
</template>
