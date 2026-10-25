<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\SchoolEvent;
use App\Entity\TimeSlot;

final readonly class BoardTodayTimeSlot
{
    /**
     * @param list<Activity>    $activities
     * @param list<SchoolEvent> $events     eventos del día que coinciden con el tramo
     */
    public function __construct(
        public TimeSlot $timeSlot,
        public array $activities,
        public array $events = [],
    ) {}

    /**
     * Los eventos que caen dentro de un tramo: los que se solapan con él (empiezan antes de que acabe
     * y acaban después de que empiece), aunque no lo abarquen entero.
     *
     * @param list<SchoolEvent> $events
     *
     * @return list<SchoolEvent>
     */
    public static function eventsDuring(TimeSlot $slot, array $events): array
    {
        $slotStart = $slot->getStartTime()->format('H:i:s');
        $slotEnd   = $slot->getEndTime()->format('H:i:s');

        return array_values(array_filter(
            $events,
            static fn (SchoolEvent $event): bool => $event->getStartTime()->format('H:i:s') < $slotEnd
                && $event->getEndTime()->format('H:i:s') > $slotStart,
        ));
    }

    /**
     * Los eventos que no están dentro de ningún tramo, para listarlos aparte sin repetir los que ya se muestran en su tramo.
     *
     * @param list<BoardTodayTimeSlot> $slots
     * @param list<SchoolEvent>        $events
     *
     * @return list<SchoolEvent>
     */
    public static function eventsOutside(array $slots, array $events): array
    {
        $placed = [];
        foreach ($slots as $slot) {
            foreach ($slot->events as $event) {
                $placed[$event->getId()->toRfc4122()] = true;
            }
        }

        return array_values(array_filter(
            $events,
            static fn (SchoolEvent $event): bool => !isset($placed[$event->getId()->toRfc4122()]),
        ));
    }
}
