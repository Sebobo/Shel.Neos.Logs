<?php

declare(strict_types=1);

namespace Shel\Neos\Logs\Command;

/**
 * This file is part of the Shel.Neos.Logs package.
 * (c) by Sebastian Helzle
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Shel\Neos\Logs\Domain\ParsedException;
use Shel\Neos\Logs\Service\LogsService;

/**
 * Commands to list, show and delete logs
 */
#[Flow\Scope('singleton')]
class LogsCommandController extends CommandController
{
    #[Flow\Inject]
    protected LogsService $logsService;

    public function exceptionsCommand(): void
    {
        try {
            $exceptions = $this->logsService->getExceptions();
        } catch (\Exception $e) {
            $this->outputLine('Could not read exceptions: %s', [$e->getMessage()]);
            return;
        }

        $this->output->outputTable(
            array_map(static fn(ParsedException $e) => [
                $e->identifier,
                $e->code,
                $e->getCroppedExcerpt(50),
                $e->date->format(DATE_W3C),
                count($e->getDuplicates()),
            ], $exceptions),
            ['identifier', 'code', 'excerpt', 'date', 'duplicates']
        );
    }

    public function showExceptionCommand(string $identifier = null): void
    {
        // If no identifier is provided, try to get it from the arguments
        if (!$identifier) {
            $identifier = $this->request->getExceedingArguments()[0] ?? null;
        }
        // If still no identifier is provided, show a list of exceptions
        if (!$identifier) {
            $this->outputFormatted('<info>Please provide an identifier or choose one of the following:</info>');
            $exceptions = $this->logsService->getExceptions();
            foreach ($exceptions as $index => $e) {
                $this->outputFormatted(
                    '<b>%d</b>: %s - %s - <error>%s</error>',
                    [
                        $index + 1,
                        $e->identifier,
                        $e->date->format(DATE_W3C),
                        $e->getCroppedExcerpt(80),
                    ]
                );
            }
            $selectedExceptionIndex = $this->output->ask('Enter the number of the exception to show: ');
            if ($selectedExceptionIndex < 1 || $selectedExceptionIndex > count($exceptions)) {
                return;
            }
            $exception = $exceptions[$selectedExceptionIndex - 1] ?? null;
        } else {
            try {
                $exception = $this->logsService->getParsedException($identifier);
            } catch (\Exception $e) {
                $this->outputFormatted('Could not read exception: <error>%s</error>', [$e->getMessage()]);
                return;
            }
        }

        if (!$exception) {
            return;
        }

        $this->outputFormatted('<b>Identifier:</b> %s', [$exception->identifier]);
        $this->outputFormatted('<b>Code:</b> %s', [$exception->code]);
        $this->outputFormatted('<b>Duplicates:</b> %d', [count($exception->getDuplicates())]);
        $this->outputFormatted('<b>Date:</b> %s', [$exception->date->format(DATE_W3C)]);
        $this->outputFormatted('<b>Excerpt:</b> <error>%s</error>', [$exception->excerpt]);
    }
}
