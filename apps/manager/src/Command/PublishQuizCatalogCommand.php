<?php

namespace App\Command;

use App\Exception\QuizPublicationException;
use App\Service\QuizPublicationService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'manager:quiz:publish', description: 'Publish or retry the current PostgreSQL quiz revision.')]
final class PublishQuizCatalogCommand extends Command
{
    public function __construct(private readonly QuizPublicationService $publication)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED,
            'Publish only one theme when it covers the entire pending revision.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $theme = $input->getOption('theme');
            $result = $this->publication->publishCurrent(is_string($theme) ? $theme : null);
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (QuizPublicationException $error) {
            $output->writeln(sprintf('<error>Quiz revision %d remains unpublished.</error>', $error->revision));
            return Command::FAILURE;
        } catch (RuntimeException) {
            $output->writeln('<error>Quiz publication could not be completed.</error>');
            return Command::FAILURE;
        }
    }
}
