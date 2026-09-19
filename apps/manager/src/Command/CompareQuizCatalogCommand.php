<?php

namespace App\Command;

use App\Service\QuizContentComparator;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizSourceCatalog;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'manager:quiz:compare', description: 'Compare published quiz JSON with the PostgreSQL projection.')]
final class CompareQuizCatalogCommand extends Command
{
    public function __construct(
        private readonly QuizSourceCatalog $source,
        private readonly QuizProjectionRenderer $renderer,
        private readonly QuizContentComparator $comparator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
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
