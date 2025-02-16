<?php

declare(strict_types=1);

namespace Shel\Neos\Logs\Model;

/**
 * This file is part of the Shel.Neos.Logs package.
 * (c) by Sebastian Helzle
 */

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final class ParsedException implements \JsonSerializable
{
    private function __construct(
        public readonly string $identifier,
        public readonly \DateTimeInterface $date,
        public readonly \DateTimeInterface $parsedDate,
        public readonly string $excerpt,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $date = $data['date'] instanceof \DateTimeInterface ? $data['date'] : new \DateTime($data['date']);
        $parsedDate = $data['parsedDate'] instanceof \DateTimeInterface ? $data['parsedDate'] : new \DateTime($data['parsedDate']);
        return new self(
            $data['identifier'],
            $date,
            $parsedDate,
            $data['excerpt'],
        );
    }

    /**
     * Returns true if the exception is recent (less than 60 minutes old)
     */
    public function isRecent(): bool
    {
        return $this->date->getTimestamp() > (time() - 60 * 60);
    }

    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'date' => $this->date->format(DATE_W3C),
            'parsedDate' => $this->parsedDate->format(DATE_W3C),
            'excerpt' => $this->excerpt,
        ];
    }
}
