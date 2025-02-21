<?php

declare(strict_types=1);

namespace Shel\Neos\Logs\Controller;

/**
 * This file is part of the Shel.Neos.Logs package.
 * (c) by Sebastian Helzle
 */

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Utility\Now;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Utility\Files;
use Shel\Neos\Logs\Domain\ParsedException;
use Shel\Neos\Logs\ParseException;

#[Flow\Scope('singleton')]
class LogsController extends AbstractModuleController
{
    protected const EXCEPTION_FILE_EXTENSION = '.txt';

    /**
     * @var array
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    #[Flow\InjectConfiguration('logFilesUrl', 'Shel.Neos.Logs')]
    protected string $logFilesUrl;

    #[Flow\InjectConfiguration('exceptionFilesUrl', 'Shel.Neos.Logs')]
    protected string $exceptionFilesUrl;

    #[Flow\InjectConfiguration('pagination.exceptions.pageSize', 'Shel.Neos.Logs')]
    protected int $exceptionsPageSize;

    /**
     * @var VariableFrontend
     */
    #[Flow\Inject]
    protected $loggerCache;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'html' => FusionView::class,
        'json' => JsonView::class,
    ];

    /**
     * Renders the app to interact with the nodetype graph
     */
    public function indexAction(int $exceptionsPage = 0): void
    {
        // Retrieve all log files (there shouldn't be more than 10 in 99% of projects)
        try {
            $logFiles = array_map(static function (string $logFile) {
                $filename = basename($logFile);
                return [
                    'name' => basename($logFile),
                    'identifier' => $filename,
                ];
            }, Files::readDirectoryRecursively($this->logFilesUrl, '.log'));
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Logfiles could not be read', Message::SEVERITY_ERROR);
            $logFiles = [];
        }

        /** @var array<string, ParsedException> $exceptions */
        $exceptions = $this->loggerCache->getByTag('exception');

        if (is_dir($this->exceptionFilesUrl)) {
            try {
                // Check for new exceptions
                $exceptionFiles = Files::readDirectoryRecursively($this->exceptionFilesUrl, self::EXCEPTION_FILE_EXTENSION);
                // TODO: Group by excerpt hash or exception code
                foreach ($exceptionFiles as $exceptionPathAndFilename) {
                    $identifier = basename($exceptionPathAndFilename, self::EXCEPTION_FILE_EXTENSION);
                    if (!array_key_exists($identifier, $exceptions)) {
                        $exceptions[$identifier] = $exceptionDto = self::parseException($identifier, $exceptionPathAndFilename);
                        $this->loggerCache->set($identifier, $exceptionDto, ['exception']);
                    }
                }
            } catch (ParseException $e) {
                $this->addFlashMessage($e->getMessage(), 'Exception files could not be parsed', Message::SEVERITY_ERROR);
            } catch (\Exception $e) {
                $this->addFlashMessage($e->getMessage(), 'An error occurred while parsing an exception', Message::SEVERITY_ERROR);
            }
        }

        // Merge exceptions with the same code
        $exceptionsByCode = [];
        foreach ($exceptions as $exception) {
            $hashOrCode = 'CODE_' . ($exception->code ?: md5($exception->excerpt));
            $exceptionsByCode[$hashOrCode][] = $exception;
        }

        $deduplicatedExceptions = [];
        foreach ($exceptionsByCode as $exceptionGroup) {
            // Add exceptions without a code or with a unique code
            if (count($exceptionGroup) <= 1) {
                array_push($deduplicatedExceptions, ...$exceptionGroup);
                continue;
            }
            // Merge the whole group into one exception
            $firstInstance = array_shift($exceptionGroup);
            $deduplicatedException = array_reduce($exceptionGroup, static function (ParsedException $firstInstance, ParsedException $exception) {
                $firstInstance->addDuplicate($exception);
                return $firstInstance;
            }, $firstInstance);
            $deduplicatedExceptions[] = $deduplicatedException;
        }

        // Sort exception by date with the newest first
        usort($deduplicatedExceptions, 'self::compareExceptions');

        $numberOfPages = (int)ceil(count($deduplicatedExceptions) / $this->exceptionsPageSize);
        $exceptionsPage = (int)min($exceptionsPage, $numberOfPages - 1);

        $pagedExceptionGroups = array_slice(
            $deduplicatedExceptions,
            $exceptionsPage * $this->exceptionsPageSize,
            $this->exceptionsPageSize
        );

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $numberOfExceptions = count($deduplicatedExceptions);
        $this->view->assignMultiple([
            'logs' => $logFiles,
            'exceptions' => $pagedExceptionGroups,
            'flashMessages' => $flashMessages,
            'exceptionsPage' => $exceptionsPage,
            'numberOfPages' => $numberOfPages,
            'numberOfExceptions' => $numberOfExceptions,
        ]);
    }

    /**
     * @throws ParseException
     */
    protected static function parseException(string $identifier, string $exceptionPathAndFilename): ParsedException
    {
        $date = \DateTime::createFromFormat('YmdHi', substr($identifier, 0, 12));
        if (!$date) {
            throw new ParseException('Could not parse date from identifier ' . $identifier);
        }

        $fileContent = Files::getFileContents($exceptionPathAndFilename);

        // Extract the exception code from the file content
        preg_match('/Exception #(\d+)/', $fileContent, $matches);

        return ParsedException::fromArray([
            'identifier' => $identifier,
            'code' => $matches[1] ?? '',
            'date' => $date,
            'parsedDate' => new Now(),
            'excerpt' => self::getExcerptFromException($fileContent),
        ]);
    }

    protected static function compareExceptions(ParsedException $a, ParsedException $b): int
    {
        return $b->date <=> $a->date;
    }

    protected static function getExcerptFromException(string $content): string
    {
        preg_match('/^(?s)(.*?)(?:\R{2,}|$)/', strip_tags($content), $excerpt);
        return str_replace([FLOW_PATH_ROOT, "\n"], ['…/', ''], $excerpt[0] ?? '');
    }

    public function showLogfileAction(): void
    {
        ['filename' => $filename] = $this->request->getArguments();

        $entries = [];
        $levels = [];
        $lineCount = 0;
        $level = $this->request->hasArgument('level') ? $this->request->getArgument('level') : '';
        $limit = $this->request->hasArgument('limit') ? $this->request->getArgument('limit') : 50;

        $filepath = self::getFilepath($this->logFilesUrl, $filename);
        if ($filename && self::isFilenameValid($this->logFilesUrl, $filepath)) {
            $fileContent = Files::getFileContents($filepath);

            $lineCount = preg_match_all('/([\d:\-\s]+)\s([\d]+)(\s+[:.\d]+)?\s+(\w+)\s+(.+)/', $fileContent, $lines);

            for ($i = 0; $i <= 5; $i++) {
                $lines[$i] = array_reverse($lines[$i]);
            }

            for ($i = 0; $i < $lineCount && count($entries) < $limit; $i++) {
                $lineLevel = $lines[4][$i];

                $levels[$lineLevel] = true;

                if ($level && $lineLevel !== $level) {
                    continue;
                }

                $entries[] = [
                    'date' => $lines[1][$i],
                    'ip' => $lines[3][$i],
                    'level' => $lines[4][$i],
                    'message' => htmlspecialchars($lines[5][$i], ENT_QUOTES | ENT_HTML5),
                ];
            }
        } else {
            $this->addFlashMessage('Logfile could not be read', Message::SEVERITY_ERROR);
        }

        $this->view->assignMultiple([
            'csrfToken' => $this->securityContext->getCsrfProtectionToken(),
            'filename' => $filename,
            'entries' => $entries,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'levels' => array_keys($levels),
            'level' => $level,
            'lineCount' => $lineCount,
            'limit' => $limit,
        ]);
    }

    /**
     * Downloads a single exception identified by its filename
     */
    public function downloadLogfileAction(): void
    {
        ['filename' => $filename] = $this->request->getArguments();

        $filepath = self::getFilepath($this->logFilesUrl, $filename);
        if ($filename && self::isFilenameValid($this->logFilesUrl, $filepath)) {
            $this->startFileDownload($filepath, $filename);
        } else {
            $this->addFlashMessage(sprintf('Logfile %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Shows the content of a single exception identified by its filename
     */
    public function showExceptionAction(): void
    {
        ['identifier' => $identifier] = $this->request->getArguments();
        $filepath = self::getFilepath($this->exceptionFilesUrl, $identifier . self::EXCEPTION_FILE_EXTENSION);
        $error = false;
        $fileContent = '';

        if ($identifier && self::isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            $fileContent = Files::getFileContents($filepath);
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $identifier), Message::SEVERITY_ERROR);
            $error = 'Error: Exception not found';
        }

        $this->view->assignMultiple([
            'filename' => $identifier,
            'content' => htmlspecialchars($fileContent),
            'error' => $error,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            ...$this->extractExcerptAndTraceFromException($fileContent),
        ]);
    }

    protected static function getFilepath(string $folderPath, string $filename): string
    {
        $path = realpath($folderPath . '/' . $filename);
        if ($path === false) {
            $path = '';
        }
        return $path;
    }

    protected static function isFilenameValid(string $folderPath, string $filepath): bool
    {
        if (!$filepath) {
            return false;
        }
        return file_exists($filepath) && str_contains($filepath, realpath($folderPath));
    }

    /**
     * Deletes a single exception identified by its filename and redirects to the index action
     */
    public function deleteExceptionAction(): void
    {
        ['identifier' => $identifier] = $this->request->getArguments();

        $filepath = self::getFilepath($this->exceptionFilesUrl, $identifier . self::EXCEPTION_FILE_EXTENSION);
        if ($identifier && self::isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            if (Files::unlink($filepath)) {
                $this->addFlashMessage(sprintf('Exception %s deleted', $identifier));
            } else {
                $this->addFlashMessage(sprintf('Exception %s could not be deleted', $identifier),
                    Message::SEVERITY_ERROR);
            }
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $identifier), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Downloads a single exception identified by its filename
     */
    public function downloadExceptionAction(): void
    {
        ['identifier' => $identifier] = $this->request->getArguments();

        $filepath = self::getFilepath($this->exceptionFilesUrl, $identifier . self::EXCEPTION_FILE_EXTENSION);
        if ($identifier && self::isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            $this->startFileDownload($filepath, $identifier);
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $identifier), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Will start the download of the given file and exits the process
     */
    protected function startFileDownload(string $filepath, string $identifier): void
    {
        $content = Files::getFileContents($filepath);
        header('Pragma: no-cache');
        header('Content-type: application/text');
        header('Content-Length: ' . strlen($content));
        header('Content-Disposition: attachment; filename=' . $identifier . self::EXCEPTION_FILE_EXTENSION);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        echo $content;

        exit;
    }

    /**
     * @return array{excerpt: string, stacktrace: string}
     */
    protected function extractExcerptAndTraceFromException(string $fileContent): array
    {
        $content = htmlspecialchars($fileContent, ENT_QUOTES | ENT_HTML5);
        preg_match('/^(?s)(.*?)(?:\R{2,}|$)(.*)/', $content, $matches);
        $excerpt = str_replace(FLOW_PATH_ROOT, '…/', $matches[1] ?? '');;
        $stacktrace = str_replace(FLOW_PATH_ROOT, '…/', $matches[2] ?? '');

        return [
            'excerpt' => $excerpt,
            'stacktrace' => $stacktrace,
        ];
    }
}
