<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useQuizGame } from '../../composables/useQuizGame'
import type { Difficulty } from '../../types/game'
import { parsePublicSettings } from '#shared/config'

const route = useRoute()
const config = useRuntimeConfig()
const { locale, localPath } = useWebLocale()
const game = useQuizGame({ baseUrl: config.public.apiBaseUrl, excludedThemeIds: parsePublicSettings(config.public).excludedThemeIds })
const { state, phase, topicLabel } = game
const mounted = ref(false)
const confirmation = ref<'hardcore' | 'reset' | 'end' | ''>('')
const confirmHeading = ref<HTMLElement | null>(null)
const stringQuery = (value: unknown) => typeof value === 'string' ? value : ''
const requestedTheme = computed(() => stringQuery(route.query.theme))
const requestedTopic = computed(() => stringQuery(route.query.topic))
const requestedDifficulty = computed(() => {
  const number = Number(route.query.difficulty)
  return [1, 2, 3, 4].includes(number) ? number as Difficulty : undefined
})
const routeConflict = computed(() => Boolean(state.context.sessionId && requestedTheme.value && state.context.theme !== requestedTheme.value))
const topicMismatch = computed(() => Boolean(state.context.sessionId && requestedTheme.value === state.context.theme && requestedTopic.value && requestedTopic.value !== state.context.topic))
const canMoveTopic = computed(() => phase.value === 'levels' || phase.value === 'runResult')
const copy = computed(() => locale.value === 'pt-BR' ? {
  eyebrow: 'Um assunto. Novas descobertas.', title: 'Seu próximo desafio começa aqui.',
  intro: 'Escolha seu ritmo, teste o que sabe e aprenda uma coisa nova a cada rodada.',
  prepare: 'Preparar quiz', discover: 'Explorar assuntos', difficulty: 'Escolha seu nível',
  difficultyIntro: 'As questões disponíveis respeitam o que você já respondeu nesta sessão.',
  levels: ['Essencial', 'Intermediário', 'Avançado', 'Hardcore'],
  descriptions: ['Construa confiança com os fundamentos.', 'Conecte conceitos e pratique decisões.', 'Aprofunde o raciocínio e os detalhes.', 'Um erro encerra a sessão e apaga seu progresso.'],
  questions: 'questões disponíveis', questionsSingular: 'questão disponível', options: 'alternativas', start: 'Começar rodada', unavailable: 'Esgotado nesta sessão',
  preparing: 'Preparando seu quiz…', pending: 'Confirmando com o servidor…', question: 'Questão', of: 'de',
  choose: 'Escolha uma resposta', correct: 'Resposta correta. Vamos em frente!', wrong: 'Essa não foi a resposta correta. Continue aprendendo!', synchronized: 'Estado confirmado com o servidor. Confira a questão antes de responder.',
  previousAnswer: 'Resposta anterior', roundStatus: 'Estado da rodada',
  result: 'Uma rodada, mais conhecimento.', resultIntro: 'Este resultado foi confirmado pelo servidor.',
  answered: 'Respondidas', hits: 'Corretas', misses: 'Incorretas', accuracy: 'Aproveitamento',
  review: 'Revisão da rodada', explain: 'Entender esta resposta', explanation: 'Explicação', generating: 'Consultando a explicação…', back: 'Voltar à revisão',
  next: 'Outra rodada neste tópico', anotherTopic: 'Ou continue em outro tópico', end: 'Encerrar sessão e ver resultado',
  uncertainTitle: 'Vamos confirmar antes de continuar.', uncertainStart: 'A conexão foi interrompida ao iniciar a rodada. Não recebemos o identificador e não podemos descobrir se ela foi criada. Não vamos iniciar outra automaticamente.',
  uncertainAnswer: 'Não foi possível confirmar sua última ação. Consulte o estado da rodada antes de responder novamente.', reconcile: 'Confirmar estado da rodada', reset: 'Reiniciar com uma nova sessão',
  expiredTitle: 'Esta rodada não está mais disponível.', expired: 'Ela pode ter expirado ou sido encerrada. Você pode começar uma nova sessão. O servidor não fornece o histórico completo de sessões antigas.',
  fatalTitle: 'Hardcore: fim de sessão.', fatal: 'Uma resposta incorreta encerrou a sessão e apagou seu progresso, conforme as regras deste nível. Uma nova sessão começa do zero.',
  closingTitle: 'Finalizando sua sessão.', closing: 'As respostas confirmadas estão salvas neste navegador. Falta confirmar o encerramento no servidor.', retryClose: 'Tentar confirmar encerramento',
  conflict: 'Você já tem uma sessão em outro tema.', conflictText: 'O tema de uma sessão não pode mudar. Continue a sessão atual ou confirme seu encerramento para começar neste tema.', continue: 'Continuar sessão atual',
  topicConflict: 'Você chegou a outro tópico.', topicConflictText: 'Sua sessão continua vinculada ao tópico exibido abaixo. Você pode continuar de onde parou. Uma rodada em andamento precisa ser encerrada antes de iniciar outro tópico.',
  error: 'Não foi possível concluir esta ação. Sua sessão foi preservada. Tente novamente quando a conexão estiver disponível.', retry: 'Tentar novamente',
  storage: 'O armazenamento desta aba está indisponível. Você pode jogar, mas atualizar ou fechar a página pode perder o contexto e os resultados locais.',
  noLevels: 'Nenhum nível está disponível neste tópico para esta sessão.',
  confirmHardcore: 'Pronto para o Hardcore?', hardcoreText: 'Uma resposta incorreta apaga todo o progresso desta sessão, incluindo rodadas anteriores. Quer continuar?',
  confirmReset: 'Começar do zero?', resetText: 'A sessão atual será encerrada. Seu progresso será descartado e uma nova identidade de sessão será criada após a confirmação do servidor.',
  confirmEnd: 'Encerrar esta sessão?', endText: 'A rodada atual será encerrada e o resultado reunirá apenas respostas confirmadas. Você poderá começar uma nova sessão depois.',
  confirm: 'Confirmar', cancel: 'Cancelar', session: 'Sua sessão', theme: 'Tema', topic: 'Tópico', runs: 'rodadas concluídas',
  waiting: 'Carregando contexto desta aba…', empty: 'Escolha um tema e um tópico para começar.',
  solutionUnavailable: 'A explicação está indisponível no momento. Você pode continuar revisando a rodada.',
} : {
  eyebrow: 'One subject. Fresh discoveries.', title: 'Your next challenge starts here.',
  intro: 'Find your pace, test what you know, and learn something new with every round.',
  prepare: 'Prepare quiz', discover: 'Explore subjects', difficulty: 'Choose your level',
  difficultyIntro: 'Available questions account for what you have already answered in this session.',
  levels: ['Essential', 'Intermediate', 'Advanced', 'Hardcore'],
  descriptions: ['Build confidence with the fundamentals.', 'Connect concepts and practice decisions.', 'Go deeper into reasoning and details.', 'One wrong answer ends the session and clears all progress.'],
  questions: 'questions available', questionsSingular: 'question available', options: 'options', start: 'Start round', unavailable: 'Exhausted in this session',
  preparing: 'Preparing your quiz…', pending: 'Confirming with the server…', question: 'Question', of: 'of',
  choose: 'Choose an answer', correct: 'Correct answer. Keep going!', wrong: 'That was not the correct answer. Keep learning!', synchronized: 'State confirmed with the server. Review the question before answering.',
  previousAnswer: 'Previous answer', roundStatus: 'Round status',
  result: 'One round, more knowledge.', resultIntro: 'This result was confirmed by the server.',
  answered: 'Answered', hits: 'Correct', misses: 'Incorrect', accuracy: 'Accuracy',
  review: 'Round review', explain: 'Understand this answer', explanation: 'Explanation', generating: 'Loading explanation…', back: 'Back to review',
  next: 'Another round in this topic', anotherTopic: 'Or continue in another topic', end: 'End session and view result',
  uncertainTitle: 'Let’s confirm before continuing.', uncertainStart: 'The connection was interrupted while starting the round. We did not receive its identifier and cannot discover whether it was created. We will not start another automatically.',
  uncertainAnswer: 'Your last action could not be confirmed. Check the round state before answering again.', reconcile: 'Confirm round state', reset: 'Restart with a new session',
  expiredTitle: 'This round is no longer available.', expired: 'It may have expired or ended. You can start a new session. The server does not provide a complete history of previous sessions.',
  fatalTitle: 'Hardcore: session over.', fatal: 'An incorrect answer ended the session and cleared its progress, as this level requires. A new session starts from scratch.',
  closingTitle: 'Finishing your session.', closing: 'Confirmed answers are saved in this browser. We still need to confirm the session has ended on the server.', retryClose: 'Retry session closure',
  conflict: 'You already have a session in another theme.', conflictText: 'A session cannot change themes. Continue the current session or confirm its closure to start in this theme.', continue: 'Continue current session',
  topicConflict: 'You arrived at another topic.', topicConflictText: 'Your session still belongs to the topic shown below. You can continue where you left off. An active round must end before you start another topic.',
  error: 'This action could not be completed. Your session has been preserved. Try again when the connection is available.', retry: 'Try again',
  storage: 'Storage is unavailable in this tab. You can play, but reloading or closing the page may lose your local context and results.',
  noLevels: 'No level is available in this topic for this session.',
  confirmHardcore: 'Ready for Hardcore?', hardcoreText: 'One incorrect answer clears all progress in this session, including previous rounds. Continue?',
  confirmReset: 'Start from scratch?', resetText: 'The current session will end. Progress will be discarded and a new session identity created after server confirmation.',
  confirmEnd: 'End this session?', endText: 'The current round will end. Your result will include confirmed answers only. You can start a new session afterwards.',
  confirm: 'Confirm', cancel: 'Cancel', session: 'Your session', theme: 'Theme', topic: 'Topic', runs: 'rounds completed',
  waiting: 'Loading context for this tab…', empty: 'Choose a theme and a topic to begin.',
  solutionUnavailable: 'The explanation is unavailable right now. You can continue reviewing the round.',
})
const confirmTitle = computed(() => confirmation.value === 'hardcore' ? copy.value.confirmHardcore : confirmation.value === 'reset' ? copy.value.confirmReset : copy.value.confirmEnd)
const confirmText = computed(() => confirmation.value === 'hardcore' ? copy.value.hardcoreText : confirmation.value === 'reset' ? copy.value.resetText : copy.value.endText)
const feedbackAnnouncement = computed(() => {
  if (state.busy || !state.feedback || !['question', 'runResult'].includes(phase.value)) return ''
  const label = state.feedback === 'synchronized' ? copy.value.roundStatus : copy.value.previousAnswer
  return `${label}. ${copy.value[state.feedback]}`
})
useHead(() => ({ title: `${copy.value.difficulty} · QuickQuiz`, meta: [{ name: 'robots', content: 'noindex,nofollow' }] }))

