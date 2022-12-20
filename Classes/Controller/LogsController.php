<?php
declare(strict_types=1);

namespace Shel\Neos\Logs\Controller;

use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */
class LogsController extends AbstractModuleController
{
    /**
     * @var FusionView
     */
    protected $view;

    /**
     * @var array
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    /**
     * @Flow\InjectConfiguration(path="logFilesUrl", package="Shel.Neos.Logs")
     * @var string
     */
    protected $logFilesUrl;

    /**
     * @Flow\InjectConfiguration(path="exceptionFilesUrl", package="Shel.Neos.Logs")
     * @var string
     */
    protected $exceptionFilesUrl;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

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
    public function indexAction(int $limit = 10): void
    {
        try {
            $logFiles = array_map(function (string $logFile) {
                $filename = basename($logFile);
                return [
                    'name' => basename($logFile),
                    'identifier' => $filename,
                ];
            }, Files::readDirectoryRecursively($this->logFilesUrl, '.log'));
        } catch (FilesException $e) {
            $logFiles = [];
        }

        try {
            $exceptionFiles = Files::readDirectoryRecursively($this->exceptionFilesUrl, '.txt');
            $numberOfExceptions = count($exceptionFiles);
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
            }, array_slice($exceptionFiles, 0, $limit));
        } catch (FilesException $e) {
            $exceptionFiles = [];
        }

        usort($exceptionFiles, function ($a, $b) {
            if ($a['date'] > $b['date']) return -1;
            if ($a['date'] < $b['date']) return 1;
            return 0;
        });

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'logs' => $logFiles,
            'exceptions' => $exceptionFiles,
            'flashMessages' => $flashMessages,
            'limit' => $limit,
            'numberOfExceptions' => $numberOfExceptions
        ]);
    }

    protected function getExcerptFromException(string $content): string
    {
        $excerpt = strip_tags(strtok($content, "\n"));
        $excerpt = str_replace(FLOW_PATH_ROOT, '…/', $excerpt);
        return $excerpt;
    }

    /**
     */
    public function showLogfileAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->logFilesUrl . '/' . $filename);
        $entries = [];
        $levels = [];
        $lineCount = 0;
        $level = $this->request->hasArgument('level') ? $this->request->getArgument('level') : '';
        $limit = $this->request->hasArgument('limit') ? $this->request->getArgument('limit') : 50;

        if ($filename && strpos($filepath, realpath($this->logFilesUrl)) !== false && file_exists($filepath)) {
            $fileContent = Files::getFileContents($filepath);

            $lineCount = preg_match_all('/([\d:\-\s]+)\s([\d]+)(\s+[:.\d]+)?\s+(\w+)\s+(.+)/', $fileContent, $lines);

            for ($i = 0; $i < $lineCount && count($entries) < $limit; $i++) {
                $lineLevel = $lines[4][$i];

                $levels[$lineLevel] = true;

                if ($level && $lineLevel !== $level) {
                    continue;
                }

                $entries[]= [
                    'date' => $lines[1][$i],
                    'ip' => $lines[3][$i],
                    'level' => $lines[4][$i],
                    'message' => htmlspecialchars($lines[5][$i]),
                ];
            }
        } else {
            $this->addFlashMessage('', 'Logfile could not be read', Message::SEVERITY_ERROR);
        }

        $csrfToken = $this->securityContext->getCsrfProtectionToken();
        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'csrfToken' => $csrfToken,
            'filename' => $filename,
            'entries' => $entries,
            'flashMessages' => $flashMessages,
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
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->logFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, realpath($this->logFilesUrl)) !== false && file_exists($filepath)) {
            $this->startFileDownload($filepath, $filename);
        } else {
            $this->addFlashMessage('', sprintf('Logfile %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Shows the content of a single exception identified by its filename
     */
    public function showExceptionAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->exceptionFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, realpath($this->exceptionFilesUrl)) !== false && file_exists($filepath)) {
            $fileContent = Files::getFileContents($filepath);
        } else {
            $fileContent = 'Error: Exception not found';
        }

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $this->view->assignMultiple([
            'filename' => $filename,
            'content' => htmlspecialchars($fileContent),
            'flashMessages' => $flashMessages,
        ]);
    }

    /**
     * Deletes a single exception identified by its filename and redirects to the index action
     */
    public function deleteExceptionAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->exceptionFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, realpath($this->exceptionFilesUrl)) !== false && file_exists($filepath)) {
            if (Files::unlink($filepath)) {
                $this->addFlashMessage('', sprintf('Exception %s deleted', $filename), Message::SEVERITY_OK);
            } else {
                $this->addFlashMessage('', sprintf('Exception %s could not be deleted', $filename), Message::SEVERITY_ERROR);
            }
        } else {
            $this->addFlashMessage('', sprintf('Exception %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Downloads a single exception identified by its filename
     */
    public function downloadExceptionAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->exceptionFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, realpath($this->exceptionFilesUrl)) !== false && file_exists($filepath)) {
            $this->startFileDownload($filepath, $filename);
        } else {
            $this->addFlashMessage('', sprintf('Exception %s not found', $filename), Message::SEVERITY_ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Will start the download of the given file and exits the process
     *
     * @param string $filepath
     * @param string $filename
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
}
