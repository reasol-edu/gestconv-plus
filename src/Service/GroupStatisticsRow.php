<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;

/**
 * One row of the "Estadísticas por grupo" report: either a single group's figures, or the
 * aggregated total for a programme/the whole report ($group is null in that case).
 */
final readonly class GroupStatisticsRow
{
    public function __construct(
        public ?Group $group,
        public int $uniqueStudents,
        public int $reportsNormal,
        public int $reportsSerious,
        public int $notifiedNormal,
        public int $notifiedSerious,
        public int $sanctionedNormal,
        public int $sanctionedSerious,
        public int $prescribedNormal,
        public int $prescribedSerious,
        public int $sanctionsCount,
    ) {}

    public function reportsTotal(): int
    {
        return $this->reportsNormal + $this->reportsSerious;
    }

    public function notifiedTotal(): int
    {
        return $this->notifiedNormal + $this->notifiedSerious;
    }

    public function sanctionedTotal(): int
    {
        return $this->sanctionedNormal + $this->sanctionedSerious;
    }

    public function prescribedTotal(): int
    {
        return $this->prescribedNormal + $this->prescribedSerious;
    }
}
