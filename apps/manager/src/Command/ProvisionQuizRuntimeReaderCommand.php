<?php

namespace App\Command;

use App\Service\QuizRuntimeReaderProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'manager:quiz:provision-reader', description: 'Explicitly provision a dedicated SELECT-only runtime reader (revokes PUBLIC TEMP on this dedicated database).')]
final class ProvisionQuizRuntimeReaderCommand extends Command
{
    public function __construct(private readonly QuizRuntimeReaderProvisioner $provisioner) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('role', null, InputOption::VALUE_REQUIRED, 'Dedicated lowercase PostgreSQL login role.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect the role without changing roles or grants.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Provision using QUIZ_DATABASE_READER_PASSWORD or its __FILE variant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('dry-run') === (bool) $input->getOption('apply')) {
            $output->writeln('<error>Choose exactly one of --dry-run or --apply.</error>');
            return Command::INVALID;
        }
        try {
            $report = $this->provisioner->run((string) $input->getOption('role'), (string) getenv('QUIZ_DATABASE_READER_PASSWORD'), (bool) $input->getOption('apply'));
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (Throwable) {
            $output->writeln('<error>Reader provisioning failed; no credentials are included in this report. Check dedicated role, migrations, and operator privileges.</error>');
            return Command::FAILURE;
        }
    }
}
