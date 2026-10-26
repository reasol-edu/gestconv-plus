<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Sanction;
use App\Repository\AbsenceRepository;
use App\Repository\ActivityRepository;
use App\Repository\SanctionRepository;
use App\Repository\SchoolEventRepository;
use App\Repository\TimeSlotRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the "Hoy" board screen: every time slot of the given weekday with
 * its guard teachers and the activities registered for that day, plus the
 * list of teachers absent that day, the students sanctioned that day, and
 * the school events of that day (shown even on non-working days, unlike the
 * rest of the screen — an event like an open house can fall on a holiday).
 */
class BoardTodayBuilder
{
    private const int SANCTION_LABEL_LIMIT = 70;

    public function __construct(
        private readonly TimeSlotRepository $timeSlots,
        private readonly ActivityRepository $activities,
        private readonly AbsenceRepository $absences,
        private readonly SanctionRepository $sanctions,
        private readonly SchoolEventRepository $events,
        private readonly FeatureToggle $features,
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @param int|null $eventsDaysAhead si se indica, el informe trae también las actividades de hoy y de los
     *                                  próximos días (panel de actividades del tablón)
     */
    public function build(AcademicYear $year, \DateTimeImmutable $date, ?int $eventsDaysAhead = null): BoardTodayReport
    {
        $eventsEnabled = $this->features->isEnabledForCentre(Feature::Events, $year->getEducationalCentre());
        $events        = $eventsEnabled ? $this->events->findAllForAcademicYearAndDate($year, $date) : [];
        $upcoming      = $eventsEnabled && $eventsDaysAhead !== null
            ? $this->events->findAllForAcademicYearBetween($year, $date, $date->modify('+' . max(0, $eventsDaysAhead) . ' days'))
            : [];

        if ($this->nonWorkingDayChecker->isNonWorkingDay($year, $date)) {
            return new BoardTodayReport($date, [], [], [], $this->nonWorkingDayLabel($year, $date), $events, $upcoming);
        }

        $dayOfWeek = ((int) $date->format('N')) - 1;

        $slots = $this->timeSlots->findByAcademicYearAndDay($year, $dayOfWeek);

        /** @var array<string, list<\App\Entity\Activity>> $activitiesBySlot */
        $activitiesBySlot = [];
        foreach ($this->activities->findByAcademicYearAndDate($year, $date) as $activity) {
            $activitiesBySlot[$activity->getTimeSlot()->getId()->toRfc4122()][] = $activity;
        }

        $substituted = $this->absences->findSubstitutedTeacherIdsOn($year, $date);
        $timeSlots   = [];
        foreach ($slots as $slot) {
            $timeSlots[] = new BoardTodayTimeSlot(
                $slot,
                $activitiesBySlot[$slot->getId()->toRfc4122()] ?? [],
                BoardTodayTimeSlot::eventsDuring($slot, $events),
                BoardTodayTimeSlot::guardsOn($slot, $substituted),
            );
        }

        $sanctionedStudents = array_map(
            fn (Sanction $sanction): BoardTodaySanction => new BoardTodaySanction(
                $sanction->getStudent(),
                $sanction->getGroup(),
                $sanction->getCalendarLabel() ?? $this->truncate(trim(strip_tags($sanction->getDetails()))),
                $sanction->getEffectiveFrom(),
                $sanction->getEffectiveTo(),
            ),
            $this->sanctions->findActiveOn($year, $date),
        );

        return new BoardTodayReport(
            $date,
            $timeSlots,
            $this->absences->findTeachersAbsentOn($year, $date),
            $sanctionedStudents,
            events: $events,
            upcomingEvents: $upcoming,
        );
    }

    private function nonWorkingDayLabel(AcademicYear $year, \DateTimeImmutable $date): string
    {
        $description = $this->nonWorkingDayChecker->descriptionFor($year, $date);
        if ($description !== null) {
            return $description;
        }

        return $this->nonWorkingDayChecker->isWeekend($date)
            ? $this->translator->trans('board_non_working_weekend', [], 'calendar')
            : $this->translator->trans('board_day_non_working', [], 'calendar');
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::SANCTION_LABEL_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::SANCTION_LABEL_LIMIT - 1) . '…';
    }
}
