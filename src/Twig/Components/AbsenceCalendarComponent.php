<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Repository\AbsenceRepository;
use App\Service\CalendarMonthGridBuilder;
use App\Service\GroupColorPalette;
use App\Service\TenantContext;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

#[AsLiveComponent]
class AbsenceCalendarComponent extends AbstractCalendarComponent
{
    /** @var list<Absence>|null */
    private ?array $absencesCache = null;

    public function __construct(
        TenantContext $tenantContext,
        TranslatorInterface $translator,
        private readonly AbsenceRepository $absenceRepository,
        private readonly CalendarMonthGridBuilder $gridBuilder,
        private readonly GroupColorPalette $colorPalette,
    ) {
        parent::__construct($tenantContext, $translator);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeks(): array
    {
        $centre       = $this->getTenantContext()->getSelectedCentre();
        $academicYear = $centre !== null ? $this->getTenantContext()->getViewYear($centre) : null;
        if ($centre === null || $academicYear === null) {
            return [];
        }

        $absences = $this->getAbsencesForYear($academicYear);

        return $this->gridBuilder->build(
            $this->year,
            $this->month,
            $absences,
            static fn (Absence $absence): array => [
                'id'    => $absence->getId()->toRfc4122(),
                'start' => $absence->getStartDate(),
                'end'   => $absence->getEndDate(),
            ],
            function (Absence $absence): array {
                $teacher = $absence->getTeacher();

                return [
                    'label'   => $teacher->getName()->full(),
                    'details' => '',
                    'color'   => $this->colorPalette->colorFor($teacher->getId()->toRfc4122()),
                ];
            },
        );
    }

    /**
     * @return list<Absence>
     */
    private function getAbsencesForYear(AcademicYear $academicYear): array
    {
        if ($this->absencesCache === null) {
            $this->absencesCache = $this->absenceRepository->findWithDatesForAcademicYear($academicYear);
        }

        return $this->absencesCache;
    }
}
