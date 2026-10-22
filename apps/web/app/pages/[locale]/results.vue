<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useQuizGame } from '../../composables/useQuizGame'

const config = useRuntimeConfig()
const { locale, localPath } = useWebLocale()
const game = useQuizGame({ baseUrl: config.public.apiBaseUrl })
const { state, phase } = game
const ready = ref(false)
const copy = computed(() => locale.value === 'pt-BR' ? {
  eyebrow: 'Cada pergunta conta.', title: 'Um passo a mais no seu aprendizado.',
  intro: 'Sua sessão foi encerrada. Este resumo reúne apenas rodadas com resultados confirmados pelo servidor nesta aba.',
  exhausted: 'Você respondeu todas as questões disponíveis deste tema nesta sessão. Muito bem!',
  answered: 'Respondidas', correct: 'Corretas', wrong: 'Incorretas', accuracy: 'Aproveitamento', rounds: 'Rodadas concluídas',
  discover: 'Descobrir meu próximo assunto', again: 'Jogar novamente neste tópico',
  note: 'Este resumo fica somente nesta aba. O servidor não fornece a restauração do histórico completo de uma sessão encerrada. Prompts, respostas e explicações não são guardados aqui.',
  emptyTitle: 'Seu próximo resultado começa com uma pergunta.', empty: 'Não há um resultado de sessão encerrada disponível nesta aba.',
  resume: 'Voltar à sessão em andamento', loading: 'Carregando o resumo desta aba…',
  recent: 'Últimas rodadas', topic: 'Tópico', level: 'Nível', answeredColumn: 'Respondidas', accuracyColumn: 'Aproveitamento',
  levels: ['Essencial', 'Intermediário', 'Avançado', 'Hardcore'],
  storage: 'O armazenamento desta aba está indisponível. Não foi possível recuperar um resumo salvo.',
} : {
  eyebrow: 'Every question counts.', title: 'Another step in your learning.',
  intro: 'Your session has ended. This summary includes only rounds with server-confirmed results in this tab.',
  exhausted: 'You answered all available questions in this theme for this session. Well done!',
  answered: 'Answered', correct: 'Correct', wrong: 'Incorrect', accuracy: 'Accuracy', rounds: 'Completed rounds',
  discover: 'Discover my next subject', again: 'Play this topic again',
  note: 'This summary lives only in this tab. The server cannot restore the complete history of a closed session. Prompts, answers, and explanations are not stored here.',
  emptyTitle: 'Your next result starts with a question.', empty: 'No completed session result is available in this tab.',
  resume: 'Return to the current session', loading: 'Loading the summary for this tab…',
  recent: 'Recent rounds', topic: 'Topic', level: 'Level', answeredColumn: 'Answered', accuracyColumn: 'Accuracy',
  levels: ['Essential', 'Intermediate', 'Advanced', 'Hardcore'],
  storage: 'Storage is unavailable in this tab. A saved summary could not be recovered.',
})
const playUrl = computed(() => ({ path: localPath('/play'), query: { theme: state.context.theme, topic: state.context.topic } }))
useHead(() => ({ title: `${copy.value.accuracy} · QuickQuiz`, meta: [{ name: 'robots', content: 'noindex,nofollow' }] }))
onMounted(async () => { await game.restore(); ready.value = true })
onUnmounted(() => game.dispose())
</script>