async function prepare() { await game.prepare(requestedTheme.value, requestedTopic.value, locale.value, requestedDifficulty.value) }
async function ask(kind: typeof confirmation.value) {
  confirmation.value = kind
  await nextTick()
  confirmHeading.value?.focus()
}
async function confirm() {
  const kind = confirmation.value
  confirmation.value = ''
  if (kind === 'hardcore') await game.start(true)
  else if (kind === 'end') await game.endSession(true)
  else await game.resetAndPrepare(requestedTheme.value || state.context.theme, requestedTopic.value || state.context.topic, locale.value, true)
}
function continueCurrent() {
  return navigateTo({ path: localPath('/play'), query: { theme: state.context.theme, topic: state.context.topic } })
}
function focusQuestion() {
  void nextTick(() => document.getElementById('question-heading')?.focus())
}
const onFocus = () => { if (phase.value === 'question' && !state.busy) void game.reconcile() }
onMounted(async () => {
  await game.restore()
  if (state.themeExcluded) {
    showError({ statusCode: 404, statusMessage: 'Page not found' })
    return
  }
  mounted.value = true
  window.addEventListener('focus', onFocus)
})
onUnmounted(() => { window.removeEventListener('focus', onFocus); game.dispose() })
watch(() => state.question?.id, focusQuestion)
watch(phase, value => {
  if (mounted.value && value === 'sessionResult' && !state.storageWarning) void navigateTo(localPath('/results'))
})
</script>

