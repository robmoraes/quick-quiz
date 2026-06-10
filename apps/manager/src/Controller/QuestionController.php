<?php

namespace App\Controller;

use App\Service\QuizPackService;
use App\Service\QuestionLocalizer;
use App\Service\QuestionRecommender;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QuestionController extends BaseController
{
    #[Route('/questions', name: 'questions', methods: ['GET'])]
    public function index(QuizPackService $packs, Request $request): Response
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

        $questions = $topic === '' ? [] : $packs->listQuestions($locale, $topic, $difficulty);

        return $this->render('question/index.html.twig', [
            'locales' => $packs->supportedLocales(),
            'topics' => $topicChoices,
            'difficulties' => $packs->difficulties(),
            'selectedLocale' => $locale,
            'selectedTopic' => $topic,
            'selectedDifficulty' => $difficulty,
            'questions' => $questions,
        ]);
    }

    #[Route('/questions/ai/recommend', name: 'question_ai_recommend', methods: ['POST'])]
    public function aiRecommend(QuizPackService $packs, QuestionRecommender $recommender, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $locale = (string) $request->request->get('locale', $packs->fallbackLocale());
        $topic = (string) $request->request->get('topic', '');
        $difficulty = (int) $request->request->get('difficulty', 1);

        try {
            $this->csrf->assertValid($request);
            $difficulties = $packs->difficulties();
            $contextPrompts = $packs->recommendationPrompts($locale, $topic, $difficulty);
            $topicMetadata = $packs->recommendationTopicMetadata($locale, $topic);
            $draft = $recommender->recommend($locale, $topicMetadata, $difficulty, $difficulties[$difficulty], $contextPrompts);
            $question = $packs->validateRecommendedQuestionDraft($locale, $topic, $difficulty, $draft);

            return $this->render('question/ai_form.html.twig', [
                'topics' => $packs->topicChoices(),
                'difficulties' => $difficulties,
                'fallbackLocale' => $packs->fallbackLocale(),
                'supportedLocales' => $packs->supportedLocales(),
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => '',
                'suggestedQuestionId' => $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : '',
                'translateOptions' => false,
                'question' => $question,
            ]);
        } catch (RuntimeException $error) {
            return $this->renderQuestionIndex($packs, $locale, $topic, $difficulty, $error->getMessage());
        }
    }

    #[Route('/questions/edit', name: 'question_edit', methods: ['GET'])]
    public function edit(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $locale = (string) $request->query->get('locale', $packs->fallbackLocale());
        $topic = (string) $request->query->get('topic', '');
        $difficulty = (int) $request->query->get('difficulty', 1);
        $questionId = (string) $request->query->get('id', '');
        $question = $questionId === '' ? ['prompt' => '', 'correctOptions' => [], 'wrongOptions' => []] : $packs->readQuestion($locale, $topic, $difficulty, $questionId);
        $suggestedQuestionId = $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId;

        return $this->render('question/form.html.twig', [
            'locales' => $packs->supportedLocales(),
            'topics' => $packs->topicChoices(),
            'difficulties' => $packs->difficulties(),
            'locale' => $locale,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionId' => $questionId,
            'suggestedQuestionId' => $suggestedQuestionId,
            'question' => $question,
        ]);
    }

    #[Route('/questions/ai/new', name: 'question_ai_new', methods: ['GET'])]
    public function aiNew(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $topic = (string) $request->query->get('topic', '');
        $difficulty = (int) $request->query->get('difficulty', 1);
        $questionId = '';
        $suggestedQuestionId = $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : '';

        return $this->render('question/ai_form.html.twig', [
            'topics' => $packs->topicChoices(),
            'difficulties' => $packs->difficulties(),
            'fallbackLocale' => $packs->fallbackLocale(),
            'supportedLocales' => $packs->supportedLocales(),
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionId' => $questionId,
            'suggestedQuestionId' => $suggestedQuestionId,
            'translateOptions' => false,
            'question' => ['prompt' => '', 'correctOptions' => [], 'wrongOptions' => []],
        ]);
    }

    #[Route('/questions/save', name: 'question_save', methods: ['POST'])]
    public function save(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $locale = (string) $request->request->get('locale');
        $topic = (string) $request->request->get('topic');
        $difficulty = (int) $request->request->get('difficulty');
        $questionId = (string) $request->request->get('questionId');

        try {
            $this->csrf->assertValid($request);
            $questionId = $packs->saveQuestion($locale, $topic, $difficulty, $questionId, $request->request->all());
            return $this->redirect('questions', ['locale' => $locale, 'topic' => $topic, 'difficulty' => $difficulty]);
        } catch (RuntimeException $error) {
            return $this->render('question/form.html.twig', [
                'locales' => $packs->supportedLocales(),
                'topics' => $packs->topicChoices(),
                'difficulties' => $packs->difficulties(),
                'locale' => $locale,
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId,
                'question' => [
                    'prompt' => (string) $request->request->get('prompt'),
                    'correctOptions' => preg_split('/\R/', (string) $request->request->get('correctOptions')) ?: [],
                    'wrongOptions' => preg_split('/\R/', (string) $request->request->get('wrongOptions')) ?: [],
                ],
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/questions/ai/save', name: 'question_ai_save', methods: ['POST'])]
    public function aiSave(QuizPackService $packs, QuestionLocalizer $localizer, Request $request): Response
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
        $translateOptions = $request->request->has('translateOptions');

        try {
            $this->csrf->assertValid($request);
            $prepared = $packs->prepareNewLocalizedQuestionSet($topic, $difficulty, $questionId, $request->request->all());
            $localized = $localizer->localize($prepared['question'], $packs->supportedLocales(), $translateOptions);
            $packs->saveLocalizedQuestionSet($topic, $difficulty, $prepared['questionId'], $prepared['question'], $localized['localizations']);

            return $this->redirect('questions', [
                'locale' => $packs->fallbackLocale(),
                'topic' => $topic,
                'difficulty' => $difficulty,
            ]);
        } catch (RuntimeException $error) {
            return $this->render('question/ai_form.html.twig', [
                'topics' => $packs->topicChoices(),
                'difficulties' => $packs->difficulties(),
                'fallbackLocale' => $packs->fallbackLocale(),
                'supportedLocales' => $packs->supportedLocales(),
                'topic' => $topic,
                'difficulty' => $difficulty,
                'questionId' => $questionId,
                'suggestedQuestionId' => $questionId === '' && $topic !== '' ? $packs->nextQuestionId($topic, $difficulty) : $questionId,
                'translateOptions' => $translateOptions,
                'question' => [
                    'prompt' => (string) $request->request->get('prompt'),
                    'correctOptions' => preg_split('/\R/', (string) $request->request->get('correctOptions')) ?: [],
                    'wrongOptions' => preg_split('/\R/', (string) $request->request->get('wrongOptions')) ?: [],
                ],
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
        $packs->deleteQuestion($locale, $topic, $difficulty, $questionId);

        return $this->redirect('questions', ['locale' => $locale, 'topic' => $topic, 'difficulty' => $difficulty]);
    }

    private function renderQuestionIndex(QuizPackService $packs, string $locale, string $topic, int $difficulty, string $error): Response
    {
        $questions = [];
        if ($topic !== '') {
            try {
                $questions = $packs->listQuestions($locale, $topic, $difficulty);
            } catch (RuntimeException) {
                $questions = [];
            }
        }

        return $this->render('question/index.html.twig', [
            'locales' => $packs->supportedLocales(),
            'topics' => $packs->topicChoices(),
            'difficulties' => $packs->difficulties(),
            'selectedLocale' => $locale,
            'selectedTopic' => $topic,
            'selectedDifficulty' => $difficulty,
            'questions' => $questions,
            'error' => $error,
        ]);
    }
}
