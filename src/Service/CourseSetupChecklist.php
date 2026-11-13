<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SetupStepConfirmation;
use App\Repository\GroupRepository;
use App\Repository\GroupTeacherRepository;
use App\Repository\NonWorkingDayRepository;
use App\Repository\SetupStepConfirmationRepository;
use App\Repository\StudentRepository;
use App\Repository\TeacherRepository;
use App\Repository\TimeSlotRepository;

/**
 * Lista de comprobación de inicio de curso (la del manual) calculada a partir de los datos del curso activo del
 * centro: curso activo, profesorado, alumnado, tutorías… Los pasos que no se pueden detectar (revisar catálogos y
 * ajustes) se confirman a mano. Siempre evalúa el curso **activo**, no el que se esté consultando.
 *
 * Coste fijo: un puñado de recuentos, no depende del tamaño de los datos.
 */
final class CourseSetupChecklist
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly GroupTeacherRepository $groupTeachers,
        private readonly TimeSlotRepository $timeSlots,
        private readonly NonWorkingDayRepository $nonWorkingDays,
        private readonly SetupStepConfirmationRepository $confirmations,
        private readonly FeatureToggle $features,
    ) {}

    public function build(EducationalCentre $centre): SetupChecklist
    {
        $year = $centre->getActiveAcademicYear();

        if ($year === null) {
            // Sin curso activo solo se puede hacer el primer paso; el resto espera.
            $items = [];
            foreach (SetupStep::cases() as $step) {
                $items[] = $step === SetupStep::ActiveYear
                    ? new SetupChecklistItem($step, false, true, false)
                    : new SetupChecklistItem($step, false, $step->isRequiredByDefault(), true);
            }

            return new SetupChecklist(null, $items);
        }

        $teacherCount    = $this->teachers->countByAcademicYear($year);
        $groupCount      = $this->groups->countByActiveYearOfCentre($centre, $year);
        $studentCount    = $this->students->countByActiveYear($centre, null, $year);
        $withoutTutor    = $groupCount > 0 ? $this->groups->countWithoutTutor($year) : 0;
        $assignments     = $this->groupTeachers->countByAcademicYear($year);
        $slotCount       = $this->timeSlots->countByAcademicYear($year);
        $dayCount        = $this->nonWorkingDays->countByAcademicYear($year);
        $roleCount       = $centre->getCommitteeMembers()->count() + $centre->getCounselors()->count() + $centre->getActivityCoordinators()->count();
        $confirmed       = $this->confirmations->findByAcademicYear($year);
        $usesAbsences    = $this->features->isEnabledForCentre(Feature::Absences, $centre);

        $items = [];
        foreach (SetupStep::cases() as $step) {
            $required = $step->isRequiredByDefault() || ($step === SetupStep::TimeSlots && $usesAbsences);

            $items[] = match ($step) {
                SetupStep::ActiveYear     => new SetupChecklistItem($step, true, $required, false, 'setup.detail.year', ['%year%' => $year->getName()]),
                SetupStep::Teachers       => new SetupChecklistItem($step, $teacherCount > 0, $required, false, $teacherCount > 0 ? 'setup.detail.teachers' : null, ['%count%' => $teacherCount]),
                SetupStep::Students       => new SetupChecklistItem($step, $studentCount > 0 && $groupCount > 0, $required, false, $studentCount > 0 ? 'setup.detail.students' : null, ['%students%' => $studentCount, '%groups%' => $groupCount]),
                SetupStep::Tutors         => $this->tutorsItem($step, $required, $groupCount, $withoutTutor),
                SetupStep::GroupTeachers  => new SetupChecklistItem($step, $assignments > 0, $required, false, $assignments > 0 ? 'setup.detail.assignments' : null, ['%count%' => $assignments]),
                SetupStep::TimeSlots      => new SetupChecklistItem($step, $slotCount > 0, $required, false, $slotCount > 0 ? 'setup.detail.time_slots' : null, ['%count%' => $slotCount]),
                SetupStep::NonWorkingDays => new SetupChecklistItem($step, $dayCount > 0, $required, false, $dayCount > 0 ? 'setup.detail.days' : null, ['%count%' => $dayCount]),
                SetupStep::Roles          => new SetupChecklistItem($step, $roleCount > 0, $required, false, $roleCount > 0 ? 'setup.detail.roles' : null, ['%count%' => $roleCount]),
                SetupStep::Catalogues,
                SetupStep::Settings       => $this->manualItem($step, $required, $confirmed[$step->value] ?? null),
            };
        }

        return new SetupChecklist($year, $items);
    }

    private function tutorsItem(SetupStep $step, bool $required, int $groupCount, int $withoutTutor): SetupChecklistItem
    {
        if ($groupCount === 0) {
            return new SetupChecklistItem($step, false, $required, false, 'setup.detail.no_groups');
        }
        if ($withoutTutor > 0) {
            return new SetupChecklistItem($step, false, $required, false, 'setup.detail.tutors_missing', ['%missing%' => $withoutTutor, '%count%' => $groupCount]);
        }

        return new SetupChecklistItem($step, true, $required, false, 'setup.detail.tutors_all', ['%count%' => $groupCount]);
    }

    private function manualItem(SetupStep $step, bool $required, ?SetupStepConfirmation $confirmation): SetupChecklistItem
    {
        return new SetupChecklistItem(
            $step,
            $confirmation !== null,
            $required,
            false,
            confirmedBy: $confirmation?->getConfirmedBy()?->getName()->full(),
            confirmedAt: $confirmation?->getConfirmedAt(),
        );
    }
}
