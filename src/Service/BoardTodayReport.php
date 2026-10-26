<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;

final readonly class BoardTodayReport
{
    /**
     * @param list<BoardTodayTimeSlot>  $timeSlots
     * @param list<Teacher>             $absentTeachers
     * @param list<BoardTodaySanction>  $sanctionedStudents
     * @param list<SchoolEvent>         $events         actividades del día
     * @param list<SchoolEvent>         $upcomingEvents actividades de hoy y de los próximos días (panel del tablón)
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $timeSlots,
        public array $absentTeachers,
        public array $sanctionedStudents,
        public ?string $nonWorkingDayLabel = null,
        public array $events = [],
        public array $upcomingEvents = [],
    ) {}

    /**
     * Actividades del día que no caen dentro de ningún tramo horario (las demás se muestran en su tramo).
     *
     * @return list<SchoolEvent>
     */
    public function eventsOutsideSlots(): array
    {
        return BoardTodayTimeSlot::eventsOutside($this->timeSlots, $this->events);
    }

    /**
     * El profesorado ausente por tramos. Quien dejó tareas sale en los tramos donde las dejó; quien no dejó ninguna
     * en todo el día sale en todos los tramos de la jornada. Los tramos sin ningún ausente ni profesorado de
     * guardia se omiten.
     *
     * @return list<BoardAbsenceSlot>
     */
    public function absenceSlots(): array
    {
        $teachersWithTasks = [];
        foreach ($this->timeSlots as $entry) {
            foreach ($entry->activities as $activity) {
                $teachersWithTasks[$activity->getAbsence()->getTeacher()->getId()->toRfc4122()] = true;
            }
        }

        $withoutTasks = array_values(array_filter(
            $this->absentTeachers,
            static fn (Teacher $teacher): bool => !isset($teachersWithTasks[$teacher->getId()->toRfc4122()]),
        ));

        $slots = [];
        foreach ($this->timeSlots as $entry) {
            $rows = array_map(
                static fn (Activity $activity): BoardAbsenceRow => new BoardAbsenceRow($activity->getAbsence()->getTeacher(), $activity),
                $entry->activities,
            );
            foreach ($withoutTasks as $teacher) {
                $rows[] = new BoardAbsenceRow($teacher, null);
            }

            if ($rows !== [] || $entry->guards !== []) {
                $slots[] = new BoardAbsenceSlot($entry->timeSlot, $rows, $entry->guards);
            }
        }

        return $slots;
    }
}
