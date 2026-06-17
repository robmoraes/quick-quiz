export default {
  app: {
    title: 'dev.QuickQuiz',
    subtitle: 'Game show quiz for programmers',
  },
  actions: {
    next: 'Next',
    startRun: 'Start run',
    session: 'Session',
    endSession: 'End session',
    changeTopic: 'Change topic',
    newRun: 'New run',
    newSession: 'New session',
  },
  rules: {
    button: 'RFC 0001 - Game Rules Protocol',
    title: 'RFC 0001 - Game Rules Protocol',
    close: 'Close RFC',
  },
  confirm: {
    cancel: 'Cancel',
    sessionResultTitle: 'End session?',
    sessionResultMessage:
      'This ends the current session. Any active run will be finished, the final session result will be shown, and this session cannot be continued.',
    sessionResultConfirm: 'End session',
    hardcoreTitle: 'Enter fatal severity?',
    hardcoreMessage:
      'One wrong answer resets the whole current session. There is no result screen for the lost run.',
    hardcoreConfirm: 'Accept fatal risk',
  },
  form: {
    topic: 'Choose the Topic',
  },
  topicInfo: {
    title: 'About this topic',
  },
  settings: {
    title: 'Settings',
    close: 'Close settings',
    locale: 'Locale',
    cancel: 'Cancel',
    save: 'Save',
    locales: {
      browser: 'Browser',
      ptBR: 'pt-BR',
      enUS: 'en-US',
    },
    audio: {
      title: 'Audio',
      soundEffects: 'Sound Effects',
      enableSounds: 'Enable sounds',
      soundsVolume: 'Sounds volume',
      music: 'Music',
      enableMusic: 'Enable music',
      musicVolume: 'Music volume',
    },
  },
  beforeGame: {
    startPanel: {
      title: 'Topic',
    },
    difficultyPanel: {
      title: 'Level',
    },
  },
  game: {
    questionProgress: 'Question {current} of {total}',
    sessionAvailability: 'Session {available}/{total}',
    topicAvailability: 'Topic {available}/{total}',
    availabilityCards: {
      questionsLabel: 'Questions',
      answeredLabel: 'answered',
      totalLabel: 'total',
      sessionLabel: 'Session',
      topicLabel: 'Topic',
      session: {
        activeTooltip:
          'In this session, <strong>{available}</strong> questions remain from a total of <strong>{total}</strong>.',
        inactiveTooltip:
          '<strong>{total}</strong> questions are available before starting a session.',
      },
      topic: {
        activeTooltip:
          'In this session, this topic still has <strong>{available}</strong> questions remaining from a total of <strong>{total}</strong>.',
        inactiveTooltip: 'This topic has <strong>{total}</strong> available questions.',
      },
    },
    correct: 'Tests passed',
    wrong: 'Tests failed',
  },
  devtools: {
    welcome: 'Well well, looks like we have a hacker here. Welcome to Quick Quiz Dev',
  },
  fatalLoss: {
    eyebrow: 'severity.FATAL',
    title: 'GAME OVER',
    message: 'One wrong answer cracked the session. All progress was lost.',
  },
  result: {
    title: 'Result',
    runTitle: 'Run',
    sessionTitle: 'Session',
    sessionFinished: 'Session ended',
    answered: 'Tests',
    correct: 'Passed',
    wrong: 'Failed',
    accuracy: 'Accuracy',
    answerCorrect: 'Correct',
    answerWrong: 'Wrong',
    icon: 'icon',
    pullRequest: 'Pull request',
    codeReview: 'code review',
    reviewAccept: 'accept',
    reviewRejected: 'rejected',
    noAnswers: 'No answers in this session',
    finished: 'Run finished',
    reasons: {
      player_quit: 'Run ended by player',
      max_questions_reached: 'Run complete',
      no_questions_left: 'No questions left',
      hardcore_wrong_answer: 'Hardcore ended on mistake',
    },
  },
  solution: {
    title: 'Solution',
    open: 'Review comment',
    back: 'Back to result',
    question: 'Question',
    explanation: 'Explanation',
    generating: 'Generating solution...',
    cached: 'stored solution',
    generated: 'new solution',
  },
  difficulty: {
    info: 'INFO',
    warn: 'WARN',
    error: 'ERROR',
    fatal: 'FATAL',
    unknown: 'Unknown',
  },
  difficultyMessages: {
    info: 'Easy quiz, junior programmer, few options, plenty of time.',
    warn: 'Normal quiz, mid-level programmer, more options, fair time.',
    error: 'Hard quiz, senior programmer, many options, little time.',
    fatal: 'Roguelike quiz, specialist programmer, many options, one shot only.',
  },
  errors: {
    apiUnavailable: 'API unavailable',
    startRun: 'Could not start the run',
    answer: 'Could not send the answer',
    runExpired: 'The session expired. Start a new run to continue.',
    quitRun: 'Could not finish the run',
    endSession: 'Could not end the session',
    topics: 'Could not refresh available topics',
    difficulties: 'Could not refresh available severities',
    noDifficulties: 'No difficulties available for this topic',
    solution: 'Could not load the question solution',
    solutionRateLimited:
      'Well, well, the hacker is back. I knew challenging programmers would turn against me, but not through this door. Do not quit: keep exercising your knowledge in the quiz ;)',
  },
};
