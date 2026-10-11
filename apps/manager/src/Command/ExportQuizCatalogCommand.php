<?php

namespace App\Command;

use App\Service\QuizLegacyExportService;
use App\Storage\ContentStorageFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'manager:quiz:export', description: 'Stream an explicit revision to fresh legacy JSON storage; never changes publication state.')]
final class ExportQuizCatalogCommand extends Command
{
    public function __construct(private readonly QuizLegacyExportService $exporter) { parent::__construct(); }

    protected function configure(): void
    {
        foreach (['format' => 'legacy-json', 'target' => null, 'expected-revision' => null, 'directory' => null,
            'bucket' => null, 'prefix' => null, 'report' => null, 'snapshot-reader-version' => null] as $name => $default) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, $name, $default);
        }
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and count without target writes.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Write and read-back verify every object in a fresh target.');
        $this->setHelp('Use --directory=/absolute/new/path for local, or --bucket=NAME --prefix=isolated/new/prefix for s3. S3 region/endpoint/path-style use existing environment settings. Store --report outside the export target. Freeze all authoring, prompt and tag writes through any coordinated backend switch. A failed export leaves an incomplete target; use a fresh target for retry.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $input->getOption('target');
        $revision = (string) $input->getOption('expected-revision');
        $directory = (string) $input->getOption('directory');
        $prefix = trim((string) $input->getOption('prefix'), '/');
        $reportPath = (string) $input->getOption('report');
        $reader = $input->getOption('snapshot-reader-version');
        if ((bool) $input->getOption('dry-run') === (bool) $input->getOption('apply')
            || $input->getOption('format') !== 'legacy-json' || !ctype_digit($revision)
            || !in_array($target, ['local', 's3'], true)
            || ($reader !== null && !ctype_digit((string) $reader))
            || ($target === 'local' && (!str_starts_with($directory, '/') || $directory === '/' || preg_match('@/(\.\.?)(/|$)@', $directory)))
            || ($reportPath !== '' && (!str_starts_with($reportPath, '/') || preg_match('@/(\.\.?)(/|$)@', $reportPath)))
            || ($target === 's3' && ($prefix === '' || trim((string) $input->getOption('bucket')) === ''))
            || ($target === 'local' && $reportPath !== '' && str_starts_with($reportPath, rtrim($directory, '/').'/'))) {
            $output->writeln('<error>Specify format, explicit fresh target, expected revision, and exactly one of --dry-run/--apply. Report must be outside the target.</error>');
            return Command::INVALID;
        }
        try {
            $storage = ContentStorageFactory::create($target, $directory, (string) (getenv('AWS_REGION') ?: 'us-east-1'),
                (string) $input->getOption('bucket'), $prefix, (string) getenv('S3_ENDPOINT_URL'),
                filter_var(getenv('S3_FORCE_PATH_STYLE'), FILTER_VALIDATE_BOOL));
            $report = $this->exporter->export((int) $revision, $storage, (bool) $input->getOption('apply'));
            if ($reader !== null) {
                $report['snapshotReaderVersion'] = (int) $reader;
                $report['snapshotReaderVersionSource'] = 'operator-attested-compatible-api-image';
            }
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($reportPath !== '' && file_put_contents($reportPath, $json."\n", LOCK_EX) === false) {
                throw new \RuntimeException('Cannot persist export report.');
            }
            $output->writeln($json);
            return Command::SUCCESS;
        } catch (Throwable) {
            $failure = '{"type":"legacy-export","complete":false,"verified":false,"error":"Export failed; target must not be used for rollback."}';
            if ($reportPath !== '') {
                @file_put_contents($reportPath, $failure."\n", LOCK_EX);
            }
            $output->writeln($failure);
            return Command::FAILURE;
        }
    }
}
