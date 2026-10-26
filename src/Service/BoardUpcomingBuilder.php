<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\AbsenceRepository;
use App\Repository\SchoolEventRepository;

/**
 * Construye la pantalla «Próximos días» del tablón: las siguientes jornadas lectivas a partir del día lectivo
 * siguiente al actual (se saltan los fines de semana y los días no lectivos), cada una con sus sanciones por
 * grupo, el profesorado ausente y las actividades.
 */
final class BoardUpcomingBuilder
{
    /** Hasta dónde se busca por delante cuando faltan días lectivos (p. ej. las vacaciones de Navidad). */
    private const int SEARCH_LIMIT_DAYS = 60;

    public function __construct(
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly CalendarBoardBuilder $boardBuilder,
        private readonly AbsenceRepository $absences,
        private readonly SchoolEventRepository $events,
        private readonly FeatureToggle $features,
    ) {}

    /**
     * Las próximas $count jornadas lectivas después de $today (que puede no ser lectivo).
     *
     * @return list<\DateTimeImmutable>
     */
    public function upcomingLectiveDays(AcademicYear $year, \DateTimeImmutable $today, int $count = 5): array
    {
        $days = [];
        $day  = $today->setTime(0, 0, 0);

        for ($i = 1; $i <= self::SEARCH_LIMIT_DAYS && count($days) < $count; $i++) {
            $candidate = $day->modify("+{$i} days");
            if (!$this->nonWorkingDayChecker->isNonWorkingDay($year, $candidate)) {
                $days[] = $candidate;
            }
        }

        return $days;
    }

    /**
     * @param list<array{groupId: string, groupName: string, groupColor?: ?string, student: string, details: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable}> $sanctionItems
     *
     * @return list<BoardUpcomingDay>
     */
    public function build(AcademicYear $year, \DateTimeImmutable $today, array $sanctionItems, int $count = 5): array
    {
        $days = $this->upcomingLectiveDays($year, $today, $count);
        if ($days === []) {
            return [];
        }

        $centre        = $year->getEducationalCentre();
        $showAbsences  = $this->features->isEnabledForCentre(Feature::Absences, $centre);
        $eventsByDay   = [];
        if ($this->features->isEnabledForCentre(Feature::Events, $centre)) {
            foreach ($this->events->findAllForAcademicYearBetween($year, $days[0], $days[array_key_last($days)]) as $event) {
                $eventsByDay[$event->getDate()->format('Y-m-d')][] = $event;
            }
        }

        $result = [];
        foreach ($this->boardBuilder->build($sanctionItems, $days) as $entry) {
            $key      = $entry['day']->format('Y-m-d');
            $result[] = new BoardUpcomingDay(
                $entry['day'],
                $entry['groups'],
                $showAbsences ? $this->absences->findTeachersAbsentOn($year, $entry['day']) : [],
                $eventsByDay[$key] ?? [],
            );
        }

        return $result;
    }
}
