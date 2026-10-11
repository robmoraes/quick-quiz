<?php

namespace App\Service;

use App\Storage\ContentStorage;
use App\Storage\IterableContentStorage;
use RuntimeException;

final class QuizLegacyExportService
{
    public function __construct(private readonly QuizCatalogStream $stream) {}

    /** @return array<string,mixed> */
    public function export(int $revision, ContentStorage $target, bool $apply): array
    {
        foreach ($this->inventory($target) as $_) {
            throw new RuntimeException('Legacy export target must be a fresh isolated directory or prefix.');
        }
        $report = $this->stream->inspect($revision, $apply ? function (string $key, string $contents) use ($target): void {
            $target->write($key, $contents);
            if (!$this->equal($key, $contents, $target->read($key))) {
                throw new RuntimeException('Legacy export read-back verification failed.');
            }
        } : null);
        $count = 0;
        foreach ($this->inventory($target) as $_) {
            ++$count;
        }
        if ($apply && $count !== $report['objectCount']) {
            throw new RuntimeException('Legacy export inventory verification failed.');
        }
        return ['type' => 'legacy-export', 'target' => $target->description(), 'applied' => $apply,
            'verified' => $apply, 'targetObjectCount' => $count] + $report;
    }

    /** @return array<string,mixed> */
    public function compare(int $revision, ContentStorage $source, bool $exactInventory = false): array
    {
        $differences = [];
        $differenceCount = 0;
        $report = $this->stream->inspect($revision, function (string $key, string $contents) use ($source, &$differences, &$differenceCount): void {
            if (!$source->exists($key) || !$this->equal($key, $contents, $source->read($key))) {
                ++$differenceCount;
                if (count($differences) < 50) {
                    $differences[] = $key;
                }
            }
        });
        $count = 0;
        foreach ($this->inventory($source) as $key) {
            // Shared storage may contain Ads and unrelated prompt types. They are not part of this projection.
            if ($exactInventory || ($key !== 'ads/ads.json' && (str_ends_with($key, '.json')
                || str_ends_with($key, '/ai-prompts/question-solution-prompt.txt')))) {
                ++$count;
            }
        }
        $equal = $differenceCount === 0 && $count === $report['objectCount'];
        return ['type' => 'comparison', 'target' => $source->description(), 'verified' => $equal,
            'targetObjectCount' => $count, 'differenceCount' => $differenceCount,
            'differences' => $differences, 'comparison' => ['equal' => $equal]] + $report;
    }

    /** @return iterable<string> */
    private function inventory(ContentStorage $storage): iterable
    {
        yield from $storage instanceof IterableContentStorage ? $storage->iterate('') : $storage->list('');
    }

    private function equal(string $key, string $expected, string $actual): bool
    {
        if (!str_ends_with($key, '.json')) {
            return rtrim($expected, "\r\n") === rtrim($actual, "\r\n");
        }
        try {
            $left = json_decode($expected, false, flags: JSON_THROW_ON_ERROR);
            $right = json_decode($actual, false, flags: JSON_THROW_ON_ERROR);
            return $left instanceof \stdClass && $right instanceof \stdClass && $this->stream->canonicalJson($left) === $this->stream->canonicalJson($right);
        } catch (\JsonException) {
            return false;
        }
    }
}
