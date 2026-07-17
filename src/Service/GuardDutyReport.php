<?php

declare(strict_types=1);

namespace App\Service;

final readonly class GuardDutyReport
{
    /**
     * @param list<GuardDutyRow> $rows
     */
    public function __construct(
        public array $rows,
    ) {}
}
