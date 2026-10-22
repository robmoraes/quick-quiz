import type { WebLocale } from '#shared/config'

const pt = {
  discover: 'Explorar', about: 'Aprender um pouco, todos os dias.', skip: 'Ir para o conteúdo', language: 'Idioma',
  homeEyebrow: 'SUA CURIOSIDADE TEM ESPAÇO AQUI', homeTitle: 'Um mundo de assuntos.\nUma pergunta de cada vez.',
  homeDescription: 'Descubra novos interesses, desafie o que você já sabe e transforme pequenas pausas em grandes descobertas.',
  searchLabel: 'O que você quer aprender?', searchPlaceholder: 'Busque um tema ou assunto...', searchAction: 'Explorar assuntos',
  areas: 'Siga sua curiosidade', areasDescription: 'Escolha uma área ou encontre uma conexão inesperada.',
  themes: 'Temas', topics: 'Tópicos', allThemes: 'Todos os temas', allTopics: 'Todos os tópicos', viewAll: 'Ver todos',
  themeCollection: 'Seu próximo assunto favorito', themeDescription: 'Um universo de possibilidades para aprender no seu ritmo.',
  question: 'questão', questions: 'questões', topic: 'tópico', topicCount: 'tópicos', ready: 'Pronto para jogar', comingSoon: 'Conteúdo em preparação',
  exploreTitle: 'Encontre o que desperta sua curiosidade.', exploreDescription: 'Temas para explorar. Tópicos para ir além. Você escolhe o caminho.',
  search: 'Busca', theme: 'Tema', themeTag: 'Área do tema', topicTag: 'Interesse do tópico', difficulty: 'Nível',
  any: 'Todos', filters: 'Refine sua descoberta', clearFilters: 'Limpar filtros', applyFilters: 'Aplicar filtros',
  results: 'resultados', noMatches: 'Ainda não encontramos essa combinação.', noMatchesDescription: 'Tente outra busca ou remova algum filtro para descobrir mais assuntos.',
  emptyTitle: 'Novos desafios estão a caminho.', emptyDescription: 'Este conteúdo ainda não tem questões disponíveis. Explore outros assuntos enquanto isso.',
  failedTitle: 'Não conseguimos carregar os assuntos.', failedDescription: 'Tente novamente em instantes. Sua busca continua aqui.', retry: 'Tentar novamente',
  loading: 'Carregando assuntos...', previous: 'Anterior', next: 'Próxima', page: 'Página', of: 'de',
  related: 'Conecte outros interesses', chooseTopic: 'Escolha seu próximo desafio', themeIntro: 'Explore os tópicos e escolha um desafio para começar.',
  topicEyebrow: 'UM ASSUNTO, NOVAS DESCOBERTAS', chooseLevel: 'Como você quer se desafiar?',
  levelDescription: 'Escolha um nível. As questões disponíveis para sua sessão serão confirmadas ao preparar o quiz.',
  play: 'Preparar quiz', easy: 'Essencial', normal: 'Intermediário', hard: 'Avançado', hardcore: 'Hardcore',
  easyDescription: 'Construa uma boa base.', normalDescription: 'Conecte conceitos e pratique.', hardDescription: 'Vá além do que é familiar.', hardcoreDescription: 'Um erro encerra a sessão.',
  relatedDescription: 'O conhecimento se conecta. Explore outros tópicos com estes interesses.',
  footer: 'Curiosidade em movimento.', footerNote: 'Explore. Pratique. Descubra.', backHome: 'Voltar para o início',
  notFound: 'Este assunto não foi encontrado.', unavailable: 'O QuickQuiz está temporariamente indisponível.',
  whyTitle: 'Cada pergunta abre uma possibilidade.', whyDescription: 'Sem pressa, sem cadastro. Escolha algo que você gosta — ou algo que você ainda não conhece.',
  stepOne: 'Encontre um assunto', stepOneDescription: 'Navegue por temas e conexões.',
  stepTwo: 'Escolha seu desafio', stepTwoDescription: 'Comece pelo nível que faz sentido.',
  stepThree: 'Aprenda jogando', stepThreeDescription: 'Veja seu resultado e siga descobrindo.',
  filterApplied: 'Filtros aplicados', noTags: 'Nenhum interesse disponível nesta seleção.', moreTags: 'Mais interesses', filterOptionsWithoutSearch: 'Opções pelos filtros selecionados, sem o texto da busca.',
} as const

