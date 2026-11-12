<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\DailyNoteRepository;
use App\Repository\DailyNoteTypeRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Resumen de convivencia de un estudiante para mostrar al registrar un parte: partes del último mes,
 * sanción vigente y notas activas por tipo.
 *
 * No enseña nada que la ficha del estudiante no enseñe al mismo docente: los partes y las sanciones se
 * piden con los mismos filtros por visor (`createFilteredQuery`), y el criterio de «relacionado» (para no
 * dar a entender que un estudiante «no tiene partes» a quien no puede verlos) es el de la ficha. Las notas
 * del día las ve cualquier docente en la ficha, así que aquí también.
 */
final class StudentConductContext
{
    /** Ventana de «partes recientes», en días. */
    public const RECENT_DAYS = 30;

    public function __construct(
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly DailyNoteRepository $dailyNotes,
        private readonly DailyNoteTypeRepository $dailyNoteTypes,
        private readonly FeatureToggle $features,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *     related: bool,
     *     recentReports: int,
     *     recentSerious: int,
     *     sanctionUntil: string|null,
     *     hasActiveSanction: bool,
     *     notes: list<array{type: string, count: int}>
     * }
     */
    public function build(Teacher $viewer, EducationalCentre $centre, Student $student, AcademicYear $year): array
    {
        $studentId = $student->getId()->toRfc4122();
        $today     = $this->clock->now()->setTime(0, 0, 0);
        $from      = $today->modify('-' . self::RECENT_DAYS . ' days');

        /** @var list<IncidentReport> $allReports */
        $allReports = $this->reports->createFilteredQuery($centre, $viewer, $year, ['studentId' => $studentId])->getResult();
        /** @var list<Sanction> $allSanctions */
        $allSanctions = $this->sanctions->createFilteredQuery($centre, $viewer, $year, ['studentId' => $studentId])->getResult();

        $recent = $serious = 0;
        foreach ($allReports as $report) {
            if ($report->getOccurredAt() < $from) {
                continue;
            }
            ++$recent;
            foreach ($report->getBehaviors() as $behavior) {
                if ($behavior->isSerious()) {
                    ++$serious;
                    break;
                }
            }
        }

        $active = false;
        $until  = null;
        foreach ($allSanctions as $sanction) {
            $start = $sanction->getEffectiveFrom();
            $end   = $sanction->getEffectiveTo();
            if ($sanction->isNotified() && $start !== null && $start <= $today && ($end === null || $end >= $today)) {
                $active = true;
                if ($end !== null && ($until === null || $end->format('Y-m-d') > $until)) {
                    $until = $end->format('Y-m-d');
                }
            }
        }

        $notes = [];
        if ($this->features->isEnabledForCentre(Feature::DailyNotes, $centre)) {
            $counts = $this->dailyNotes->countActiveByStudentGroupedByType($student, $year);
            foreach ($this->dailyNoteTypes->findByCentreActive($centre) as $type) {
                $count = $counts[$type->getId()->toRfc4122()] ?? 0;
                if ($count > 0) {
                    $notes[] = ['type' => $type->getName(), 'count' => $count];
                }
            }
        }

        return [
            'related'           => $this->isRelated($viewer, $centre, $student, $year) || $allReports !== [] || $allSanctions !== [],
            'recentReports'     => $recent,
            'recentSerious'     => $serious,
            'hasActiveSanction' => $active,
            'sanctionUntil'     => $until !== null ? (new \DateTimeImmutable($until))->format('d/m/Y') : null,
            'notes'             => $notes,
        ];
    }

    /** Mismo criterio que la ficha del estudiante: administración, comisión, orientación, tutoría o docencia. */
    private function isRelated(Teacher $viewer, EducationalCentre $centre, Student $student, AcademicYear $year): bool
    {
        if ($viewer->isAdmin()
            || $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer)) {
            return true;
        }

        foreach ($student->getGroups() as $group) {
            if ($group->getAcademicYear() === $year
                && ($group->getTutors()->contains($viewer) || $group->getTeachers()->contains($viewer))) {
                return true;
            }
        }

        return false;
    }
}
