<?php

namespace App\Controller;

use App\Service\CatalogAssistant;
use App\Service\OpenAiConfiguration;
use App\Service\QuizAuthoringService;
use App\Service\TopicTags;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends BaseController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirect($this->themeContext->selectedTheme() === '' ? 'themes' : 'catalog');
    }

    #[Route('/catalog', name: 'catalog', methods: ['GET'])]
    public function catalog(QuizAuthoringService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $sortField = (string) $request->query->get('sortField', '');
        $sortDirection = (string) $request->query->get('sortDirection', 'asc');
        $keyword = (string) $request->query->get('keyword', '');
        $topics = $this->sortTopics($this->filterTopics($packs->listTopics(), $keyword), $sortField, $sortDirection);

        return $this->render('catalog/index.html.twig', [
            'contentRoot' => $packs->contentRoot(),
            'theme' => $packs->selectedThemeMetadata(),
            'fallbackLocale' => $packs->fallbackLocale(),
            'supportedLocales' => $packs->supportedLocales(),
            'topics' => $topics,
            'keyword' => $keyword,
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
            'validationErrors' => $packs->validateAll(),
        ]);
    }

    #[Route('/catalog/new', name: 'catalog_new', methods: ['GET'])]
    public function new(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        return $this->render('catalog/form.html.twig', [
            'topic' => ['active' => true, 'weight' => 100],
            'isNew' => true,
        ]);
    }

    #[Route('/catalog/{key}', name: 'catalog_edit', methods: ['GET'])]
    public function edit(QuizAuthoringService $packs, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        foreach ($packs->listTopics() as $topic) {
            if ($topic['key'] === $key) {
                return $this->render('catalog/form.html.twig', ['topic' => $topic, 'isNew' => false]);
            }
        }

        return $this->redirect('catalog');
    }

    #[Route('/catalog/save', name: 'catalog_save', methods: ['POST'])]
    public function save(QuizAuthoringService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        try {
            $this->csrf->assertValid($request);
            $packs->saveTopic($this->topicInput($request->request->all()));
            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('catalog/form.html.twig', [
                'topic' => $request->request->all(),
                'isNew' => $request->request->get('isNew') === '1',
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/catalog/ai/suggest-description', name: 'catalog_ai_suggest_description', methods: ['POST'])]
    public function suggestDescription(QuizAuthoringService $packs, CatalogAssistant $assistant, OpenAiConfiguration $openAi, Request $request): Response
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

        $topic = $request->request->all();
        $isNew = trim((string) ($topic['isNew'] ?? '')) === '1';
        try {
            $this->csrf->assertValid($request);
            $topic['description'] = $assistant->suggestDescription($packs->fallbackLocale(), (string) ($topic['name'] ?? ''));

            return $this->render('catalog/form.html.twig', [
                'topic' => $topic,
                'isNew' => $isNew,
            ]);
        } catch (RuntimeException $error) {
            return $this->render('catalog/form.html.twig', [
                'topic' => $topic,
                'isNew' => $isNew,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/catalog/ai/save', name: 'catalog_ai_save', methods: ['POST'])]
    public function saveWithAi(QuizAuthoringService $packs, CatalogAssistant $assistant, OpenAiConfiguration $openAi, Request $request): Response
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

        $topic = $request->request->all();
        $isNew = trim((string) ($topic['isNew'] ?? '')) === '1';
        try {
            $this->csrf->assertValid($request);
            $this->topicInput($topic);
            $canonical = $assistant->canonicalize($packs->fallbackLocale(), (string) ($topic['name'] ?? ''), (string) ($topic['description'] ?? ''));
            $topic['name'] = $canonical['name'];
            $topic['description'] = $canonical['description'];
            $localizations = [];
            foreach ($packs->supportedLocales() as $locale) {
                if ($locale === $packs->fallbackLocale()) {
                    continue;
                }

                $translated = $assistant->translate($locale, $canonical['name'], $canonical['description']);
                $localizations[$locale] = [
                    'name' => $translated['name'],
                    'description' => $translated['description'],
                ];
            }
            $packs->saveTopicSet($this->topicInput($topic), $localizations);

            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('catalog/form.html.twig', [
                'topic' => $topic,
                'isNew' => $isNew,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/catalog/{key}/delete', name: 'catalog_delete', methods: ['POST'])]
    public function delete(QuizAuthoringService $packs, Request $request, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $this->csrf->assertValid($request);
        $packs->deleteTopic($key);
        return $this->redirect('catalog');
    }

    #[Route('/catalog/{locale}/{key}/localization', name: 'catalog_localization', methods: ['GET'])]
    public function localization(QuizAuthoringService $packs, string $locale, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $fallback = $this->fallbackTopic($packs, $key);
        $localized = ['key' => $key, 'name' => '', 'description' => ''];
        foreach ($packs->readLocalizedCatalog($locale)['topics'] as $topic) {
            if (($topic['key'] ?? '') === $key) {
                $localized = $topic;
                break;
            }
        }
        if (trim((string) ($localized['name'] ?? '')) === '') {
            $localized['name'] = $fallback['name'] ?? '';
        }
        if (trim((string) ($localized['description'] ?? '')) === '') {
            $localized['description'] = $fallback['description'] ?? '';
        }

        return $this->render('catalog/localization.html.twig', [
            'locale' => $locale,
            'topic' => $localized,
        ]);
    }

    #[Route('/catalog/{locale}/{key}/localization', name: 'catalog_localization_save', methods: ['POST'])]
    public function saveLocalization(QuizAuthoringService $packs, Request $request, string $locale, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        try {
            $this->csrf->assertValid($request);
            $payload = $request->request->all();
            $payload['key'] = $key;
            $packs->saveLocalizedTopic($locale, $payload);
            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('catalog/localization.html.twig', [
                'locale' => $locale,
                'topic' => $request->request->all() + ['key' => $key],
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/catalog/{locale}/{key}/localization/ai-save', name: 'catalog_localization_ai_save', methods: ['POST'])]
    public function saveLocalizationWithAi(QuizAuthoringService $packs, CatalogAssistant $assistant, OpenAiConfiguration $openAi, Request $request, string $locale, string $key): Response
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

        $payload = $request->request->all();
        try {
            $this->csrf->assertValid($request);
            $translated = $assistant->translate($locale, (string) ($payload['name'] ?? ''), (string) ($payload['description'] ?? ''));
            $payload['key'] = $key;
            $payload['name'] = $translated['name'];
            $payload['description'] = $translated['description'];
            $packs->saveLocalizedTopic($locale, $payload);

            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('catalog/localization.html.twig', [
                'locale' => $locale,
                'topic' => $payload + ['key' => $key],
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function requireAiConfigured(OpenAiConfiguration $openAi): ?Response
    {
        if ($openAi->isConfigured()) {
            return null;
        }
        return $this->redirect('catalog');
    }

    /** @return array<string,mixed> */
    private function fallbackTopic(QuizAuthoringService $packs, string $key): array
    {
        foreach ($packs->listTopics() as $topic) {
            if (($topic['key'] ?? '') === $key) {
                return $topic;
            }
        }
        return ['key' => $key, 'name' => '', 'description' => ''];
    }

    /**
     * @param list<array<string,mixed>> $topics
     * @return list<array<string,mixed>>
     */
    private function filterTopics(array $topics, string $keyword): array
    {
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return $topics;
        }

        return array_values(array_filter($topics, static function (array $topic) use ($keyword): bool {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($topic['key'] ?? ''),
                (string) ($topic['name'] ?? ''),
                (string) ($topic['description'] ?? ''),
            ]));

            return str_contains($haystack, $keyword);
        }));
    }

    /**
     * @param list<array<string,mixed>> $topics
     * @return list<array<string,mixed>>
     */
    private function sortTopics(array $topics, string $field, string $direction): array
    {
        if (!in_array($field, ['key', 'name', 'weight', 'created'], true)) {
            return $topics;
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        usort($topics, function (array $left, array $right) use ($field, $direction): int {
            $comparison = $this->compareTopics($left, $right, $field);
            if ($direction === 'desc') {
                $comparison *= -1;
            }
            return $comparison !== 0
                ? $comparison
                : ((string) ($left['key'] ?? '')) <=> ((string) ($right['key'] ?? ''));
        });

        return $topics;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareTopics(array $left, array $right, string $field): int
    {
        if ($field === 'weight') {
            return ((int) ($left['weight'] ?? 0)) <=> ((int) ($right['weight'] ?? 0));
        }
        if ($field === 'created') {
            $leftCreated = $this->topicCreatedTimestamp($left);
            $rightCreated = $this->topicCreatedTimestamp($right);
            if ($leftCreated === null && $rightCreated === null) {
                return 0;
            }
            if ($leftCreated === null) {
                return 1;
            }
            if ($rightCreated === null) {
                return -1;
            }
            return $leftCreated <=> $rightCreated;
        }

        return strcasecmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''));
    }

    /** @param array<string,mixed> $topic */
    private function topicCreatedTimestamp(array $topic): ?int
    {
        $createdAt = trim((string) ($topic['created_at'] ?? ''));
        if ($createdAt === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($createdAt))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function topicInput(array $input): array
    {
        if (array_key_exists('tags', $input)) {
            $input['tags'] = TopicTags::fromForm($input['tags']);
        }
        return $input;
    }
}
