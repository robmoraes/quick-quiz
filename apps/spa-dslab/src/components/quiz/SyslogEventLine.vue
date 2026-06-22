<template>
  <span class="syslog-line">
    <span
      v-for="(part, index) in parts"
      :key="`${index}-${part.role}`"
      :class="[
        'syslog-token',
        `syslog-token--${part.role}`,
        part.severity ? `syslog-token--severity-${part.severity}` : undefined,
      ]"
    >
      {{ part.text }}
    </span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { SessionEvent } from 'src/services/session-events';
import { formatSyslogEventParts } from 'src/services/syslog-terminal';

const props = defineProps<{
  event: SessionEvent;
}>();

const parts = computed(() => formatSyslogEventParts(props.event));
</script>
