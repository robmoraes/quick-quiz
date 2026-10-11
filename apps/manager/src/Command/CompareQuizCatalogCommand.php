<?php

namespace App\Command;

use App\Service\QuizContentComparator;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizSourceCatalog;
use App\Service\QuizLegacyExportService;
use App\Storage\ContentStorage;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'manager:quiz:compare', description: 'Compare published quiz JSON with the PostgreSQL projection.')]
final class CompareQuizCatalogCommand extends Command
{
    public function __construct(
        private readonly QuizSourceCatalog $source,
        private readonly QuizProjectionRenderer $renderer,
        private readonly QuizContentComparator $comparator,
        private readonly QuizLegacyExportService $streamedComparison,
        private readonly ContentStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('expected-revision', null, InputOption::VALUE_REQUIRED, 'Use bounded comparison at this exact PostgreSQL revision.');
        $this->addOption('report', null, InputOption::VALUE_REQUIRED, 'Persist revisioned verification JSON for an explicit backend switch.');
        $this->addOption('legacy-provider-runs', null, InputOption::VALUE_REQUIRED, 'Explicit operator attestation from the API compatibility metric; never inferred.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $revision = $input->getOption('expected-revision');
            if ($revision !== null) {
                if (!ctype_digit((string) $revision)) {
                    throw new RuntimeException('Expected revision must be a nonnegative integer.');
                }
                $result = $this->streamedComparison->compare((int) $revision, $this->storage);
                $legacyRuns = $input->getOption('legacy-provider-runs');
                if ($legacyRuns !== null) {
                    if (!ctype_digit((string) $legacyRuns)) {
                        throw new RuntimeException('Legacy provider run attestation must be a nonnegative integer.');
                    }
                    $result['legacyProviderRuns'] = (int) $legacyRuns;
                    $result['legacyProviderRunsSource'] = 'operator-attested-api-metric';
                }
                $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $path = $input->getOption('report');
                if ($path !== null && file_put_contents((string) $path, $json."\n", LOCK_EX) === false) {
                    throw new RuntimeException('Cannot persist comparison report.');
                }
                $output->writeln($json);
                return $result['verified'] ? Command::SUCCESS : Command::FAILURE;
            }
            if ($input->getOption('report') !== null || $input->getOption('legacy-provider-runs') !== null) {
                throw new RuntimeException('Revisioned comparison flags require --expected-revision.');
            }
            $source = $this->source->load();
            $target = $this->renderer->render();
            $result = $this->comparator->compare($source['objects'], $target);
            $output->writeln(json_encode([
                'sourceCounts' => $source['counts'],
                'sourceBreakdown' => $source['breakdown'],
                'sourceChecksum' => $this->comparator->checksum($source['objects']),
                'databaseChecksum' => $this->comparator->checksum($target),
                'comparison' => $result,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $result['equal'] ? Command::SUCCESS : Command::FAILURE;
        } catch (PDOException) {
            $output->writeln('<error>PostgreSQL comparison failed.</error>');
            return Command::FAILURE;
        } catch (RuntimeException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