<template>
  <div class="game-shell quiz-web" :aria-busy="state.busy">
    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ feedbackAnnouncement }}</p>
    <div class="game-topline">
      <NuxtLink :to="localPath('/explore')" class="game-back">← {{ copy.discover }}</NuxtLink>
      <span v-if="state.context.completedRuns" class="muted">{{ state.context.completedRuns }} {{ copy.runs }}</span>
    </div>
    <p v-if="state.storageWarning" class="game-notice" role="status">{{ copy.storage }}</p>
    <section v-if="confirmation" class="confirmation surface" aria-labelledby="confirmation-heading">
      <p class="eyebrow">QuickQuiz</p>
      <h2 id="confirmation-heading" ref="confirmHeading" tabindex="-1">{{ confirmTitle }}</h2>
      <p>{{ confirmText }}</p>
      <div class="game-actions">
        <button class="primary-button" type="button" @click="confirm">{{ copy.confirm }}</button>
        <button class="secondary-button" type="button" @click="confirmation = ''">{{ copy.cancel }}</button>
      </div>
    </section>
    <p v-if="state.error" class="game-error" role="alert">{{ copy.error }}</p>
    <p v-if="state.busy" class="pending-status" role="status">{{ phase === 'levels' ? copy.preparing : copy.pending }}</p>

    <ClientOnly>
      <section v-if="routeConflict" class="surface recovery-card">
        <p class="eyebrow">{{ copy.session }} · {{ state.context.theme }}</p>
        <h1>{{ copy.conflict }}</h1><p>{{ copy.conflictText }}</p>
        <div class="game-actions">
          <button type="button" class="primary-button" :disabled="state.busy" @click="continueCurrent">{{ copy.continue }}</button>
          <button type="button" class="secondary-button" :disabled="state.busy" @click="ask('reset')">{{ copy.reset }}</button>
        </div>
      </section>
      <section v-else-if="topicMismatch" class="surface recovery-card">
        <p class="eyebrow">{{ state.context.theme }} / {{ state.context.topic }}</p>
        <h1>{{ copy.topicConflict }}</h1><p>{{ copy.topicConflictText }}</p>
        <div class="game-actions">
          <button v-if="canMoveTopic" type="button" class="primary-button" :disabled="state.busy" @click="prepare">{{ copy.prepare }} · {{ requestedTopic }}</button>
          <button type="button" class="secondary-button" :disabled="state.busy" @click="continueCurrent">{{ copy.continue }}</button>
          <button v-if="!canMoveTopic" type="button" class="secondary-button" :disabled="state.busy" @click="ask('reset')">{{ copy.reset }}</button>
        </div>
      </section>
      <section v-else-if="phase === 'welcome'" class="game-welcome">
        <p class="eyebrow">{{ copy.eyebrow }}</p>
        <h1>{{ copy.title }}</h1>
        <p class="game-lead">{{ copy.intro }}</p>
        <div v-if="requestedTheme && requestedTopic" class="welcome-subject surface">
          <span class="subject-mark" aria-hidden="true">✦</span>
          <div><p class="eyebrow">{{ requestedTheme }}</p><h2>{{ requestedTopic }}</h2></div>
          <button class="primary-button" type="button" :disabled="state.busy" @click="prepare">{{ copy.prepare }} →</button>
        </div>
        <p v-else>{{ copy.empty }} <NuxtLink :to="localPath('/explore')">{{ copy.discover }} →</NuxtLink></p>
      </section>

      <div v-else-if="phase === 'levels'" class="level-screen">
        <header class="game-section-heading">
          <p class="eyebrow">{{ state.context.theme }} / {{ topicLabel }}</p>
          <h1>{{ copy.difficulty }}</h1><p class="game-lead">{{ copy.difficultyIntro }}</p>
        </header>
        <fieldset class="level-grid" :disabled="state.busy">
          <legend class="sr-only">{{ copy.difficulty }}</legend>
          <label v-for="level in state.levels" :key="level.id" class="level-card surface" :class="{ selected: state.context.difficulty === level.id, exhausted: !level.available }">
            <input type="radio" name="difficulty" :value="level.id" :checked="state.context.difficulty === level.id" :disabled="!level.available" @change="game.chooseLevel(level.id)">
            <span class="level-number">0{{ level.id }}</span>
            <strong>{{ copy.levels[level.id - 1] }}</strong>
            <span class="level-description">{{ copy.descriptions[level.id - 1] }}</span>
            <span class="level-count">{{ level.available ? `${level.availableQuestionCount} ${level.availableQuestionCount === 1 ? copy.questionsSingular : copy.questions}` : copy.unavailable }}</span>
            <span class="muted">{{ level.optionCount }} {{ copy.options }}</span>
          </label>
        </fieldset>
        <p v-if="!state.levels.some(level => level.available) && !state.busy" role="status">{{ copy.noLevels }}</p>
        <div class="game-actions">
          <button class="primary-button" type="button" :disabled="state.busy || !game.selectedLevel.value?.available" @click="state.context.difficulty === 4 ? ask('hardcore') : game.start()">{{ copy.start }} →</button>
          <button v-if="state.error" class="secondary-button" type="button" :disabled="state.busy" @click="game.prepare(state.context.theme, state.context.topic, locale)">{{ copy.retry }}</button>
          <button v-if="state.context.completedRuns" class="secondary-button" type="button" :disabled="state.busy" @click="ask('end')">{{ copy.end }}</button>
        </div>
        <div v-if="state.topics.filter(topic => topic.available && topic.id !== state.context.topic).length" class="other-topics">
          <h2>{{ copy.anotherTopic }}</h2>
          <button v-for="topic in state.topics.filter(topic => topic.available && topic.id !== state.context.topic)" :key="topic.id" type="button" class="tag-chip" :disabled="state.busy" @click="game.nextTopic(topic.id, locale)">{{ topic.label }}</button>
        </div>
      </div>

      <section v-else-if="phase === 'question' && state.question" class="question-screen">
        <header class="question-meta">
          <span class="eyebrow">{{ state.context.theme }} / {{ topicLabel }}</span>
          <span>{{ copy.levels[state.context.difficulty - 1] }}</span>
        </header>
        <div class="question-progress"><span>{{ copy.question }} {{ state.question.current }} {{ copy.of }} {{ state.question.total }}</span><progress :value="state.question.current - 1" :max="state.question.total" :aria-label="`${copy.question} ${state.question.current} ${copy.of} ${state.question.total}`" /></div>
        <div class="question-card surface">
          <h1 id="question-heading" tabindex="-1" class="question-heading">{{ copy.question }} {{ state.question.current }}</h1>
          <div v-if="state.feedback" class="answer-feedback" :class="`answer-feedback--${state.feedback}`">
            <span class="feedback-icon" aria-hidden="true">
              <UIcon v-if="state.feedback === 'correct'" name="i-lucide-check" />
              <UIcon v-else-if="state.feedback === 'wrong'" name="i-lucide-x" />
              <UIcon v-else name="i-lucide-info" />
            </span>
            <div class="feedback-copy">
              <span class="feedback-label">{{ state.feedback === 'synchronized' ? copy.roundStatus : `${copy.previousAnswer} · ${copy.question} ${state.question.current - 1}` }}</span>
              <strong>{{ copy[state.feedback] }}</strong>
            </div>
          </div>
          <div class="question-prompt"><QuizMarkdown :content="state.question.prompt" /></div>
          <fieldset class="answer-list" :disabled="state.busy || Boolean(confirmation)">
            <legend>{{ copy.choose }}</legend>
            <button v-for="(option, index) in state.question.options" :key="`${state.question.id}:${option.id}`" class="answer-option" type="button" @click="game.answer(option.id, state.question.id)">
              <span class="option-letter" aria-hidden="true">{{ String.fromCharCode(65 + index) }}</span>
              <QuizMarkdown :content="option.text" />
            </button>
          </fieldset>
        </div>
        <button type="button" class="game-text-button" :disabled="state.busy" @click="ask('end')">{{ copy.end }}</button>
      </section>

      <section v-else-if="phase === 'runResult' && state.result" class="run-result">
        <p class="eyebrow">{{ state.context.theme }} / {{ state.result.topic }}</p>
        <h1>{{ copy.result }}</h1><p class="game-lead">{{ copy.resultIntro }}</p>
        <div class="result-stats surface">
          <div><strong>{{ state.result.stats.answered }}</strong><span>{{ copy.answered }}</span></div>
          <div><strong>{{ state.result.stats.correct }}</strong><span>{{ copy.hits }}</span></div>
          <div><strong>{{ state.result.stats.wrong }}</strong><span>{{ copy.misses }}</span></div>
          <div><strong>{{ state.result.stats.accuracyPercent }}%</strong><span>{{ copy.accuracy }}</span></div>
        </div>
        <div class="game-actions">
          <button type="button" class="primary-button" :disabled="state.busy" @click="game.nextTopic(state.context.topic, locale)">{{ copy.next }} →</button>
          <button type="button" class="secondary-button" :disabled="state.busy" @click="ask('end')">{{ copy.end }}</button>
          <button v-if="state.error" type="button" class="secondary-button" :disabled="state.busy" @click="game.reconcile()">{{ copy.reconcile }}</button>
        </div>
        <section v-if="state.solutionQuestionId" class="solution-panel surface" aria-live="polite">
          <h2>{{ copy.explanation }}</h2>
          <p v-if="state.solutionBusy" role="status">{{ copy.generating }}</p>
          <p v-else-if="state.solutionError" role="alert">{{ copy.solutionUnavailable }}</p>
          <QuizMarkdown v-else-if="state.solution" :content="state.solution.explanation" />
          <button type="button" class="secondary-button" :disabled="state.solutionBusy" @click="game.dismissExplanation()">{{ copy.back }}</button>
        </section>
        <section v-else class="answer-review">
          <h2>{{ copy.review }}</h2>
          <article v-for="(answer, index) in state.result.answers" :key="answer.questionId" class="review-item surface">
            <span class="review-outcome" :class="{ incorrect: !answer.correct }">{{ index + 1 }} · {{ answer.correct ? copy.hits : copy.misses }}</span>
            <QuizMarkdown :content="answer.prompt" />
            <button v-if="!answer.correct" type="button" class="game-text-button" :disabled="state.solutionBusy" @click="game.explanation(answer.questionId)">{{ copy.explain }} →</button>
          </article>
        </section>
      </section>

      <section v-else-if="phase === 'sessionResult'" class="run-result">
        <p class="eyebrow">{{ copy.session }}</p>
        <h1>{{ copy.result }}</h1><p class="game-lead">{{ copy.resultIntro }}</p>
        <div class="result-stats surface">
          <div><strong>{{ state.context.totals.answered }}</strong><span>{{ copy.answered }}</span></div>
          <div><strong>{{ state.context.totals.correct }}</strong><span>{{ copy.hits }}</span></div>
          <div><strong>{{ state.context.totals.wrong }}</strong><span>{{ copy.misses }}</span></div>
          <div><strong>{{ state.context.totals.accuracyPercent }}%</strong><span>{{ copy.accuracy }}</span></div>
        </div>
        <div class="game-actions">
          <button type="button" class="primary-button" :disabled="state.busy" @click="game.prepare(requestedTheme || state.context.theme, requestedTopic || state.context.topic, locale, requestedDifficulty)">{{ copy.prepare }} →</button>
          <NuxtLink :to="localPath('/explore')" class="secondary-button">{{ copy.discover }}</NuxtLink>
        </div>
      </section>

      <section v-else-if="['uncertainStart', 'uncertainAnswer', 'expired', 'fatalLoss', 'closing'].includes(phase)" class="surface recovery-card">
        <p class="eyebrow">QuickQuiz · {{ copy.session }}</p>
        <h1>{{ phase === 'expired' ? copy.expiredTitle : phase === 'fatalLoss' ? copy.fatalTitle : phase === 'closing' ? copy.closingTitle : copy.uncertainTitle }}</h1>
        <p>{{ phase === 'uncertainStart' ? copy.uncertainStart : phase === 'uncertainAnswer' ? copy.uncertainAnswer : phase === 'expired' ? copy.expired : phase === 'fatalLoss' ? copy.fatal : copy.closing }}</p>
        <div class="game-actions">
          <button v-if="phase === 'uncertainAnswer'" type="button" class="primary-button" :disabled="state.busy" @click="game.reconcile()">{{ copy.reconcile }}</button>
          <button v-if="phase === 'closing'" type="button" class="primary-button" :disabled="state.busy" @click="game.retryClose()">{{ copy.retryClose }}</button>
          <button v-else type="button" class="secondary-button" :disabled="state.busy" @click="ask('reset')">{{ copy.reset }}</button>
        </div>
      </section>
      <template #fallback><p class="pending-status" role="status">{{ copy.waiting }}</p></template>
    </ClientOnly>
  </div>
