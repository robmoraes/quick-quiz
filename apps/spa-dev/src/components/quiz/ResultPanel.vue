<template>
  <section class="result-panel">
    <div class="brand-row">
      <div>
        <h1>{{ result.title }}</h1>
        <p>{{ result.subtitle }}</p>
      </div>
      <q-space />
      <q-btn
        flat
        round
        icon="settings"
        :aria-label="t('settings.title')"
        @click="emit('open-settings')"
      />
    </div>

    <div class="row q-col-gutter-sm q-mb-lg">
      <div class="col-6 col-md-3">
        <div class="score-card">
          <strong>{{ result.stats.answered }}</strong>
          <span>{{ t('result.answered') }}</span>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="score-card score-card--passed">
          <strong>{{ result.stats.correct }}</strong>
          <span>{{ t('result.correct') }}</span>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="score-card score-card--failed">
          <strong>{{ result.stats.wrong }}</strong>
          <span>{{ t('result.wrong') }}</span>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="score-card">
          <strong>{{ result.stats.accuracyPercent }}%</strong>
          <span>{{ t('result.accuracy') }}</span>
        </div>
      </div>
    </div>

    <q-markup-table flat bordered separator="horizontal" class="answer-table">
      <thead>
        <tr>
          <th class="answer-table__icon">
            <q-icon name="commit" size="18px" :aria-label="t('result.icon')" />
          </th>
          <th>{{ t('result.pullRequest') }}</th>
          <th class="gt-xs">{{ t('result.codeReview') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(answer, index) in result.answers" :key="`${answer.questionId}-${index}`">
          <td class="answer-table__icon">
            <q-icon
              :name="answer.correct ? 'check_circle' : 'cancel'"
              :color="answer.correct ? 'green-5' : 'red-5'"
              size="22px"
            />
          </td>
          <td>
            <QuizMarkdown :text="answer.prompt" variant="review" />
          </td>
          <td class="gt-xs">
            <q-chip dense square :color="answer.correct ? 'green-9' : 'red-9'" text-color="white">
              {{ answer.correct ? t('result.reviewAccept') : t('result.reviewRejected') }}
            </q-chip>
          </td>
        </tr>
        <tr v-if="result.answers.length === 0">
          <td class="lt-sm" colspan="2">{{ t('result.noAnswers') }}</td>
          <td class="gt-xs" colspan="3">{{ t('result.noAnswers') }}</td>
        </tr>
      </tbody>
    </q-markup-table>

    <div v-if="errorMessage" class="error-line">{{ errorMessage }}</div>

    <div v-if="result.variant === 'run'" class="command-actions row q-col-gutter-sm">
      <div :class="canEndSession ? 'col-6' : 'col-12'">
        <q-btn
          unelevated
          color="amber-8"
          text-color="black"
          icon="replay"
          :label="newRunActionLabel"
          :aria-label="t('actions.newRun')"
          :disable="busy"
          class="full-width"
          @click="emit('new-run')"
        />
      </div>
      <div v-if="canEndSession" class="col-6">
        <q-btn
          flat
          color="white"
          icon="logout"
          :label="endSessionActionLabel"
          :aria-label="t('actions.endSession')"
          :disable="busy"
          class="full-width"
          @click="emit('end-session')"
        />
      </div>
    </div>

    <div v-else class="command-actions row q-col-gutter-sm">
      <div class="col-12">
        <q-btn
          unelevated
          color="amber-8"
          text-color="black"
          icon="add_circle"
          :label="newSessionActionLabel"
          :aria-label="t('actions.newSession')"
          class="full-width"
          @click="emit('new-session')"
        />
      </div>
    </div>

    <div class="terminal-floating-action">
      <q-btn round text-color="white" icon="terminal" size="md" @click="showTerminal = true" />
    </div>

    <q-dialog v-model="showTerminal" maximized @show="focusTerminalPrompt">
      <q-card class="bg-black text-green-5 column">
        <!-- Output -->
        <q-card-section class="col q-pa-md terminal-output scroll">
          <div v-for="line in syslogTerminalIntroLines" :key="line">{{ line }}</div>
          <div>
            <span class="syslog-token syslog-token--prompt">{{ syslogPrompt }} </span>
            <span class="syslog-token syslog-token--command">{{ syslogTailCommand }}</span>
          </div>
          <div v-for="event in result.events" :key="event.id">
            <SyslogEventLine :event="event" />
          </div>
        </q-card-section>

        <!-- Prompt -->
        <q-card-actions class="bg-grey-10 q-pa-sm">
          <div class="row items-center full-width">
            <span class="text-green-5 q-mr-sm">run$quiz:~$ </span>

            <q-input
              ref="terminalInput"
              v-model="terminalPrompt"
              dark
              borderless
              dense
              class="col text-green-5 terminal-input"
              placeholder="type exit and press enter to leave"
              @keyup.enter="sendTerminalCommand"
            />
          </div>
        </q-card-actions>
      </q-card>
    </q-dialog>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ResultPanelEmit, ResultPanelProps } from './contracts';
import { syslogPrompt, syslogTailCommand, syslogTerminalIntroLines } from 'src/services/syslog-terminal';
import QuizMarkdown from './QuizMarkdown.vue';
import SyslogEventLine from './SyslogEventLine.vue';

defineProps<ResultPanelProps>();
const emit = defineEmits<ResultPanelEmit>();
const { t } = useI18n();

const showTerminal = ref(false);
const terminalPrompt = ref('');
const terminalInput = ref<{ focus: () => void }>();

function focusTerminalPrompt() {
  terminalInput.value?.focus();
}

function sendTerminalCommand() {
  if (terminalPrompt.value.trim() === 'exit') {
    showTerminal.value = false;
    terminalPrompt.value = '';
  }
}
</script>