<template>
  <div class="page-shell session-results">
    <ClientOnly>
      <p v-if="!ready" role="status">{{ copy.loading }}</p>
      <p v-if="state.storageWarning" class="storage-notice" role="status">{{ copy.storage }}</p>
      <template v-if="ready && phase === 'sessionResult'">
        <header class="results-heading">
          <div class="completion-mark" aria-hidden="true">✓</div>
          <p class="eyebrow">{{ copy.eyebrow }}</p>
          <h1>{{ copy.title }}</h1>
          <p class="results-intro">{{ copy.intro }}</p>
          <p v-if="state.context.closeReason === 'exhausted'" class="exhaustion-note">{{ copy.exhausted }}</p>
        </header>
        <section class="session-stats surface" :aria-label="copy.title">
          <div><strong>{{ state.context.totals.answered }}</strong><span>{{ copy.answered }}</span></div>
          <div><strong>{{ state.context.totals.correct }}</strong><span>{{ copy.correct }}</span></div>
          <div><strong>{{ state.context.totals.wrong }}</strong><span>{{ copy.wrong }}</span></div>
          <div><strong>{{ state.context.totals.accuracyPercent }}%</strong><span>{{ copy.accuracy }}</span></div>
        </section>
        <p class="round-count">{{ state.context.completedRuns }} {{ copy.rounds.toLocaleLowerCase(locale) }}</p>
        <div class="results-actions">
          <NuxtLink :to="localPath('/explore')" class="primary-button">{{ copy.discover }} →</NuxtLink>
          <NuxtLink :to="playUrl" class="secondary-button">{{ copy.again }}</NuxtLink>
        </div>
        <section v-if="state.context.summaries.length" class="recent-rounds">
          <h2>{{ copy.recent }}</h2>
          <ul class="round-list">
            <li v-for="(run, index) in state.context.summaries" :key="run.runId" class="surface">
              <span class="round-number">{{ state.context.completedRuns - state.context.summaries.length + index + 1 }}</span>
              <div class="round-topic"><strong>{{ run.topic }}</strong><span>{{ copy.levels[run.difficulty - 1] }} · {{ run.stats.answered }} {{ copy.answered.toLocaleLowerCase(locale) }}</span></div>
              <strong class="round-accuracy">{{ run.stats.accuracyPercent }}%</strong>
            </li>
          </ul>
        </section>
        <p class="summary-note">{{ copy.note }}</p>
      </template>
      <section v-else-if="ready" class="empty-result surface">
        <p class="eyebrow">QuickQuiz</p><h1>{{ copy.emptyTitle }}</h1><p>{{ copy.empty }}</p>
        <div class="results-actions">
          <NuxtLink v-if="state.context.sessionId" :to="playUrl" class="primary-button">{{ copy.resume }}</NuxtLink>
          <NuxtLink :to="localPath('/explore')" class="secondary-button">{{ copy.discover }}</NuxtLink>
        </div>
      </section>
      <template #fallback><p role="status">{{ copy.loading }}</p></template>
    </ClientOnly>
  </div>
</template>

<style scoped>
.session-results { max-width: 72rem; padding-block: clamp(2rem, 5vw, 5rem); min-height: calc(100svh - 160px); }
.results-heading { max-width: 46rem; margin: 0 auto; text-align: center; }
.completion-mark { width: 4rem; height: 4rem; display: grid; place-items: center; margin: 0 auto 1.5rem; background: #ddf3e8; color: #147d64; border-radius: 50%; font-size: 2rem; }
.results-heading h1 { font-size: clamp(2.2rem, 5vw, 4rem); line-height: 1.1; letter-spacing: -.045em; margin-block: 1rem; }
.results-intro { color: #5b6c6a; font-size: 1.05rem; line-height: 1.8; }
.exhaustion-note { background: #eaf7ef; color: #22634e; border-radius: .8rem; padding: 1rem; margin-top: 1rem; }
.session-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); padding: 2rem 1rem; gap: 1rem; margin-top: 2.5rem; }
.session-stats > div { display: flex; flex-direction: column; align-items: center; text-align: center; gap: .5rem; }
.session-stats strong { font-size: clamp(2rem, 4vw, 3rem); color: #216951; letter-spacing: -.04em; }
.session-stats span { font-size: .9rem; color: #597065; }
.round-count { text-align: center; color: #62776d; margin-top: 1rem; }
.results-actions { display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap; margin-block: 2.5rem; }
.results-actions > * { max-width: 100%; white-space: normal; text-align: center; }
.recent-rounds { max-width: 46rem; margin: 3rem auto 0; }
.recent-rounds h2 { font-size: 1.3rem; margin-bottom: 1rem; }
.round-list { list-style: none; padding: 0; display: grid; gap: .7rem; }
.round-list li { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; }
.round-number { display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: .6rem; background: #edf5ef; color: #527160; font-size: .8rem; flex-shrink: 0; }
.round-topic { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: .3rem; overflow-wrap: anywhere; }
.round-topic span { color: #61736a; font-size: .85rem; }
.round-accuracy { color: #267157; }
.summary-note { color: #697a71; font-size: .85rem; line-height: 1.8; max-width: 46rem; margin: 2rem auto 0; }
.empty-result { max-width: 46rem; padding: clamp(1.5rem, 5vw, 3rem); margin: 2rem auto; }
.empty-result h1 { font-size: clamp(1.8rem, 4vw, 2.8rem); line-height: 1.2; margin-block: 1rem; }
.storage-notice { background: #fff4df; color: #72551d; padding: 1rem; border-radius: .8rem; }
@media (max-width: 560px) { .session-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; } .results-actions > * { width: 100%; } }
</style>
