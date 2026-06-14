<?php

namespace App\Controller;

use App\Service\QuestionLocalizer;
use App\Service\QuestionRecommender;
use App\Service\OpenAiConfiguration;
use App\Service\QuizPackService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QuestionController extends BaseController
{
    #[Route('/questions', name: 'questions', methods: ['GET'])]
    public function index(QuizPackService $packs, OpenAiConfiguration $openAi, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $locale = (string) $request->query->get('locale', $packs->fallbackLocale());
        $topicChoices = $packs->topicChoices();
        $topic = (string) $request->query->get('topic', $topicChoices[0]['key'] ?? '');
        $difficulty = (int) $request->query->get('difficulty', 1);
        $deletedQuestion = (string) $request->query->get('deletedQuestion', '');
        $deletedLocales = (int) $request->query->get('deletedLocales', 0);
        $missingLocales = (int) $request->query->get('missingLocales', 0);

        $questions = $topic === '' ? [] : $packs->listQuestions($locale, $topic, $difficulty);

        return $this->renderQuestionIndex(
            $packs,
            $locale,
            $topic,
            $difficulty,
            $questions,
            aiAvailable: $openAi->isConfigured(),
            deleteResult: [
                'questionId' => $deletedQuestion,
                'deletedLocales' => $deletedLocales,
                'missingLocales' => $missingLocales,
            ],
        );
    }

    #[Route('/questions/manual/new', name: 'question_manual_new', methods: ['GET'])]
    public function manualNew(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $topic = (string) $request->query->get('topic', '');
        $difficulty = (int) $request->query->get('difficulty', 1);
        $sourceLocale = (string) $request->query->get('locale', $packs->fallbackLocale());

        return $this->renderManualForm($packs, [
            'isEdit' => false,
            'sourceLocale' => $sourceLocale,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionId' => '',
            'suggestedQuestionId' => $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : '',
            'question' => $this->emptyQuestion(),
            'localizedQuestions' => [],
        ]);
    }

    #[Route('/questions/manual/create', name: 'question_manual_create', methods: ['POST'])]
    public function manualCreate(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $sourceLocale = (string) $request->request->get('sourceLocale', $packs->fallbackLocale());
        $topic = (string) $request->request->get('topic');
        $difficulty = (int) $request->request->get('difficulty');
        $questionId = (string) $request->request->get('questionId');
        $question = $this->questionFromRequest($request);

        try {
            $this->csrf->assertValid($request);
            $questionId = $packs->createReplicatedQuestionSet($sourceLocale, $topic, $difficulty, $questionId, $question);
            return $this->redirect('questions', ['locale' => $sourceLocale, 'topic' => $topic, 'difficulty' => $difficulty]);
        } catch (RuntimeException $error) {
            return $this->renderManualForm($packs, [
                'isEdit' => false,
                'sourceLocale' => $sourceLocale,
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId,
                'question' => $question,
                'localizedQuestions' => [],
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/questions/manual/edit', name: 'question_manual_edit', methods: ['GET'])]
    public function manualEdit(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $topic = (string) $request->query->get('topic', '');
        $difficulty = (int) $request->query->get('difficulty', 1);
        $questionId = (string) $request->query->get('id', '');

        return $this->renderManualForm($packs, [
            'isEdit' => true,
            'sourceLocale' => $packs->fallbackLocale(),
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionId' => $questionId,
            'suggestedQuestionId' => $questionId,
            'question' => $this->emptyQuestion(),
            'localizedQuestions' => $packs->readLocalizedQuestionSet($topic, $difficulty, $questionId),
        ]);
    }

    #[Route('/questions/manual/update', name: 'question_manual_update', methods: ['POST'])]
    public function manualUpdate(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $topic = (string) $request->request->get('topic');
        $difficulty = (int) $request->request->get('difficulty');
        $questionId = (string) $request->request->get('questionId');
        $localizedQuestions = $this->localizedQuestionsFromRequest($request);

        try {
            $this->csrf->assertValid($request);
            $packs->updateManualLocalizedQuestionSet($topic, $difficulty, $questionId, $localizedQuestions);
            return $this->redirect('questions', ['locale' => $packs->fallbackLocale(), 'topic' => $topic, 'difficulty' => $difficulty]);
        } catch (RuntimeException $error) {
            return $this->renderManualForm($packs, [
                'isEdit' => true,
                'sourceLocale' => $packs->fallbackLocale(),
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId,
                'question' => $this->emptyQuestion(),
                'localizedQuestions' => $localizedQuestions,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/questions/ai/new', name: 'question_ai_new', methods: ['GET'])]
    public function aiNew(QuizPackService $packs, OpenAiConfiguration $openAi, Request $request): Response
    {
        if ($redirect = $this->requireAiConfigured($openAi)) {
            return $redirect;
        }
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $topic = (string) $request->query->get('topic', '');
        $difficulty = (int) $request->query->get('difficulty', 1);

        return $this->renderAiForm($packs, [
            'mode' => 'create',
            'sourceLocale' => $packs->fallbackLocale(),
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionId' => '',
            'suggestedQuestionId' => $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : '',
            'question' => $this->emptyQuestion(),
            'translateOptions' => false,
        ]);
    }

    #[Route('/questions/ai/edit', name: 'question_ai_edit', methods: ['GET'])]
    public function aiEdit(QuizPackService $packs, OpenAiConfiguration $openAi, Request $request): Response
    {
        if ($redirect = $this->requireAiConfigured($openAi)) {
            return $redirect;
        }
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $sourceLocale = (string) $request->query->get('locale', $packs->fallbackLocale());
        $topic = (string) $request->query->get('topic', '');
        $difficulty = (int) $request->query->get('difficulty', 1);
        $questionId = (string) $request->query->get('id', '');

        return $this->renderAiForm($packs, [
            'mode' => 'edit',
            'sourceLocale' => $sourceLocale,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionId' => $questionId,
            'suggestedQuestionId' => $questionId,
            'question' => $packs->readQuestion($sourceLocale, $topic, $difficulty, $questionId),
            'translateOptions' => false,
        ]);
    }

    #[Route('/questions/ai/recommend', name: 'question_ai_recommend', methods: ['POST'])]
    public function aiRecommend(QuizPackService $packs, QuestionRecommender $recommender, OpenAiConfiguration $openAi, Request $request): Response
    {
        if ($redirect = $this->requireAiConfigured($openAi)) {
            return $redirect;
        }
        return $this->handleAiRecommendation($packs, $recommender, $request, answersOnly: false);
    }

    #[Route('/questions/ai/suggest-answers', name: 'question_ai_suggest_answers', methods: ['POST'])]
    public function aiSuggestAnswers(QuizPackService $packs, QuestionRecommender $recommender, OpenAiConfiguration $openAi, Request $request): Response
    {
        if ($redirect = $this->requireAiConfigured($openAi)) {
            return $redirect;
        }
        return $this->handleAiRecommendation($packs, $recommender, $request, answersOnly: true);
    }

    #[Route('/questions/ai/save', name: 'question_ai_save', methods: ['POST'])]
    public function aiSave(QuizPackService $packs, QuestionLocalizer $localizer, OpenAiConfiguration $openAi, Request $request): Response
    {
        if ($redirect = $this->requireAiConfigured($openAi)) {
            return $redirect;
        }
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $mode = (string) $request->request->get('mode', 'create');
        $sourceLocale = (string) $request->request->get('sourceLocale', $packs->fallbackLocale());
        $topic = (string) $request->request->get('topic');
        $difficulty = (int) $request->request->get('difficulty');
        $questionId = (string) $request->request->get('questionId');
        $translateOptions = $request->request->has('translateOptions');
        $question = $this->questionFromRequest($request);

        try {
            $this->csrf->assertValid($request);
            if ($mode === 'edit') {
                $localized = $localizer->localize($question, $packs->supportedLocales(), $translateOptions);
                $packs->updateAiLocalizedQuestionSet($topic, $difficulty, $questionId, $question, $localized['localizations'], copySourceAnswers: !$translateOptions);
            } else {
                $prepared = $packs->prepareNewLocalizedQuestionSet($topic, $difficulty, $questionId, $question);
                $localized = $localizer->localize($prepared['question'], $packs->supportedLocales(), $translateOptions);
                $packs->saveLocalizedQuestionSet($topic, $difficulty, $prepared['questionId'], $prepared['question'], $localized['localizations'], copySourceAnswers: !$translateOptions);
                $questionId = $prepared['questionId'];
            }

            return $this->redirect('questions', ['locale' => $sourceLocale, 'topic' => $topic, 'difficulty' => $difficulty]);
        } catch (RuntimeException $error) {
            return $this->renderAiForm($packs, [
                'mode' => $mode,
                'sourceLocale' => $sourceLocale,
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId,
                'question' => $question,
                'translateOptions' => $translateOptions,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/questions/delete', name: 'question_delete', methods: ['POST'])]
    public function delete(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $this->csrf->assertValid($request);
        $locale = (string) $request->request->get('locale');
        $topic = (string) $request->request->get('topic');
        $difficulty = (int) $request->request->get('difficulty');
        $questionId = (string) $request->request->get('questionId');
        $result = $packs->deleteQuestion($topic, $difficulty, $questionId);

        return $this->redirect('questions', [
            'locale' => $locale,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'deletedQuestion' => $questionId,
            'deletedLocales' => count($result['deletedLocales']),
            'missingLocales' => count($result['missingLocales']),
        ]);
    }

    private function handleAiRecommendation(QuizPackService $packs, QuestionRecommender $recommender, Request $request, bool $answersOnly): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $mode = (string) $request->request->get('mode', 'create');
        $sourceLocale = (string) $request->request->get('sourceLocale', $packs->fallbackLocale());
        $topic = (string) $request->request->get('topic', '');
        $difficulty = (int) $request->request->get('difficulty', 1);
        $questionId = (string) $request->request->get('questionId');
        $translateOptions = $request->request->has('translateOptions');
        $question = $this->questionFromRequest($request);

        try {
            $this->csrf->assertValid($request);
            $difficulties = $packs->difficulties();
            $contextPrompts = $packs->recommendationPrompts($sourceLocale, $topic, $difficulty);
            $topicMetadata = $packs->recommendationTopicMetadata($sourceLocale, $topic);
            if ($answersOnly) {
                if (trim($question['prompt']) === '') {
                    throw new RuntimeException('Prompt is required before suggesting answers with AI.');
                }
                $answers = $recommender->recommendAnswers($sourceLocale, $topicMetadata, $difficulty, $difficulties[$difficulty], $question['prompt'], $contextPrompts);
                $question = $packs->validateRecommendedAnswerDraft($difficulty, $question['prompt'], $answers);
            } else {
                $draft = $recommender->recommend($sourceLocale, $topicMetadata, $difficulty, $difficulties[$difficulty], $contextPrompts, $question['prompt']);
                $question = $packs->validateRecommendedQuestionDraft($sourceLocale, $topic, $difficulty, $draft);
            }

            return $this->renderAiForm($packs, [
                'mode' => $mode,
                'sourceLocale' => $sourceLocale,
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId,
                'question' => $question,
                'translateOptions' => $translateOptions,
            ]);
        } catch (RuntimeException $error) {
            return $this->renderAiForm($packs, [
                'mode' => $mode,
                'sourceLocale' => $sourceLocale,
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId,
                'question' => $question,
                'translateOptions' => $translateOptions,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param list<array{id:string,path:string,prompt:string,correctCount:int,wrongCount:int}> $questions
     * @param array{questionId:string, deletedLocales:int, missingLocales:int}|null $deleteResult
     */
    private function renderQuestionIndex(QuizPackService $packs, string $locale, string $topic, int $difficulty, array $questions, string $error = '', bool $aiAvailable = false, ?array $deleteResult = null): Response
    {
        return $this->render('question/index.html.twig', [
            'locales' => $packs->supportedLocales(),
            'topics' => $packs->topicChoices(),
            'difficulties' => $packs->difficulties(),
            'selectedLocale' => $locale,
            'selectedTopic' => $topic,
            'selectedDifficulty' => $difficulty,
            'questions' => $questions,
            'error' => $error,
            'aiAvailable' => $aiAvailable,
            'deleteResult' => $deleteResult,
        ]);
    }

    private function requireAiConfigured(OpenAiConfiguration $openAi): ?Response
    {
        if ($openAi->isConfigured()) {
            return null;
        }
        return $this->redirect('questions');
    }

    /** @param array<string,mixed> $context */
    private function renderManualForm(QuizPackService $packs, array $context): Response
    {
        return $this->render('question/manual_form.html.twig', array_merge([
            'locales' => $packs->supportedLocales(),
            'topics' => $packs->topicChoices(),
            'difficulties' => $packs->difficulties(),
            'fallbackLocale' => $packs->fallbackLocale(),
        ], $context));
    }

    /** @param array<string,mixed> $context */
    private function renderAiForm(QuizPackService $packs, array $context): Response
    {
        return $this->render('question/ai_form.html.twig', array_merge([
            'topics' => $packs->topicChoices(),
            'difficulties' => $packs->difficulties(),
            'fallbackLocale' => $packs->fallbackLocale(),
            'supportedLocales' => $packs->supportedLocales(),
        ], $context));
    }

    /** @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    private function questionFromRequest(Request $request): array
    {
        return [
            'prompt' => (string) $request->request->get('prompt'),
            'correctOptions' => preg_split('/\R/', (string) $request->request->get('correctOptions')) ?: [],
            'wrongOptions' => preg_split('/\R/', (string) $request->request->get('wrongOptions')) ?: [],
        ];
    }

    /** @return array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}> */
    private function localizedQuestionsFromRequest(Request $request): array
    {
        $values = $request->request->all();
        $questions = is_array($values['questions'] ?? null) ? $values['questions'] : [];
        $localized = [];
        foreach ($questions as $locale => $question) {
            if (!is_array($question)) {
                continue;
            }
            $localized[(string) $locale] = [
                'prompt' => (string) ($question['prompt'] ?? ''),
                'correctOptions' => preg_split('/\R/', (string) ($question['correctOptions'] ?? '')) ?: [],
                'wrongOptions' => preg_split('/\R/', (string) ($question['wrongOptions'] ?? '')) ?: [],
            ];
        }
        return $localized;
    }

    /** @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    private function emptyQuestion(): array
    {
        return ['prompt' => '', 'correctOptions' => [], 'wrongOptions' => []];
    }
}
