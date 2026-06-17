<template>
  <section class="result-panel" :class="{ 'result-panel--solution': selectedSolutionAnswer }">
    <template v-if="selectedSolutionAnswer">
      <div class="row items-center q-mb-md">
        <q-btn
          flat
          round
          icon="arrow_back"
          :aria-label="t('solution.back')"
          @click="closeSolution"
        >
          <q-tooltip>{{ t('solution.back') }}</q-tooltip>
        </q-btn>

        <div class="col text-h5 text-center">{{ t('solution.title') }}</div>

        <q-space />

        <img :src="logoUrl" :alt="t('app.title')" class="brand-logo" />
      </div>

      <div class="solution-screen">
        <div class="solution-screen__meta">
          <q-chip
            dense
            square
            :color="selectedSolutionAnswer.correct ? 'green-9' : 'red-9'"
            text-color="white"
          >
            {{
              selectedSolutionAnswer.correct
                ? t('result.reviewAccept')
                : t('result.reviewRejected')
            }}
          </q-chip>
          <q-chip dense square color="cyan-10" text-color="white">
            {{ selectedSolutionAnswer.questionId }}
          </q-chip>
        </div>

        <section class="solution-screen__question">
          <h2>{{ t('solution.question') }}</h2>
          <QuizMarkdown :text="selectedSolutionAnswer.prompt" variant="review" />
        </section>

        <section class="solution-screen__explanation">
          <h2>{{ t('solution.explanation') }}</h2>

          <div v-if="solutionLoading" class="solution-screen__loading">
            <q-spinner-dots color="cyan-3" size="34px" />
            <span>{{ t('solution.generating') }}</span>
          </div>

          <q-banner v-else-if="solutionError" rounded class="solution-screen__error">
            {{ solutionError }}
          </q-banner>

          <template v-else-if="solution">
            <QuizMarkdown :text="solution.explanation" variant="review" />
            <div class="solution-screen__details">
              <span>{{ solution.cached ? t('solution.cached') : t('solution.generated') }}</span>
              <span v-if="solution.model">{{ solution.model }}</span>
              <span v-if="solution.generatedAt">{{ solution.generatedAt }}</span>
            </div>
          </template>
        </section>
      </div>

      <div class="command-actions row q-col-gutter-sm">
        <div class="col-12">
          <q-btn
            flat
            color="white"
            icon="arrow_back"
            :label="t('solution.back')"
            class="full-width"
            @click="closeSolution"
          />
        </div>
      </div>
    </template>

    <template v-else>
    <div class="row items-center q-mb-xs">
      <img :src="logoUrl" :alt="t('app.title')" class="brand-logo" />

      <div class="col text-h4 text-center">
        {{ result.variant === 'run' ? t('result.runTitle') : t('result.sessionTitle') }}
      </div>

      <q-space />

      <q-btn
        flat
        round
        icon="help_outline"
        :aria-label="t('rules.button')"
        @click="emit('open-rules')"
      >
        <q-tooltip>{{ t('rules.button') }}</q-tooltip>
      </q-btn>

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

    <LayoutAds class="result-ad-slot lt-md" placement="result" />

    <q-markup-table flat bordered separator="horizontal" class="answer-table">
      <thead>
        <tr>
          <th class="answer-table__icon">
            <q-icon name="commit" size="18px" :aria-label="t('result.icon')" />
          </th>
          <th>{{ t('result.pullRequest') }}</th>
          <th class="answer-table__icon">
            <q-icon name="rate_review" size="18px" :aria-label="t('solution.open')" />
          </th>
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
          <td class="answer-table__icon">
            <q-btn
              v-if="!answer.correct"
              flat
              round
              dense
              color="cyan-3"
              icon="rate_review"
              :aria-label="t('solution.open')"
              @click="openSolution(answer)"
            >
              <q-badge
                v-if="isSolutionUnread(answer)"
                floating
                color="red-8"
                rounded
                label="1"
              />
              <q-tooltip>{{ t('solution.open') }}</q-tooltip>
            </q-btn>
          </td>
          <td class="gt-xs">
            <q-chip dense square :color="answer.correct ? 'green-9' : 'red-9'" text-color="white">
              {{ answer.correct ? t('result.reviewAccept') : t('result.reviewRejected') }}
            </q-chip>
          </td>
        </tr>
        <tr v-if="result.answers.length === 0">
          <td class="lt-sm" colspan="3">{{ t('result.noAnswers') }}</td>
          <td class="gt-xs" colspan="4">{{ t('result.noAnswers') }}</td>
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

    <q-page-sticky class="gt-xs" position="bottom-right" :offset="[18, 18]">
      <q-btn round text-color="white" icon="terminal" size="md" @click="showTerminal = true" />
    </q-page-sticky>

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
    </template>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import logoUrl from 'src/assets/logo-large.svg';
import { getQuestionSolution, type QuestionSolution } from 'src/services/api';
import type { ResultAnswer, ResultPanelEmit, ResultPanelProps } from './contracts';
import {
  syslogPrompt,
  syslogTailCommand,
  syslogTerminalIntroLines,
} from 'src/services/syslog-terminal';
import QuizMarkdown from './QuizMarkdown.vue';
import SyslogEventLine from './SyslogEventLine.vue';
import LayoutAds from './LayoutAds.vue';

defineProps<ResultPanelProps>();
const emit = defineEmits<ResultPanelEmit>();
const { t } = useI18n();

const showTerminal = ref(false);
const terminalPrompt = ref('');
const terminalInput = ref<{ focus: () => void }>();
const selectedSolutionAnswer = ref<ResultAnswer | null>(null);
const solution = ref<QuestionSolution | null>(null);
const solutionLoading = ref(false);
const solutionError = ref('');
const readSolutionKeys = ref(new Set<string>());
let solutionRequestId = 0;

function focusTerminalPrompt() {
  terminalInput.value?.focus();
}

function sendTerminalCommand() {
  if (terminalPrompt.value.trim() === 'exit') {
    showTerminal.value = false;
    terminalPrompt.value = '';
  }
}

async function openSolution(answer: ResultAnswer) {
  if (answer.correct) {
    return;
  }

  const requestId = ++solutionRequestId;
  selectedSolutionAnswer.value = answer;
  solution.value = null;
  solutionError.value = '';
  solutionLoading.value = true;

  try {
    const loaded = await getQuestionSolution(answer.runId, answer.questionId);
    if (requestId !== solutionRequestId) {
      return;
    }
    solution.value = loaded;
    markSolutionRead(answer);
  } catch {
    if (requestId !== solutionRequestId) {
      return;
    }
    solutionError.value = t('errors.solution');
  } finally {
    if (requestId === solutionRequestId) {
      solutionLoading.value = false;
    }
  }
}

function closeSolution() {
  solutionRequestId++;
  selectedSolutionAnswer.value = null;
  solution.value = null;
  solutionError.value = '';
  solutionLoading.value = false;
}

function isSolutionUnread(answer: ResultAnswer) {
  return !readSolutionKeys.value.has(solutionReadKey(answer));
}

function markSolutionRead(answer: ResultAnswer) {
  const next = new Set(readSolutionKeys.value);
  next.add(solutionReadKey(answer));
  readSolutionKeys.value = next;
}

function solutionReadKey(answer: ResultAnswer) {
  return `${answer.runId}:${answer.questionId}`;
}
</script>
