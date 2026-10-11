<?php

namespace App\Command;

use App\Service\QuizRuntimeBackendService;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'manager:quiz:runtime-backend', description: 'Explicitly validate/switch the stored runtime backend using complete revisioned verification evidence.')]
final class SetQuizRuntimeBackendCommand extends Command
{
    public function __construct(private readonly QuizRuntimeBackendService $backend) { parent::__construct(); }

    protected function configure(): void
    {
        foreach (['provider', 'expected-revision', 'verification-report'] as $name) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, $name);
        }
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and validate without changing catalog state.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Atomically switch backend after revalidating evidence.');
        $this->addOption('writes-frozen', null, InputOption::VALUE_NONE, 'Attest all authoring/prompt/tag writes are frozen and old writers are stopped. Required for apply.');
        $this->setHelp('No pending legacy revision is promoted. Reconcile publication first, create a revisioned comparison with --legacy-provider-runs=0 for PostgreSQL, or a complete applied export with --snapshot-reader-version=2 for storage fallback. Freeze writes until Manager/API provider configuration, candidate verification and routing are coordinated. The API independently checks its actual legacy-provider run count.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $revision = (string) $input->getOption('expected-revision');
        if (!ctype_digit($revision) || (bool) $input->getOption('dry-run') === (bool) $input->getOption('apply')) {
            $output->writeln('<error>Specify expected revision and exactly one of --dry-run or --apply.</error>');
            return Command::INVALID;
        }
        try {
            $path = (string) $input->getOption('verification-report');
            if (!is_file($path) || !is_readable($path) || filesize($path) > 1048576) {
                throw new RuntimeException('Readable bounded verification report is required.');
            }
            $evidence = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($evidence)) {
                throw new RuntimeException('Verification report must be a JSON object.');
            }
            $report = $this->backend->run((string) $input->getOption('provider'), (int) $revision, $evidence,
                (bool) $input->getOption('apply'), (bool) $input->getOption('writes-frozen'));
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (PDOException) {
            $output->writeln('<error>Backend transition failed because PostgreSQL is unavailable or incompatible.</error>');
        } catch (RuntimeException $error) {
            $output->writeln('<error>'.htmlspecialchars($error->getMessage(), ENT_QUOTES).'</error>');
        } catch (Throwable) {
            $output->writeln('<error>Backend transition failed; state is unchanged.</error>');
        }
        return Command::FAILURE;
    }
}
