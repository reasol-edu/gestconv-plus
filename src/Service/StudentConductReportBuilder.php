<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\DailyNote;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Repository\DailyNoteRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentExitRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reúne el informe de convivencia de un estudiante para un curso y, opcionalmente, un rango de fechas.
 *
 * Nunca incluye más de lo que la ficha del estudiante enseña a quien lo pide: partes y sanciones salen de las
 * mismas consultas por visor (`createFilteredQuery`), las notas del día y las salidas son las que la ficha
 * muestra a cualquier docente (y se omiten si su sección está desactivada), y los datos de contacto de la
 * familia solo se marcan como visibles si {@see StudentContactVisibility} lo permite. El criterio de
 * «relacionado» (para no afirmar que un estudiante no tiene partes a quien no puede verlos) es el de la ficha.
 */
final class StudentConductReportBuilder
{
    public function __construct(
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly DailyNoteRepository $dailyNotes,
        private readonly StudentExitRepository $exits,
        private readonly StudentContactVisibility $contactVisibility,
        private readonly FeatureToggle $features,
        private readonly ClockInterface $clock,
    ) {}

    public function build(
        Teacher $viewer,
        EducationalCentre $centre,
        Student $student,
        AcademicYear $year,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): StudentConductReportData {
        $studentId = $student->getId()->toRfc4122();
        $start     = $from?->setTime(0, 0, 0);
        // El límite superior es exclusivo: el día `$to` entero entra.
        $end = $to?->setTime(0, 0, 0)->modify('+1 day');
        $in  = static fn (\DateTimeImmutable $at): bool => ($start === null || $at >= $start) && ($end === null || $at < $end);

        /** @var list<IncidentReport> $visibleReports */
        $visibleReports = $this->reports->createFilteredQuery($centre, $viewer, $year, ['studentId' => $studentId])->getResult();
        /** @var list<Sanction> $visibleSanctions */
        $visibleSanctions = $this->sanctions->createFilteredQuery($centre, $viewer, $year, ['studentId' => $studentId])->getResult();

        $reports = array_values(array_filter($visibleReports, static fn (IncidentReport $r): bool => $in($r->getOccurredAt())));
        usort($reports, static fn (IncidentReport $a, IncidentReport $b): int => $a->getOccurredAt() <=> $b->getOccurredAt());
        $sanctions = array_values(array_filter($visibleSanctions, static fn (Sanction $s): bool => $in($s->getCreatedAt())));
        usort($sanctions, static fn (Sanction $a, Sanction $b): int => $a->getCreatedAt() <=> $b->getCreatedAt());

        $serious = $prescribed = $notified = 0;
        foreach ($reports as $report) {
            foreach ($report->getBehaviors() as $behavior) {
                if ($behavior->isSerious()) {
                    ++$serious;
                    break;
                }
            }
            if ($report->getPrescribedAt() !== null) {
                ++$prescribed;
            }
            if ($report->isNotified()) {
                ++$notified;
            }
        }

        $today  = $this->clock->now()->setTime(0, 0, 0);
        $active = 0;
        foreach ($sanctions as $sanction) {
            $effectiveFrom = $sanction->getEffectiveFrom();
            $effectiveTo   = $sanction->getEffectiveTo();
            if ($sanction->isNotified() && $effectiveFrom !== null && $effectiveFrom <= $today
                && ($effectiveTo === null || $effectiveTo >= $today)) {
                ++$active;
            }
        }

        $includesNotes = $this->features->isEnabledForCentre(Feature::DailyNotes, $centre);
        $notes         = [];
        $noteTotals    = [];
        if ($includesNotes) {
            /** @var list<DailyNote> $allNotes */
            $allNotes = $this->dailyNotes->createStudentHistoryQuery($student, $year)->getResult();
            $notes    = array_values(array_filter($allNotes, static fn (DailyNote $n): bool => $in($n->getOccurredAt())));
            usort($notes, static fn (DailyNote $a, DailyNote $b): int => $a->getOccurredAt() <=> $b->getOccurredAt());

            $byType = [];
            foreach ($notes as $note) {
                $name = $note->getType()->getName();
                $byType[$name] ??= ['type' => $name, 'active' => 0, 'total' => 0];
                ++$byType[$name]['total'];
                if ($note->isActive()) {
                    ++$byType[$name]['active'];
                }
            }
            $noteTotals = array_values($byType);
        }

        $includesExits = $this->features->isEnabledForCentre(Feature::Exits, $centre);
        $exits         = [];
        if ($includesExits) {
            /** @var list<StudentExit> $allExits */
            $allExits = $this->exits->createStudentHistoryQuery($student)->getResult();
            $exits    = array_values(array_filter(
                $allExits,
                static fn (StudentExit $e): bool => $e->getAcademicYear()->getId()->equals($year->getId()) && $in($e->getExitedAt()),
            ));
            usort($exits, static fn (StudentExit $a, StudentExit $b): int => $a->getExitedAt() <=> $b->getExitedAt());
        }

        $groupNames = [];
        $related    = $viewer->isAdmin()
            || $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        foreach ($student->getGroups() as $group) {
            if ($group->getAcademicYear()->getId()->equals($year->getId())) {
                $groupNames[] = $group->getName();
                if ($group->getTutors()->contains($viewer) || $group->getTeachers()->contains($viewer)) {
                    $related = true;
                }
            }
        }
        // También cuenta haber registrado uno mismo algún parte o sanción del estudiante (sin importar el rango).
        $related = $related || $visibleReports !== [] || $visibleSanctions !== [];

        return new StudentConductReportData(
            student:         $student,
            year:            $year,
            from:            $from,
            to:              $to,
            groupNames:      $groupNames,
            related:         $related,
            showContact:     $this->contactVisibility->isVisibleTo($viewer, $centre, $student),
            reports:         $reports,
            seriousCount:    $serious,
            prescribedCount: $prescribed,
            notifiedCount:   $notified,
            sanctions:       $sanctions,
            activeSanctions: $active,
            includesNotes:   $includesNotes,
            notes:           $notes,
            noteTotals:      $noteTotals,
            includesExits:   $includesExits,
            exits:           $exits,
        );
    }
}