</template>

<style scoped>
.quiz-web { width: 90%; max-width: 1760px; margin-inline: auto; min-height: calc(100svh - 160px); padding-block: 1.5rem 4rem; }
.game-topline, .question-meta { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
.game-topline { margin-bottom: 2.5rem; }
.game-back { font-weight: 650; text-decoration: none; }
.game-welcome { padding-block: clamp(2rem, 6vw, 6rem); max-width: 68rem; margin-inline: auto; }
.game-welcome h1, .game-section-heading h1, .run-result > h1 { font-size: clamp(2.2rem, 5vw, 4.3rem); line-height: 1.1; letter-spacing: -.045em; max-width: 16ch; margin-block: 1rem; }
.game-lead { color: #5b6c6a; font-size: 1.1rem; line-height: 1.7; max-width: 55ch; }
.welcome-subject { margin-top: 2.5rem; padding: 1.5rem; display: flex; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
.welcome-subject > div { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.welcome-subject h2 { font-size: 1.35rem; }
.subject-mark { display: grid; place-items: center; width: 3.5rem; height: 3.5rem; border-radius: 1rem; background: #dff3ec; color: #126a57; font-size: 1.6rem; }
.level-screen, .run-result { max-width: 80rem; margin-inline: auto; }
.level-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; padding: 0; border: 0; margin-block: 2.5rem; }
.level-card { position: relative; display: flex; flex-direction: column; gap: .7rem; padding: 1.6rem; cursor: pointer; border: 2px solid #dce5e0; }
.level-card.selected { border-color: #147d64; background: #f0faf5; box-shadow: 0 8px 30px #17694c0a; }
.level-card.exhausted { opacity: .65; cursor: default; }
.level-card input { position: absolute; inset-block-start: 1.5rem; inset-inline-end: 1.5rem; accent-color: #147d64; width: 1.2rem; height: 1.2rem; }
.level-card strong { font-size: 1.4rem; }
.level-number { color: #467969; font-size: 1rem; font-weight: 750; margin-bottom: 1rem; }
.level-description { min-height: 5em; line-height: 1.6; color: #536962; }
.level-count { margin-top: auto; font-weight: 650; font-size: .88rem; }
.game-actions { display: flex; flex-wrap: wrap; gap: .8rem; margin-block: 1.5rem; }
.game-actions > * { max-width: 100%; white-space: normal; }
.question-screen { max-width: 60rem; margin-inline: auto; }
.question-meta { color: #46625a; margin-bottom: 1.8rem; overflow-wrap: anywhere; }
.question-meta .eyebrow { margin: 0; }
.question-progress { display: flex; align-items: center; gap: 1rem; font-size: .85rem; margin-bottom: 1rem; }
.question-progress span { white-space: nowrap; }
progress { flex: 1; width: 0; height: .45rem; accent-color: #147d64; }
.question-card { padding: clamp(1.1rem, 4vw, 3rem); }
.question-heading { font-size: .85rem; font-weight: 650; color: #587167; margin-bottom: 1rem; }
.question-prompt { font-size: clamp(1.2rem, 2.4vw, 1.75rem); font-weight: 500; line-height: 1.6; letter-spacing: normal; margin-bottom: 2rem; overflow-wrap: anywhere; }
.answer-list { border: 0; padding: 0; display: grid; gap: .8rem; min-width: 0; }
.answer-list legend { font-weight: 650; margin-bottom: 1rem; font-size: .85rem; color: #587167; }
.answer-option { display: flex; align-items: center; gap: 1rem; text-align: left; border: 1px solid #d9e3dd; background: #fff; padding: 1rem 1.2rem; border-radius: 1rem; width: 100%; min-width: 0; font: inherit; font-size: 1.125rem; font-weight: 500; line-height: 1.7; color: inherit; cursor: pointer; transition: background .12s, border-color .12s; }
.answer-option:hover { border-color: #147d64; background: #f4fbf7; }
.answer-option > :last-child { min-width: 0; overflow-wrap: anywhere; }
.option-letter { display: grid; place-items: center; flex: 0 0 2rem; width: 2rem; height: 2rem; background: #edf4ef; border-radius: .6rem; font-size: .82rem; font-weight: 750; color: #47685b; }
.answer-feedback { --feedback-ink: #245369; --feedback-tint: #edf4f8; --feedback-line: #adc8d6; display: flex; align-items: center; gap: .85rem; margin-block: 0 1.5rem; padding: 1rem 1.1rem; border: 1px solid var(--feedback-line); border-inline-start: 4px solid var(--feedback-ink); border-radius: .85rem; background: var(--feedback-tint); color: var(--feedback-ink); }
.answer-feedback--correct { --feedback-ink: #176344; --feedback-tint: #eaf7ee; --feedback-line: #a9d8b8; }
.answer-feedback--wrong { --feedback-ink: #9b3038; --feedback-tint: #fff0f0; --feedback-line: #edb6bb; }
.feedback-icon { display: grid; place-items: center; flex: 0 0 2.4rem; width: 2.4rem; height: 2.4rem; border-radius: 50%; background: var(--feedback-ink); color: #fff; }
.feedback-icon .iconify { width: 1.35rem; height: 1.35rem; }
.feedback-copy { min-width: 0; overflow-wrap: anywhere; }
.feedback-label { display: block; font-size: .75rem; font-weight: 650; margin-bottom: .15rem; }
.feedback-copy strong { display: block; font-size: 1rem; line-height: 1.5; font-weight: 750; }
.game-text-button { color: #216952; background: transparent; border: 0; font: inherit; font-weight: 650; padding: .5rem 0; text-decoration: underline; text-underline-offset: .22em; cursor: pointer; text-align: left; }
.game-error, .game-notice { background: #fff4df; color: #72551d; padding: 1rem 1.25rem; border-radius: .8rem; margin-bottom: 1.25rem; }
.game-error { background: #fdf0e9; color: #8c402d; }
.pending-status { color: #536b62; padding-block: .5rem; }
.recovery-card, .confirmation { max-width: 44rem; padding: clamp(1.25rem, 5vw, 3rem); margin: 2rem auto; line-height: 1.75; }
.recovery-card h1, .confirmation h2 { font-size: clamp(1.8rem, 4vw, 2.8rem); line-height: 1.2; margin-bottom: 1rem; }
.confirmation { border: 2px solid #147d64; }
.result-stats { display: grid; grid-template-columns: repeat(4, 1fr); margin-block: 2rem; padding: 2rem 1rem; }
.result-stats > div { display: flex; flex-direction: column; align-items: center; gap: .5rem; min-width: 0; text-align: center; }
.result-stats strong { font-size: clamp(1.5rem, 3vw, 2.5rem); font-weight: 750; color: #20664e; }
.result-stats span { font-size: .85rem; color: #5a7066; }
.answer-review, .solution-panel { margin-top: 3rem; max-width: 60rem; }
.answer-review h2, .other-topics h2 { font-size: 1.3rem; margin-bottom: 1.2rem; }
.review-item { padding: 1.5rem; margin-block: 1rem; }
.review-outcome { display: inline-block; font-size: .8rem; font-weight: 750; color: #18684d; margin-bottom: .75rem; }
.review-outcome.incorrect { color: #9d4d2d; }
.solution-panel { padding: 1.5rem; }
.other-topics { margin-top: 3rem; }
.other-topics button { margin: .25rem .5rem .25rem 0; cursor: pointer; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; border: 0; }
button:disabled { opacity: .55; cursor: wait; }
@media (max-width: 1000px) { .level-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) {
  .level-grid { grid-template-columns: 1fr; }
  .level-description { min-height: auto; }
  .game-actions > * { width: 100%; }
  .welcome-subject { gap: 1rem; }
  .welcome-subject .primary-button { width: 100%; }
  .question-meta { align-items: flex-start; flex-direction: column; gap: .5rem; }
  .question-progress { flex-wrap: wrap; }
  .result-stats { grid-template-columns: repeat(2, 1fr); row-gap: 1.5rem; }
  .answer-option { padding: .8rem; gap: .7rem; }
  .answer-feedback { align-items: flex-start; padding: .85rem; gap: .7rem; }
}
@media (prefers-reduced-motion: reduce) { .answer-option { transition: none; } }
</style>
