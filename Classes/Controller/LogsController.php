<?php

declare(strict_types=1);

namespace Shel\Neos\Logs\Controller;

/**
 * This file is part of the Shel.Neos.Logs package.
 * (c) by Sebastian Helzle
 */

use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Utility\Files;

#[Flow\Scope('singleton')]
class LogsController extends AbstractModuleController
{

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

        try {
            $exceptionFiles = Files::readDirectoryRecursively($this->exceptionFilesUrl, '.txt');
            $numberOfExceptions = count($exceptionFiles);
            $numberOfPages = ceil($numberOfExceptions / $this->exceptionsPageSize);
            rsort($exceptionFiles);
            $exceptionFiles = array_map(function (string $exceptionFile) {
                $filename = basename($exceptionFile);
                $date = \DateTime::createFromFormat('YmdHi', substr($filename, 0, 12));
                return [
                    'name' => $exceptionFile,
                    'identifier' => $filename,
                    'date' => $date,
                    'excerpt' => $this->getExcerptFromException(Files::getFileContents($exceptionFile)),
                ];
            }, array_slice($exceptionFiles, $exceptionsPage * $this->exceptionsPageSize, $this->exceptionsPageSize));
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Exception files could not be read', Message::SEVERITY_ERROR);
            $exceptionFiles = [];
            $numberOfExceptions = 0;
            $numberOfPages = 0;
        }

        // Sort exception by date with the newest first
        usort($exceptionFiles, static function ($a, $b) {
            return ($a['date'] <=> $b['date']) * -1;
        });

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'logs' => $logFiles,
            'exceptions' => $exceptionFiles,
            'flashMessages' => $flashMessages,
            'exceptionsPage' => $exceptionsPage,
            'numberOfPages' => $numberOfPages,
            'numberOfExceptions' => $numberOfExceptions,
        ]);
    }

    protected function getExcerptFromException(string $content): string
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

        $filepath = $this->getFilepath($this->logFilesUrl, $filename);
        if ($filename && $this->isFilenameValid($this->logFilesUrl, $filepath)) {
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

        $filepath = $this->getFilepath($this->logFilesUrl, $filename);
        if ($filename && $this->isFilenameValid($this->logFilesUrl, $filepath)) {
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
        ['filename' => $filename] = $this->request->getArguments();

        $filepath = $this->getFilepath($this->exceptionFilesUrl, $filename);
        if ($filename && $this->isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            $fileContent = Files::getFileContents($filepath);
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $filename), Message::SEVERITY_ERROR);
            $fileContent = 'Error: Exception not found';
        }

        $this->view->assignMultiple([
            'filename' => $filename,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            ...$this->extractExcerptAndTraceFromException($fileContent),
        ]);
    }

    protected function getFilepath(string $folderPath, string $filename): string
    {
        return realpath($folderPath . '/' . $filename);
    }

    protected function isFilenameValid(string $folderPath, string $filepath): bool
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
        ['filename' => $filename] = $this->request->getArguments();

        $filepath = $this->getFilepath($this->exceptionFilesUrl, $filename);
        if ($filename && $this->isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            if (Files::unlink($filepath)) {
                $this->addFlashMessage(sprintf('Exception %s deleted', $filename));
            } else {
                $this->addFlashMessage(sprintf('Exception %s could not be deleted', $filename),
                    Message::SEVERITY_ERROR);
            }
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Downloads a single exception identified by its filename
     */
    public function downloadExceptionAction(): void
    {
        ['filename' => $filename] = $this->request->getArguments();

        $filepath = $this->getFilepath($this->exceptionFilesUrl, $filename);
        if ($filename && $this->isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            $this->startFileDownload($filepath, $filename);
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Will start the download of the given file and exits the process
     */
    protected function startFileDownload(string $filepath, string $filename): void
    {
        $content = Files::getFileContents($filepath);
        header('Pragma: no-cache');
        header('Content-type: application/text');
        header('Content-Length: ' . strlen($content));
        header('Content-Disposition: attachment; filename=' . $filename);
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
