<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\DailyNote;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\StudentExit;

/**
 * Contenido del informe de convivencia de un estudiante, ya filtrado para quien lo pide (ver
 * {@see StudentConductReportBuilder}). Las listas van de la más antigua a la más reciente.
 */
final readonly class StudentConductReportData
{
    /**
     * @param list<string>         $groupNames
     * @param list<IncidentReport> $reports
     * @param list<Sanction>       $sanctions
     * @param list<DailyNote>      $notes
     * @param list<StudentExit>    $exits
     * @param list<array{type: string, active: int, total: int}> $noteTotals
     */
    public function __construct(
        public Student $student,
        public AcademicYear $year,
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
        public array $groupNames,
        public bool $related,
        public bool $showContact,
        public array $reports,
        public int $seriousCount,
        public int $prescribedCount,
        public int $notifiedCount,
        public array $sanctions,
        public int $activeSanctions,
        public bool $includesNotes,
        public array $notes,
        public array $noteTotals,
        public bool $includesExits,
        public array $exits,
    ) {}
}
