<?php

namespace App\Command;

use App\Service\QuizCatalogImporter;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'manager:quiz:import', description: 'Validate or import the published quiz catalog into PostgreSQL.')]
final class ImportQuizCatalogCommand extends Command
{
    public function __construct(private readonly QuizCatalogImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report without database writes.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the import.');
        $this->addOption('replace', null, InputOption::VALUE_NONE, 'Replace conflicting quiz content explicitly.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $dryRun = (bool) $input->getOption('dry-run');
        $replace = (bool) $input->getOption('replace');
        if ($apply === $dryRun || ($replace && !$apply)) {
            $output->writeln('<error>Select exactly one of --dry-run or --apply; --replace requires --apply.</error>');
            return Command::INVALID;
        }
        try {
            $report = $this->importer->run($apply, $replace);
        } catch (PDOException) {
            $output->writeln('<error>PostgreSQL import failed; no partial catalog was committed.</error>');
            return Command::FAILURE;
        } catch (RuntimeException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');
            return Command::FAILURE;
        }
        $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
