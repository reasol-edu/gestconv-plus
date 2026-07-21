<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\SanctionMeasure;

final readonly class SanctionFormResult
{
    /**
     * @param array<string, string> $errors campo → mensaje ya traducido
     * @param list<SanctionMeasure> $measures
     */
    public function __construct(
        public array $errors,
        public array $measures,
        public bool $requiresDates,
        public ?\DateTimeImmutable $effectiveFrom,
        public ?\DateTimeImmutable $effectiveTo,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
