<?php

declare(strict_types=1);

namespace Shel\Neos\Logs\Service;

/**
 * This file is part of the Shel.Neos.Logs package.
 * (c) by Sebastian Helzle
 */

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Now;
use Neos\Utility\Files;
use Shel\Neos\Logs\Domain\ParsedException;
use Shel\Neos\Logs\ParseException;

#[Flow\Scope('singleton')]
class LogsService
{
    public const EXCEPTION_FILE_EXTENSION = '.txt';

    /**
     * @var VariableFrontend
     */
    #[Flow\Inject]
    protected $loggerCache;

    #[Flow\InjectConfiguration('logFilesUrl', 'Shel.Neos.Logs')]
    protected ?string $logFilesUrl;

    #[Flow\InjectConfiguration('exceptionFilesUrl', 'Shel.Neos.Logs')]
    protected ?string $exceptionFilesUrl;

    /**
     * TODO: Introduce DTO for log files
     * @return array{name: string, identifier: string}[]
     */
    public function getLogFiles(): array
    {
        // Retrieve all log files (there shouldn't be more than 10 in 99% of projects)
        try {
            return array_map(static function (string $logFile) {
                $filename = basename($logFile);
                return [
                    'name' => basename($logFile),
                    'identifier' => $filename,
                ];
            }, Files::readDirectoryRecursively($this->logFilesUrl, '.log'));
        } catch (\Exception $e) {
            throw new \RuntimeException('Logfiles could not be read: ' . $e->getMessage(), 1740485516, $e);
        }
    }

    /**
     * @return ParsedException[]
     * @throws \RuntimeException
     */
    public function getExceptions(): array
    {
        /** @var array<string, ParsedException> $exceptions */
        $exceptions = $this->loggerCache->getByTag('exception');

        if (is_dir($this->exceptionFilesUrl)) {
            try {
                // Check for new exceptions
                $exceptionFiles = Files::readDirectoryRecursively(
                    $this->exceptionFilesUrl,
                    self::EXCEPTION_FILE_EXTENSION
                );
                // TODO: Group by excerpt hash or exception code
                foreach ($exceptionFiles as $exceptionPathAndFilename) {
                    $identifier = basename($exceptionPathAndFilename, self::EXCEPTION_FILE_EXTENSION);
                    if (!array_key_exists($identifier, $exceptions)) {
                        $exceptions[$identifier] = $exceptionDto = self::parseException(
                            $identifier,
                            $exceptionPathAndFilename
                        );
                        $this->loggerCache->set($identifier, $exceptionDto, ['exception']);
                    }
                }
            } catch (ParseException $e) {
                throw new \RuntimeException('Exception files could not be parsed: ' . $e->getMessage(), 1740485512, $e);
            } catch (\Exception $e) {
                throw new \RuntimeException('Exception files could not be read: ' . $e->getMessage(), 1740485531, $e);
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
            $deduplicatedException = array_reduce(
                $exceptionGroup,
                static function (ParsedException $firstInstance, ParsedException $exception) {
                    $firstInstance->addDuplicate($exception);
                    return $firstInstance;
                },
                $firstInstance
            );
            $deduplicatedExceptions[] = $deduplicatedException;
        }

        // Sort exception by date with the newest first
        usort($deduplicatedExceptions, 'self::compareExceptions');

        return $deduplicatedExceptions;
    }

    public function getLogFileContents($filename): ?string
    {
        $filepath = self::getFilepath($this->logFilesUrl, $filename);
        if ($filename && self::isFilenameValid($this->logFilesUrl, $filepath)) {
            return Files::getFileContents($filepath);
        }
        return null;
    }

    public static function sanitiseExceptionIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-z0-9]/', '', $identifier) ?? '';
    }

    public function getValidExceptionFilepath(string $identifier): ?string
    {
        $filepath = self::getFilepath($this->exceptionFilesUrl, $identifier . self::EXCEPTION_FILE_EXTENSION);
        if (self::isFilenameValid($this->exceptionFilesUrl, $filepath)) {
            return $filepath;
        }
        return null;
    }

    public function getValidLogFilepath(string $filename): ?string
    {
        $filepath = self::getFilepath($this->logFilesUrl, $filename);
        if ($filename && self::isFilenameValid($this->logFilesUrl, $filepath)) {
            return $filepath;
        }
        return null;
    }

    /**
     * @return array{excerpt: string, stacktrace: string}
     */
    public function extractExcerptAndTraceFromException(string $fileContent): array
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

    protected static function compareExceptions(ParsedException $a, ParsedException $b): int
    {
        return $b->date <=> $a->date;
    }

    protected static function getExcerptFromException(string $content): string
    {
        preg_match('/^(?s)(.*?)(?:\R{2,}|$)/', strip_tags($content), $excerpt);
        return str_replace([FLOW_PATH_ROOT, "\n"], ['…/', ''], $excerpt[0] ?? '');
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

    /**
     * @throws ParseException|\RuntimeException
     */
    public function getParsedException(string $identifier): ParsedException
    {
        $exception = $this->loggerCache->get($identifier);
        if ($exception) {
            return $exception;
        }
        $filepath = $this->getValidExceptionFilepath($identifier);
        if (!$filepath) {
            throw new \RuntimeException('Exception not found', 1740487763);
        }
        return self::parseException($identifier, $filepath);
    }

    public function deleteExceptionWithDuplicates($identifier): void
    {
        $exceptions = $this->getExceptions();
        foreach ($exceptions as $exception) {
            if ($exception->identifier === $identifier
                || array_key_exists($identifier, $exception->getDuplicates())) {
                $identifiersToDelete = [$exception->identifier, ...array_keys($exception->getDuplicates())];
                foreach ($identifiersToDelete as $identifierToDelete) {
                    $filepath = $this->getValidExceptionFilepath($identifierToDelete);
                    if ($filepath && !Files::unlink($filepath)) {
                        throw new \RuntimeException(
                            sprintf('Exception %s could not be deleted', $identifierToDelete),
                            1762766217
                        );
                    }
                }
                $this->loggerCache->flushByTag('exception');
                break;
            }
        }
    }
}
