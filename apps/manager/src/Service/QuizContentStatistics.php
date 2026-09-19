<?php

namespace App\Service;

use App\Repository\QuizContentRepository;

final class QuizContentStatistics
{
    public function __construct(
        private readonly QuizContentRepository $repository,
        private readonly QuizContentRules $rules,
        private readonly int $runQuestionLimit,
    ) {
    }

    /** @return array<string,mixed> */
    public function forTheme(string $theme): array
    {
        $fallback = $this->rules->fallbackLocale();
        $locales = $this->rules->supportedLocales();
        $topics = $this->repository->topics($theme, $fallback, $fallback);
        $runLimit = max(1, $this->runQuestionLimit);
        $stats = [
            'theme' => $theme,
            'fallbackLocale' => $fallback,
            'runQuestionLimit' => $runLimit,
            'totals' => $this->bucket() + [
                'canonicalQuestions' => 0,
                'activeTopics' => 0,
                'inactiveTopics' => 0,
                'activeTopicQuestions' => 0,
            ],
            'averages' => ['correctAnswersPerQuestion' => 0.0, 'wrongAnswersPerQuestion' => 0.0],
            'runCapacity' => ['total' => 0, 'byTopic' => []],
            'byLocale' => [],
            'byTopic' => [],
            'byDifficulty' => [],
            'zeroQuestionTopics' => [],
            'topicsBelowRunLimit' => [],
            'localeQuestionRange' => ['min' => 0, 'max' => 0],
            'localeParityIssues' => $this->repository->localeParityIssues($theme, $fallback, $locales),
        ];
        foreach ($locales as $locale) {
            $stats['byLocale'][$locale] = $this->bucket();
        }
        foreach ($topics as $topic) {
            $key = $topic['key'];
            $active = $topic['active'];
            $stats['totals'][$active ? 'activeTopics' : 'inactiveTopics']++;
            $stats['byTopic'][$key] = $this->bucket() + [
                'name' => $topic['name'],
                'active' => $active,
                'canonicalQuestions' => 0,
                'difficultyQuestions' => [],
            ];
        }
        foreach ($this->rules->difficulties() as $difficulty => $metadata) {
            $stats['byDifficulty'][$difficulty] = $this->bucket() + ['label' => $metadata['label']];
        }
        foreach ($this->repository->statisticGroups($theme) as $group) {
            $topic = $group['topic'];
            $locale = $group['locale'];
            $difficulty = $group['difficulty'];
            if (!isset($stats['byTopic'][$topic], $stats['byLocale'][$locale], $stats['byDifficulty'][$difficulty])) {
                continue;
            }
            foreach (['questions', 'correctAnswers', 'wrongAnswers'] as $key) {
                $stats['totals'][$key] += $group[$key];
                $stats['byTopic'][$topic][$key] += $group[$key];
                $stats['byLocale'][$locale][$key] += $group[$key];
                $stats['byDifficulty'][$difficulty][$key] += $group[$key];
            }
            if ($locale === $fallback) {
                $stats['totals']['canonicalQuestions'] += $group['questions'];
                $stats['byTopic'][$topic]['canonicalQuestions'] += $group['questions'];
                $stats['byTopic'][$topic]['difficultyQuestions'][$difficulty] = $group['questions'];
            }
        }
        foreach ($stats['byTopic'] as $topic => &$bucket) {
            foreach (array_keys($this->rules->difficulties()) as $difficulty) {
                $bucket['difficultyQuestions'][$difficulty] ??= 0;
            }
            ksort($bucket['difficultyQuestions']);
            if ($bucket['active']) {
                $stats['totals']['activeTopicQuestions'] += $bucket['canonicalQuestions'];
            }
            $stats['runCapacity']['byTopic'][$topic] = intdiv($bucket['canonicalQuestions'], $runLimit);
            if ($bucket['canonicalQuestions'] === 0) {
                $stats['zeroQuestionTopics'][] = $topic;
            }
            if ($bucket['canonicalQuestions'] < $runLimit) {
                $stats['topicsBelowRunLimit'][] = $topic;
            }
        }
        unset($bucket);
        $stats['runCapacity']['total'] = intdiv($stats['totals']['canonicalQuestions'], $runLimit);
        if ($stats['totals']['questions'] > 0) {
            $stats['averages']['correctAnswersPerQuestion'] = round($stats['totals']['correctAnswers'] / $stats['totals']['questions'], 2);
            $stats['averages']['wrongAnswersPerQuestion'] = round($stats['totals']['wrongAnswers'] / $stats['totals']['questions'], 2);
        }
        $counts = array_column($stats['byLocale'], 'questions');
        if ($counts !== []) {
            $stats['localeQuestionRange'] = ['min' => min($counts), 'max' => max($counts)];
        }
        return $stats;
    }

    /** @return array{questions:int,correctAnswers:int,wrongAnswers:int} */
    private function bucket(): array
    {
        return ['questions' => 0, 'correctAnswers' => 0, 'wrongAnswers' => 0];
    }
}
