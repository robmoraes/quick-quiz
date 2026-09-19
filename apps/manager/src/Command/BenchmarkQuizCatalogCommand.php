<?php

namespace App\Command;

use App\Repository\QuizContentRepository;
use App\Service\QuizContentRules;
use App\Service\QuizContentStatistics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'manager:quiz:benchmark', description: 'Measure PostgreSQL quiz navigation reads without content storage.')]
final class BenchmarkQuizCatalogCommand extends Command
{
    public function __construct(
        private readonly QuizContentRepository $repository,
        private readonly QuizContentStatistics $statistics,
        private readonly QuizContentRules $rules,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme ID to inspect.');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Supported locale.', '');
        $this->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Number of measured iterations.', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $theme = trim((string) $input->getOption('theme'));
        $locale = trim((string) $input->getOption('locale')) ?: $this->rules->fallbackLocale();
        $iterations = filter_var($input->getOption('iterations'), FILTER_VALIDATE_INT);
        if ($theme === '' || $iterations === false || $iterations < 1 || $iterations > 100
            || !in_array($locale, $this->rules->supportedLocales(), true)) {
            $output->writeln('<error>Provide --theme, a supported --locale, and --iterations from 1 to 100.</error>');
            return Command::INVALID;
        }
        $times = [];
        $counts = [];
        for ($index = 0; $index < $iterations; ++$index) {
            $start = hrtime(true);
            $themes = $this->repository->themes();
            $topics = $this->repository->topics($theme, $locale, $this->rules->fallbackLocale());
            $this->repository->topicDifficultyCounts($theme, $this->rules->fallbackLocale());
            $stats = $this->statistics->forTheme($theme);
            $questions = $topics === [] ? [] : $this->repository->questions($theme, $topics[0]['key'], $locale);
            $times[] = round((hrtime(true) - $start) / 1_000_000, 2);
            $counts = [
                'themes' => count($themes),
                'topics' => count($topics),
                'canonicalQuestions' => $stats['totals']['canonicalQuestions'],
                'selectedTopicQuestions' => count($questions),
            ];
        }
        sort($times);
        $output->writeln(json_encode([
            'theme' => $theme,
            'locale' => $locale,
            'iterations' => $iterations,
            'p50Ms' => $times[(int) floor(($iterations - 1) * 0.5)],
            'p95Ms' => $times[(int) floor(($iterations - 1) * 0.95)],
            'maxMs' => max($times),
            'counts' => $counts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
