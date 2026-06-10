<?php

namespace App\Command;

use App\Repository\AdminRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'manager:admin:create', description: 'Creates a QuickQuiz Manager administrator.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly AdminRepository $admins)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email.')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        $this->admins->createAdmin($email, $password);
        $io->success(sprintf('Admin %s created.', strtolower(trim($email))));

        return Command::SUCCESS;
    }
}
