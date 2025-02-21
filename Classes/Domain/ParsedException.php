<?php

declare(strict_types=1);

namespace Shel\Neos\Logs\Domain;

/**
 * This file is part of the Shel.Neos.Logs package.
 * (c) by Sebastian Helzle
 */

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final class ParsedException implements \JsonSerializable
{
    /**
     * @var array<string, \DateTimeInterface>
     */
    private array $duplicates = [];

    private function __construct(
        public readonly string $identifier,
        public readonly string $code,
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
            $data['code'],
            $date,
            $parsedDate,
            $data['excerpt'],
        );
    }

    public function addDuplicate(ParsedException $exception): self
    {
        $this->duplicates[$exception->identifier] = $exception->parsedDate;
        return $this;
    }

    public function getDuplicates(): array
    {
        return $this->duplicates;
    }

    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'code' => $this->code,
            'date' => $this->date->format(DATE_W3C),
            'parsedDate' => $this->parsedDate->format(DATE_W3C),
            'excerpt' => $this->excerpt,
            'duplicates' => array_reduce(
                array_keys($this->duplicates),
                function (array $acc, string $identifier) {
                    $acc[$identifier] = $this->duplicates[$identifier]->format(DATE_W3C);
                    return $acc;
                },
                []
            ),
        ];
    }
}
