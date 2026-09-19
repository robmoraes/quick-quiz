<?php

namespace App\Command;

use App\Repository\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'manager:database:migrate', description: 'Applies pending Manager PostgreSQL migrations.')]
final class MigrateDatabaseCommand extends Command
{
    public function __construct(private readonly MigrationRunner $migrations)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $applied = $this->migrations->migrate();

        if ($applied === []) {
            $io->success('Manager database schema is up to date.');

            return Command::SUCCESS;
        }

        $io->listing($applied);
        $io->success(sprintf('Applied %d Manager database migration(s).', count($applied)));

        return Command::SUCCESS;
    }
}
