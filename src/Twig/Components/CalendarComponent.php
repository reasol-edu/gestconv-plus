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
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\GroupColorPalette;
use App\Service\NonWorkingDayChecker;
use App\Service\TenantContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Calendario mensual unificado: sanciones (todo el profesorado), ausencias
 * previstas (solo administradores de centro) y eventos de centro (según
 * visibilidad — generales para todos, restringidos según grupo impartido o
 * tutorizado), todo en la misma cuadrícula.
 */
#[AsLiveComponent]
class CalendarComponent extends AbstractCalendarComponent
{
    /** Colores base de lo que no pertenece a un grupo (ámbar para las ausencias, azul cielo para los eventos generales). */
    private const string ABSENCE_COLOR       = '#f59e0b';
    private const string GENERAL_EVENT_COLOR = '#0ea5e9';

    /** Tipos de contenido que se pueden mostrar u ocultar con los botones del filtro. */
    private const array TYPES = ['sanction', 'absence', 'event'];

    /**
     * Tipos que la persona ha ocultado con los botones del filtro.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $hiddenTypes = [];

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
        private readonly FeatureToggle $features,
    ) {
        parent::__construct($tenantContext, $translator, $nonWorkingDayChecker, $clock);
    }

    #[LiveAction]
    public function toggleType(#[LiveArg] string $type): void
    {
        foreach ($this->getFilterTypes() as $button) {
            if ($button['key'] !== $type || $button['locked']) {
                continue;
            }

            $this->hiddenTypes = $button['visible']
                ? [...$this->hiddenTypes, $type]
                : array_values(array_diff($this->hiddenTypes, [$type]));

            return;
        }
    }

    /**
     * Botones del filtro: un tipo por cada clase de contenido que tiene algo en el curso y que
     * la persona puede ver. El último tipo visible queda bloqueado (deshabilitado) para que el
     * filtro nunca deje el calendario vacío; con un solo tipo, su único botón está bloqueado.
     *
     * @return list<array{key: string, visible: bool, locked: bool}>
     */
    public function getFilterTypes(): array
    {
        $centre       = $this->getTenantContext()->getSelectedCentre();
        $academicYear = $centre !== null ? $this->getTenantContext()->getViewYear($centre) : null;
        if ($centre === null || $academicYear === null) {
            return [];
        }

        $present = [];
        foreach ($this->getItemsForYear($centre, $academicYear) as $item) {
            $present[self::typeOf($item)] = true;
        }

        $keys = array_values(array_filter(self::TYPES, static fn (string $type): bool => isset($present[$type])));

        // Si lo guardado ocultara todo lo que hay (p. ej. desapareció el contenido de otro tipo), no se oculta nada.
        $hidden = array_diff($keys, $this->hiddenTypes) === [] ? [] : $this->hiddenTypes;
        $shown  = array_values(array_diff($keys, $hidden));

        return array_map(
            static fn (string $type): array => [
                'key'     => $type,
                'visible' => !in_array($type, $hidden, true),
                'locked'  => $shown === [$type],
            ],
            $keys,
        );
    }

    private static function typeOf(Sanction|Absence|SchoolEvent $item): string
    {
        return match (true) {
            $item instanceof Sanction => 'sanction',
            $item instanceof Absence  => 'absence',
            default                   => 'event',
        };
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

        $hidden = [];
        foreach ($this->getFilterTypes() as $button) {
            if (!$button['visible']) {
                $hidden[] = $button['key'];
            }
        }
        $items = array_values(array_filter(
            $this->getItemsForYear($centre, $academicYear),
            static fn (Sanction|Absence|SchoolEvent $item): bool => !in_array(self::typeOf($item), $hidden, true),
        ));

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
                        'color'   => $this->colorPalette->forGroup($group),
                        'icon'    => 'heroicons:exclamation-triangle',
                    ];
                }
                if ($item instanceof Absence) {
                    return [
                        'label'   => $item->getTeacher()->getName()->full(),
                        'details' => '',
                        'color'   => $this->colorPalette->fromHex(self::ABSENCE_COLOR),
                        'icon'    => 'heroicons:user-circle',
                    ];
                }

                $firstGroup = $item->getGroups()->first();
                $color      = $item->isGeneral() || $firstGroup === false
                    ? $this->colorPalette->fromHex(self::GENERAL_EVENT_COLOR)
                    : $this->colorPalette->forGroup($firstGroup);

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

        if (!$this->features->isEnabledForCentre(Feature::Events, $centre)) {
            // Actividades desactivadas: el calendario solo muestra sanciones y ausencias.
        } elseif ($this->isGranted(EducationalCentreVoter::MANAGE_ALL_EVENTS, $centre)) {
            $items = [...$items, ...$this->eventRepository->findAllForAcademicYear($academicYear)];
        } elseif ($viewer !== null) {
            $items = [...$items, ...$this->eventRepository->findVisibleForTeacherInAcademicYear($viewer, $academicYear)];
        }

        $this->itemsCache = $items;

        return $items;
    }
}
