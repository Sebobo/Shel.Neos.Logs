<?php
declare(strict_types=1);

namespace Shel\Neos\Logs\Controller;

use Neos\Flow\Mvc\View\JsonView;
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
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'html' => FusionView::class,
        'json' => JsonView::class,
    ];

    /**
     * Renders the app to interact with the nodetype graph
     */
    public function indexAction(): void
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
            $exceptionFiles = array_map(function (string $exceptionFile) {
                $filename = basename($exceptionFile);
                $date = \DateTime::createFromFormat('YmdHi', substr($filename, 0, 12));
                return [
                    'name' => $exceptionFile,
                    'identifier' => $filename,
                    'date' => $date,
                ];
            }, Files::readDirectoryRecursively($this->exceptionFilesUrl, '.txt'));
        } catch (FilesException $e) {
            $exceptionFiles = [];
        }

        $this->view->assignMultiple([
            'logFiles' => $logFiles,
            'exceptionFiles' => $exceptionFiles,
        ]);
    }

    /**
     */
    public function showLogfileAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->logFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, $this->logFilesUrl) !== false && file_exists($filepath)) {
            $fileContent = Files::getFileContents($filepath);

            $lineCount = preg_match_all('/([\d:\-\s]+)\s([\d]+)\s+(\w+)\s+(.+)/', $fileContent, $lines);
            $entries = [];
            for ($i = 0; $i < $lineCount; $i++) {
                $entries[]= [
                    'date' => $lines[1][$i],
                    'level' => $lines[3][$i],
                    'message' => $lines[4][$i],
                ];
            }
        } else {
            $fileContent = 'Error: Logfile not found';
            $entries = [];
        }

        $this->view->assignMultiple([
            'filename' => $filename,
            'content' => $fileContent,
            'entries' => $entries,
        ]);
    }

    /**
     */
    public function showExceptionAction(): void
    {
        [
            'filename' => $filename,
        ] = $this->request->getArguments();

        $filepath = realpath($this->exceptionFilesUrl . '/' . $filename);

        if ($filename && strpos($filepath, $this->exceptionFilesUrl) !== false && file_exists($filepath)) {
            $fileContent = Files::getFileContents($filepath);
        } else {
            $fileContent = 'Error: Exception not found';
        }

        $this->view->assignMultiple([
            'filename' => $filename,
            'content' => $fileContent,
        ]);
    }
}
