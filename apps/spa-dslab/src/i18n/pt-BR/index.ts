export default {
  app: {
    title: 'Quick Quiz DSLab',
    subtitle: 'Quiz do Distributed Systems Lab',
  },
  actions: {
    next: 'Avancar',
    startRun: 'Iniciar run',
    session: 'Sessao',
    endSession: 'Encerrar sessão',
    changeTopic: 'Trocar tópico',
    newRun: 'Nova run',
    newSession: 'Nova sessao',
  },
  rules: {
    button: 'RFC 0001 - Game Rules Protocol',
    title: 'RFC 0001 - Game Rules Protocol',
    close: 'Fechar RFC',
  },
  confirm: {
    cancel: 'Cancelar',
    sessionResultTitle: 'Encerrar sessão?',
    sessionResultMessage:
      'Isso encerra a sessão atual. Qualquer run ativa será finalizada, o resultado final da sessão será exibido e esta sessão não poderá continuar.',
    sessionResultConfirm: 'Encerrar sessão',
    hardcoreTitle: 'Entrar na severidade fatal?',
    hardcoreMessage:
      'Uma resposta errada reinicia toda a sessão atual. Não haverá tela de resultado para a run perdida.',
    hardcoreConfirm: 'Aceitar risco fatal',
  },
  form: {
    topic: 'Escolha o Tópico',
  },
  topicInfo: {
    title: 'Sobre este tópico',
  },
  settings: {
    title: 'Configuracoes',
    close: 'Fechar configuracoes',
    locale: 'Locale',
    cancel: 'Cancelar',
    save: 'Salvar',
    locales: {
      browser: 'Browser',
      ptBR: 'pt-BR',
      enUS: 'en-US',
    },
    audio: {
      title: 'Audio',
      soundEffects: 'Sound Effects',
      enableSounds: 'Ativar sons',
      soundsVolume: 'Volume dos sons',
      music: 'Música',
      enableMusic: 'Ativar música',
      musicVolume: 'Volume da música',
    },
  },
  beforeGame: {
    startPanel: {
      title: 'Tópico',
    },
    difficultyPanel: {
      title: 'Nível',
    },
  },
  game: {
    questionProgress: 'Pergunta {current} de {total}',
    sessionAvailability: 'Sessao {available}/{total}',
    topicAvailability: 'Topico {available}/{total}',
    availabilityCards: {
      questionsLabel: 'Questões',
      answeredLabel: 'respondido',
      totalLabel: 'total',
      sessionLabel: 'Sessão',
      topicLabel: 'Tópico',
      session: {
        activeTooltip:
          'Nessa sessão ainda restam <strong>{available}</strong> questões do total de <strong>{total}</strong>.',
        inactiveTooltip:
          'Existem <strong>{total}</strong> questões disponíveis antes de iniciar uma sessão.',
      },
      topic: {
        activeTooltip:
          'Nessa sessão, este tópico ainda tem <strong>{available}</strong> questões restantes do total de <strong>{total}</strong>.',
        inactiveTooltip: 'Este tópico tem <strong>{total}</strong> questões disponíveis.',
      },
    },
    correct: 'Tests Passed',
    wrong: 'Tests Failed',
    runSynchronized: 'Estado da run sincronizado. Continue pela pergunta atual.',
    runStateCheckFailed: 'Nao foi possivel conferir o estado da run. Continue se a pergunta estiver visivel.',
  },
  devtools: {
    welcome: 'Bem vindo ao Quick Quiz DSLab, o quiz do Distributed Systems Lab',
  },
  fatalLoss: {
    eyebrow: 'severity.FATAL',
    title: 'GAME OVER',
    message: 'Uma resposta errada rachou a sessão. Todo o progresso foi perdido.',
  },
  result: {
    title: 'Resultado',
    runTitle: 'Run',
    sessionTitle: 'Sessão',
    sessionFinished: 'Sessao encerrada',
    answered: 'Tests',
    correct: 'Passed',
    wrong: 'Failed',
    accuracy: 'Precisão',
    answerCorrect: 'Acertou',
    answerWrong: 'Errou',
    icon: 'icone',
    pullRequest: 'Pull request',
    codeReview: 'code review',
    reviewAccept: 'accept',
    reviewRejected: 'rejected',
    noAnswers: 'Nenhuma resposta nesta sessao',
    finished: 'Run finalizada',
    reasons: {
      player_quit: 'Run encerrada pelo jogador',
      max_questions_reached: 'Run completa',
      no_questions_left: 'Acabaram as questoes',
      hardcore_wrong_answer: 'Hardcore encerrou no erro',
    },
  },
  solution: {
    title: 'Solução',
    open: 'Comentário de revisão',
    back: 'Voltar ao resultado',
    question: 'Questão',
    explanation: 'Explicação',
    generating: 'Gerando solução...',
    cached: 'solução armazenada',
    generated: 'nova solução',
  },
  difficulty: {
    info: 'INFO',
    warn: 'WARN',
    error: 'ERROR',
    fatal: 'FATAL',
    unknown: 'Desconhecido',
  },
  difficultyMessages: {
    info: 'Quiz fácil, programador Júnior, poucas opções, enunciados diretos.',
    warn: 'Quiz normal, programador Pleno, mais opções, leitura atenta.',
    error: 'Quiz difícil, programador Sênior, muitas opções, leitura cuidadosa.',
    fatal: 'Quiz roguelike, programador Especialista, muitas opções, só uma bala.',
  },
  errors: {
    apiUnavailable: 'API indisponivel',
    startRun: 'Nao foi possivel iniciar a run',
    answer: 'Nao foi possivel enviar a resposta',
    runExpired: 'A run nao esta mais disponivel. Inicie uma nova run para continuar.',
    quitRun: 'Nao foi possivel encerrar a run',
    endSession: 'Nao foi possivel encerrar a sessao',
    topics: 'Nao foi possivel atualizar os topicos disponiveis',
    difficulties: 'Nao foi possivel atualizar as severidades disponiveis',
    noDifficulties: 'Nenhuma dificuldade disponivel para este topico',
    solution: 'Nao foi possivel carregar a solucao da questao',
    solutionRateLimited:
      'Olha so, o operador voltou por outro caminho. Nao desista: continue exercitando seu conhecimento no laboratorio ;)',
  },
};
