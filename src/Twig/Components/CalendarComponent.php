<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Repository\AbsenceRepository;
use App\Repository\SanctionRepository;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\CalendarMonthGridBuilder;
use App\Service\GroupColorPalette;
use App\Service\NonWorkingDayChecker;
use App\Service\TenantContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Calendario mensual unificado: sanciones (todo el profesorado), ausencias
 * previstas (solo administradores de centro) y eventos de centro (según
 * visibilidad — generales para todos, restringidos según grupo impartido o
 * tutorizado), todo en la misma cuadrícula.
 */
#[AsLiveComponent]
class CalendarComponent extends AbstractCalendarComponent
{
    private const array ABSENCE_COLOR  = ['bg' => 'bg-amber-100', 'text' => 'text-amber-800', 'border' => 'border-amber-300'];
    private const array GENERAL_EVENT_COLOR = ['bg' => 'bg-sky-100', 'text' => 'text-sky-800', 'border' => 'border-sky-300'];

    /** @var list<Sanction|Absence|SchoolEvent>|null */
    private ?array $itemsCache = null;

    public function __construct(
        TenantContext $tenantContext,
        TranslatorInterface $translator,
        NonWorkingDayChecker $nonWorkingDayChecker,
        ClockInterface $clock,
        private readonly SanctionRepository $sanctionRepository,
        private readonly AbsenceRepository $absenceRepository,
        private readonly SchoolEventRepository $eventRepository,
        private readonly CalendarMonthGridBuilder $gridBuilder,
        private readonly GroupColorPalette $colorPalette,
    ) {
        parent::__construct($tenantContext, $translator, $nonWorkingDayChecker, $clock);
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

        $items = $this->getItemsForYear($centre, $academicYear);

        return $this->gridBuilder->build(
            $this->year,
            $this->month,
            $items,
            function (Sanction|Absence|SchoolEvent $item): ?array {
                if ($item instanceof Sanction) {
                    $start = $item->getEffectiveFrom();

                    return $start === null ? null : [
                        'id'    => 'sanction-' . $item->getId()->toRfc4122(),
                        'start' => $start,
                        'end'   => $item->getEffectiveTo() ?? $start,
                    ];
                }
                if ($item instanceof Absence) {
                    return [
                        'id'    => 'absence-' . $item->getId()->toRfc4122(),
                        'start' => $item->getStartDate(),
                        'end'   => $item->getEndDate(),
                    ];
                }

                return [
                    'id'    => 'event-' . $item->getId()->toRfc4122(),
                    'start' => $item->getDate(),
                    'end'   => $item->getDate(),
                ];
            },
            function (Sanction|Absence|SchoolEvent $item): array {
                if ($item instanceof Sanction) {
                    $group = $item->getGroup();

                    return [
                        'label'   => $item->getStudent()->getName()->full() . ' · ' . $group->getName(),
                        'details' => $item->getCalendarLabel() ?? trim(strip_tags($item->getDetails())),
                        'color'   => $this->colorPalette->colorFor($group->getId()->toRfc4122()),
                        'icon'    => 'heroicons:exclamation-triangle',
                    ];
                }
                if ($item instanceof Absence) {
                    return [
                        'label'   => $item->getTeacher()->getName()->full(),
                        'details' => '',
                        'color'   => self::ABSENCE_COLOR,
                        'icon'    => 'heroicons:user-circle',
                    ];
                }

                $firstGroup = $item->getGroups()->first();
                $color      = $item->isGeneral() || $firstGroup === false
                    ? self::GENERAL_EVENT_COLOR
                    : $this->colorPalette->colorFor($firstGroup->getId()->toRfc4122());

                return [
                    'label'   => $item->getStartTime()->format('H:i') . '–' . $item->getEndTime()->format('H:i') . ' ' . $item->getName(),
                    'details' => '',
                    'color'   => $color,
                    'icon'    => 'heroicons:megaphone',
                ];
            },
        );
    }

    /**
     * @return list<Sanction|Absence|SchoolEvent>
     */
    private function getItemsForYear(EducationalCentre $centre, AcademicYear $academicYear): array
    {
        if ($this->itemsCache !== null) {
            return $this->itemsCache;
        }

        $isAdmin = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $user    = $this->getUser();
        $viewer  = $user instanceof Teacher ? $user : null;

        $items = $this->sanctionRepository->findWithDatesForAcademicYear($academicYear);

        if ($isAdmin) {
            $items = [...$items, ...$this->absenceRepository->findWithDatesForAcademicYear($academicYear)];
        }

        if ($isAdmin) {
            $items = [...$items, ...$this->eventRepository->findAllForAcademicYear($academicYear)];
        } elseif ($viewer !== null) {
            $items = [...$items, ...$this->eventRepository->findVisibleForTeacherInAcademicYear($viewer, $academicYear)];
        }

        $this->itemsCache = $items;

        return $items;
    }
}
