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
use Sentry\Logs\Log;
use Shel\Neos\Logs\Service\LogsService;

#[Flow\Scope('singleton')]
class LogsController extends AbstractModuleController
{

    /**
     * @var array
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    #[Flow\InjectConfiguration('pagination.exceptions.pageSize', 'Shel.Neos.Logs')]
    protected int $exceptionsPageSize;

    #[Flow\Inject]
    protected LogsService $logsService;

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
        $logFiles = [];
        $deduplicatedExceptions = [];
        try {
            $logFiles = $this->logsService->getLogFiles();
            $deduplicatedExceptions = $this->logsService->getExceptions();
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Logfiles could not be read', Message::SEVERITY_ERROR);
        }

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

    public function showLogfileAction(): void
    {
        ['filename' => $filename] = $this->request->getArguments();

        $entries = [];
        $levels = [];
        $lineCount = 0;
        $level = $this->request->hasArgument('level') ? $this->request->getArgument('level') : '';
        $limit = $this->request->hasArgument('limit') ? $this->request->getArgument('limit') : 50;

        $fileContent = $this->logsService->getLogFileContents($filename);
        if ($fileContent) {
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

        $filepath = $this->logsService->getValidLogFilepath($filename);
        if ($filepath) {
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
        $identifier = LogsService::sanitiseExceptionIdentifier($identifier);

        $filepath = $this->logsService->getValidExceptionFilepath($identifier);
        $error = false;

        if ($filepath) {
            $fileContent = Files::getFileContents($filepath);
        } else {
            $this->addFlashMessage(sprintf('Exception %s not found', $identifier), Message::SEVERITY_ERROR);
            $error = 'Error: Exception not found';
            $fileContent = '';
        }

        $this->view->assignMultiple([
            'filename' => $identifier,
            'content' => htmlspecialchars($fileContent),
            'error' => $error,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            ...$this->logsService->extractExcerptAndTraceFromException($fileContent),
        ]);
    }

    /**
     * Deletes a single exception identified by its filename and redirects to the index action
     */
    public function deleteExceptionAction(): void
    {
        ['identifier' => $identifier] = $this->request->getArguments();
        $identifier = LogsService::sanitiseExceptionIdentifier($identifier);

        try {
            $this->logsService->deleteExceptionWithDuplicates($identifier);
            $this->addFlashMessage(sprintf('Exception %s deleted', $identifier));
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), Message::SEVERITY_ERROR);
        }
        $this->redirect('index');
    }

    /**
     * Downloads a single exception identified by its filename
     */
    public function downloadExceptionAction(): void
    {
        ['identifier' => $identifier] = $this->request->getArguments();
        $identifier = LogsService::sanitiseExceptionIdentifier($identifier);

        $filepath = $this->logsService->getValidExceptionFilepath($identifier);
        if ($filepath) {
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
        header('Content-Disposition: attachment; filename=' . $identifier . LogsService::EXCEPTION_FILE_EXTENSION);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        echo $content;
        exit;
    }
}
