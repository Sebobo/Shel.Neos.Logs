<?php

declare(strict_types=1);

namespace Shel\Neos\Logs;

/**
 *  This file is part of the Shel.Neos.Logs package.
 *  (c) by Sebastian Helzle
 */

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
class ParseException extends \Exception
{
    public function __construct(string $message, int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
