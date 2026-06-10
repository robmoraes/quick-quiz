<template>
  <div ref="terminalOutput" class="tail-banner-terminal" aria-hidden="true">
    <div v-for="line in syslogTerminalIntroLines" :key="line" class="tail-banner-terminal__line">
      {{ line }}
    </div>
    <div class="tail-banner-terminal__line">
      <span class="syslog-token syslog-token--prompt">{{ syslogPrompt }} </span>
      <span class="syslog-token syslog-token--command">{{ syslogTailCommand }}</span>
    </div>
    <div v-for="event in sessionEventLog" :key="event.id" class="tail-banner-terminal__line">
      <SyslogEventLine :event="event" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { sessionEventLog } from 'src/services/session-events';
import { syslogPrompt, syslogTailCommand, syslogTerminalIntroLines } from 'src/services/syslog-terminal';
import SyslogEventLine from './SyslogEventLine.vue';

const terminalOutput = ref<HTMLElement>();

watch(
  () => sessionEventLog.value.length,
  async () => {
    await nextTick();
    terminalOutput.value?.scrollTo({
      top: terminalOutput.value.scrollHeight,
      behavior: 'smooth',
    });
  },
  { flush: 'post' },
);
</script>
