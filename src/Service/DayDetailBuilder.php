<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\AbsenceRepository;
use App\Repository\ActivityRepository;
use App\Repository\SanctionRepository;
use App\Repository\SchoolEventRepository;
use App\Repository\TimeSlotRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the detail view of a single day, reached by clicking a day in the
 * calendar: time slots with guard duty and activities, active sanctions,
 * absences (admin only) and school events (per visibility). Deliberately
 * independent from BoardTodayBuilder (used only by the kiosk "Hoy" screen):
 * unlike the board, this never short-circuits on non-working days — a day a
 * teacher navigates to directly should always show its full detail, with the
 * non-working label as an extra notice rather than a replacement.
 */
class DayDetailBuilder
{
    private const int SANCTION_LABEL_LIMIT = 70;

    public function __construct(
        private readonly TimeSlotRepository $timeSlots,
        private readonly ActivityRepository $activities,
        private readonly AbsenceRepository $absences,
        private readonly SanctionRepository $sanctions,
        private readonly SchoolEventRepository $events,
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly TranslatorInterface $translator,
    ) {}

    public function build(AcademicYear $year, ?Teacher $viewer, bool $isAdmin, \DateTimeImmutable $date): DayDetailReport
    {
        $dayOfWeek = ((int) $date->format('N')) - 1;

        $slots = $this->timeSlots->findByAcademicYearAndDay($year, $dayOfWeek);

        /** @var array<string, list<\App\Entity\Activity>> $activitiesBySlot */
        $activitiesBySlot = [];
        foreach ($this->activities->findByAcademicYearAndDate($year, $date) as $activity) {
            $activitiesBySlot[$activity->getTimeSlot()->getId()->toRfc4122()][] = $activity;
        }

        $timeSlots = [];
        foreach ($slots as $slot) {
            $timeSlots[] = new BoardTodayTimeSlot(
                $slot,
                $activitiesBySlot[$slot->getId()->toRfc4122()] ?? [],
            );
        }

        $sanctionedStudents = array_map(
            fn (Sanction $sanction): BoardTodaySanction => new BoardTodaySanction(
                $sanction->getStudent(),
                $sanction->getGroup(),
                $sanction->getCalendarLabel() ?? $this->truncate(trim(strip_tags($sanction->getDetails()))),
            ),
            $this->sanctions->findActiveOn($year, $date),
        );

        $absentTeachers = $isAdmin ? $this->absences->findTeachersAbsentOn($year, $date) : [];

        if ($isAdmin) {
            $events = $this->events->findAllForAcademicYearAndDate($year, $date);
        } elseif ($viewer !== null) {
            $events = $this->events->findVisibleForTeacherAndDate($viewer, $year, $date);
        } else {
            $events = [];
        }

        return new DayDetailReport(
            $date,
            $timeSlots,
            $sanctionedStudents,
            $absentTeachers,
            $events,
            $this->nonWorkingDayChecker->isNonWorkingDay($year, $date) ? $this->nonWorkingDayLabel($year, $date) : null,
        );
    }

    private function nonWorkingDayLabel(AcademicYear $year, \DateTimeImmutable $date): string
    {
        $description = $this->nonWorkingDayChecker->descriptionFor($year, $date);
        if ($description !== null) {
            return $description;
        }

        return $this->nonWorkingDayChecker->isWeekend($date)
            ? $this->translator->trans('day.non_working_weekend', [], 'calendar')
            : $this->translator->trans('day.non_working', [], 'calendar');
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::SANCTION_LABEL_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::SANCTION_LABEL_LIMIT - 1) . '…';
    }
}
