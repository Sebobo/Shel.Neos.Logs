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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

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

        if ($exceptions === []) {
            $this->outputLine('No exceptions found.');
            return;
        }

        $input = new ArgvInput();
        $output = new ConsoleOutput();
        $helper = new QuestionHelper();

        $this->renderExceptionTable($output, $exceptions);

        while (true) {
            $question = new ChoiceQuestion(
                "\n<info>Choose an action:</info>",
                [
                    'list' => 'List exceptions',
                    'search' => 'Search exceptions',
                    'view' => 'View exception details',
                    'quit' => 'Quit',
                ],
                'list'
            );
            $action = $helper->ask($input, $output, $question);

            if ($action === 'quit' || $action === null) {
                $output->writeln('<info>Goodbye!</info>');
                break;
            }

            match ($action) {
                'list' => $this->renderExceptionTable($output, $exceptions),
                'search' => $this->searchExceptions($output, $exceptions, $input, $helper),
                'view' => $this->viewException($output, $exceptions, $input, $helper),
                default => null,
            };
        }
    }

    private function renderExceptionTable(OutputInterface $output, array $exceptions): void
    {
        $table = new Table($output);
        $table->setHeaders(['#', 'Identifier', 'Code', 'Excerpt', 'Date', 'Duplicates']);
        $rows = [];
        foreach ($exceptions as $index => $e) {
            $rows[] = [
                $index + 1,
                $e->identifier,
                $e->code ?: '-',
                $e->getCroppedExcerpt(50),
                $e->date->format('Y-m-d H:i:s'),
                count($e->getDuplicates()) ?: '-',
            ];
        }
        $table->setRows($rows);
        $table->render();
    }

    private function searchExceptions(OutputInterface $output, array $exceptions, ArgvInput $input, QuestionHelper $helper): void
    {
        $question = new Question('<info>Search term:</info> ');
        $term = $helper->ask($input, $output, $question);

        if ($term === null || $term === '') {
            $output->writeln('<error>No search term provided.</error>');
            return;
        }

        $term = strtolower($term);
        $results = array_values(array_filter($exceptions, static fn(ParsedException $e) =>
            str_contains(strtolower($e->identifier), $term)
            || str_contains($e->code, $term)
            || str_contains(strtolower($e->excerpt), $term)
            || str_contains($e->date->format('Y-m-d H:i:s'), $term)
        ));

        if ($results === []) {
            $output->writeln(sprintf("<comment>No exceptions found matching '%s'.</comment>", $term));
            return;
        }

        $this->renderExceptionTable($output, $results);
    }

    private function viewException(OutputInterface $output, array $exceptions, ArgvInput $input, QuestionHelper $helper): void
    {
        $question = new Question('<info>Enter exception number or identifier:</info> ');
        $answer = $helper->ask($input, $output, $question);

        if ($answer === null || $answer === '') {
            return;
        }

        $exception = null;

        if (is_numeric($answer)) {
            $index = (int)$answer - 1;
            $exceptions = $this->logsService->getExceptions();
            if (isset($exceptions[$index])) {
                $exception = $exceptions[$index];
                $this->outputFormatted(
                    '<b>%d</b>: %s - %s - <error>%s</error>',
                    [
                        $index + 1,
                        $exception->identifier,
                        $exception->date->format(DATE_W3C),
                        $exception->getCroppedExcerpt(80),
                    ]
                );
            }
            $selectedExceptionIndex = $this->output->ask('Enter the number of the exception to show: ');
            if ($selectedExceptionIndex < 1 || $selectedExceptionIndex > count($exceptions)) {
                return;
            }
            $exception = $exceptions[$selectedExceptionIndex - 1] ?? null;
        } else {
            $matches = array_values(array_filter(
                $exceptions,
                static fn(ParsedException $e) => $e->identifier === $answer
            ));
            if ($matches !== []) {
                $exception = $matches[0];
            }
        }

        if ($exception === null) {
            try {
                $exception = $this->logsService->getParsedException($answer);
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Could not read exception: %s</error>', $e->getMessage()));
                return;
            }
        }

        if ($exception === null) {
            $output->writeln('<error>Exception not found.</error>');
            return;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Identifier:</info>  %s', $exception->identifier));
        $output->writeln(sprintf('<info>Code:</info>         %s', $exception->code ?: '-'));
        $output->writeln(sprintf('<info>Date:</info>         %s', $exception->date->format(DATE_W3C)));
        $output->writeln(sprintf('<info>Duplicates:</info>   %d', count($exception->getDuplicates())));
        $output->writeln('<info>Excerpt:</info>');
        $output->writeln(sprintf('<error>%s</error>', $exception->excerpt));
    }
}