const en: Record<keyof typeof pt, string> = {
  discover: 'Explore', about: 'Learn a little, every day.', skip: 'Skip to content', language: 'Language',
  homeEyebrow: 'MAKE ROOM FOR YOUR CURIOSITY', homeTitle: 'A world of subjects.\nOne question at a time.',
  homeDescription: 'Find new interests, challenge what you know, and turn small breaks into big discoveries.',
  searchLabel: 'What would you like to learn?', searchPlaceholder: 'Search for a theme or subject...', searchAction: 'Explore subjects',
  areas: 'Follow your curiosity', areasDescription: 'Choose an area or discover an unexpected connection.',
  themes: 'Themes', topics: 'Topics', allThemes: 'All themes', allTopics: 'All topics', viewAll: 'View all',
  themeCollection: 'Your next favorite subject', themeDescription: 'A world of possibilities to explore at your own pace.',
  question: 'question', questions: 'questions', topic: 'topic', topicCount: 'topics', ready: 'Ready to play', comingSoon: 'Content in preparation',
  exploreTitle: 'Find what sparks your curiosity.', exploreDescription: 'Themes to explore. Topics to go deeper. Choose your own path.',
  search: 'Search', theme: 'Theme', themeTag: 'Theme area', topicTag: 'Topic interest', difficulty: 'Level',
  any: 'All', filters: 'Refine your discovery', clearFilters: 'Clear filters', applyFilters: 'Apply filters',
  results: 'results', noMatches: 'We could not find that combination yet.', noMatchesDescription: 'Try another search or remove a filter to discover more subjects.',
  emptyTitle: 'New challenges are on their way.', emptyDescription: 'This content does not have playable questions yet. Explore other subjects in the meantime.',
  failedTitle: 'We could not load the subjects.', failedDescription: 'Please try again shortly. Your search is still here.', retry: 'Try again',
  loading: 'Loading subjects...', previous: 'Previous', next: 'Next', page: 'Page', of: 'of',
  related: 'Connect your interests', chooseTopic: 'Choose your next challenge', themeIntro: 'Explore the topics and choose a challenge to get started.',
  topicEyebrow: 'ONE SUBJECT, NEW DISCOVERIES', chooseLevel: 'How would you like to challenge yourself?',
  levelDescription: 'Choose a level. Available questions for your session will be confirmed when you prepare the quiz.',
  play: 'Prepare quiz', easy: 'Essentials', normal: 'Intermediate', hard: 'Advanced', hardcore: 'Hardcore',
  easyDescription: 'Build a strong foundation.', normalDescription: 'Connect concepts and practice.', hardDescription: 'Go beyond the familiar.', hardcoreDescription: 'One wrong answer ends the session.',
  relatedDescription: 'Knowledge connects. Explore other topics with these shared interests.',
  footer: 'Curiosity in motion.', footerNote: 'Explore. Practice. Discover.', backHome: 'Back to home',
  notFound: 'This subject was not found.', unavailable: 'QuickQuiz is temporarily unavailable.',
  whyTitle: 'Every question opens a possibility.', whyDescription: 'No rush, no account. Choose something you love — or something you have not discovered yet.',
  stepOne: 'Find a subject', stepOneDescription: 'Browse themes and connections.',
  stepTwo: 'Choose your challenge', stepTwoDescription: 'Start at a level that feels right.',
  stepThree: 'Learn by playing', stepThreeDescription: 'See your results and keep discovering.',
  filterApplied: 'Filters applied', noTags: 'No interests available in this selection.', moreTags: 'More interests', filterOptionsWithoutSearch: 'Options match selected filters, without the search text.',
}
export type MessageKey = keyof typeof pt
export function message(locale: WebLocale, key: MessageKey): string { return (locale === 'pt-BR' ? pt : en)[key] }
export function countLabel(locale: WebLocale, count: number, kind: 'questions' | 'topics'): string {
  const singular = count === 1
  return message(locale, kind === 'questions' ? singular ? 'question' : 'questions' : singular ? 'topic' : 'topicCount')
}

const tagLabels: Record<string, { 'pt-BR': string; 'en-US': string }> = {
  dev: { 'pt-BR': 'Desenvolvimento', 'en-US': 'Development' },
  cloud: { 'pt-BR': 'Nuvem', 'en-US': 'Cloud' },
  devops: { 'pt-BR': 'DevOps', 'en-US': 'DevOps' },
  seguranca: { 'pt-BR': 'Segurança', 'en-US': 'Security' },
  security: { 'pt-BR': 'Segurança', 'en-US': 'Security' },
  literatura: { 'pt-BR': 'Literatura', 'en-US': 'Literature' },
  agronomia: { 'pt-BR': 'Agronomia', 'en-US': 'Agronomy' },
  tecnologia: { 'pt-BR': 'Tecnologia', 'en-US': 'Technology' },
  ciencia: { 'pt-BR': 'Ciência', 'en-US': 'Science' },
  dados: { 'pt-BR': 'Dados', 'en-US': 'Data' },
  observabilidade: { 'pt-BR': 'Observabilidade', 'en-US': 'Observability' },
}
export function tagLabel(slug: string, locale: WebLocale): string { return tagLabels[slug]?.[locale] ?? slug }
