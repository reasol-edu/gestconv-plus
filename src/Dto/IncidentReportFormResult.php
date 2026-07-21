<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\IncidentBehavior;
use App\Entity\LocationOption;

final readonly class IncidentReportFormResult
{
    /**
     * @param array<string, string> $errors campo → mensaje ya traducido
     * @param list<IncidentBehavior> $behaviors
     */
    public function __construct(
        public array $errors,
        public ?LocationOption $location,
        public ?\DateTimeImmutable $occurredAt,
        public array $behaviors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
