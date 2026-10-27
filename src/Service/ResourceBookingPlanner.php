<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Repository\ResourceBookingRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reglas de las reservas: qué fechas admite un recurso (no lectivas, pasadas, demasiado lejanas, ocupadas), el
 * estado con el que nace una reserva y las fechas de una periodicidad semanal.
 */
final class ResourceBookingPlanner
{
    /** Como mucho una periodicidad semanal de algo más de un año. */
    public const MAX_OCCURRENCES = 60;

    public function __construct(
        private readonly ResourceBookingRepository $bookings,
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly ResourceBookingAccess $access,
        private readonly AppSettingsInterface $settings,
        private readonly ClockInterface $clock,
    ) {}

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0, 0);
    }

    /**
     * Las fechas de una periodicidad semanal: desde `$start` cada 7 días hasta `$until` (incluida).
     *
     * @return list<\DateTimeImmutable>
     */
    public function weeklyDates(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $dates = [];
        for ($cursor = $start; $cursor <= $until && count($dates) < self::MAX_OCCURRENCES; $cursor = $cursor->modify('+7 days')) {
            $dates[] = $cursor;
        }

        return $dates;
    }

    /** Días máximos de antelación para reservar el recurso (0 = sin límite): el de su categoría o, si no tiene, el del centro. */
    public function maxAdvanceDays(SharedResource $resource): int
    {
        $category = $resource->getCategory();
        $own      = $category->getMaxAdvanceDays();
        if ($own !== null) {
            return $own;
        }

        $centreValue = $this->settings->getForCentre('bookings.max_advance_days', $category->getEducationalCentre());

        return is_int($centreValue) ? $centreValue : 0;
    }

    /**
     * Estado con el que nace una reserva de `$actor`: aprobada, salvo que la categoría exija validación y quien
     * reserva no la gestione.
     */
    public function initialStatus(SharedResource $resource, Teacher $actor): ResourceBookingStatus
    {
        $category = $resource->getCategory();

        return $category->requiresApproval() && !$this->access->managesCategory($actor, $category)
            ? ResourceBookingStatus::Pending
            : ResourceBookingStatus::Approved;
    }

    /**
     * Comprueba cada fecha. `$actor` es quien hace la reserva (no su titular): la dirección y los responsables
     * pueden reservar en el pasado y sin límite de antelación. `$ignore` es la reserva que se está editando.
     *
     * @param list<TimeSlot>             $slots
     * @param list<\DateTimeImmutable>   $dates
     * @return list<BookingDateCheck>
     */
    public function check(AcademicYear $year, SharedResource $resource, Teacher $actor, array $slots, array $dates, ?ResourceBooking $ignore = null): array
    {
        if ($dates === []) {
            return [];
        }

        $unlimited = $this->access->managesCategory($actor, $resource->getCategory());
        $today     = $this->today();
        $limit     = $this->maxAdvanceDays($resource);
        $lastDay   = $limit > 0 ? $today->modify('+' . $limit . ' days') : null;

        $slotIds = [];
        foreach ($slots as $slot) {
            $slotIds[$slot->getId()->toRfc4122()] = true;
        }

        $occupied = $this->bookings->findOccupyingBetween($resource, min($dates), max($dates));

        $checks = [];
        foreach ($dates as $date) {
            $checks[] = $this->checkDate($year, $date, $slots, $slotIds, $occupied, $ignore, $unlimited, $today, $lastDay);
        }

        return $checks;
    }

    /**
     * @param list<TimeSlot>           $slots
     * @param array<string, true>      $slotIds
     * @param list<ResourceBooking>    $occupied
     */
    private function checkDate(
        AcademicYear $year,
        \DateTimeImmutable $date,
        array $slots,
        array $slotIds,
        array $occupied,
        ?ResourceBooking $ignore,
        bool $unlimited,
        \DateTimeImmutable $today,
        ?\DateTimeImmutable $lastDay,
    ): BookingDateCheck {
        if ($this->nonWorkingDays->isNonWorkingDay($year, $date)) {
            return new BookingDateCheck($date, BookingDateCheck::NON_WORKING);
        }

        // Los tramos son de un día de la semana: una fecha solo admite los de su día.
        $weekday = (int) $date->format('N') - 1;
        foreach ($slots as $slot) {
            if ($slot->getDayOfWeek() !== $weekday) {
                return new BookingDateCheck($date, BookingDateCheck::NO_SLOT);
            }
        }

        if (!$unlimited) {
            if ($date < $today) {
                return new BookingDateCheck($date, BookingDateCheck::PAST);
            }
            if ($lastDay !== null && $date > $lastDay) {
                return new BookingDateCheck($date, BookingDateCheck::TOO_FAR);
            }
        }

        foreach ($occupied as $booking) {
            if ($booking->getDate()->format('Y-m-d') !== $date->format('Y-m-d')) {
                continue;
            }
            if ($ignore !== null && $booking->getId()->equals($ignore->getId())) {
                continue;
            }
            foreach ($booking->getTimeSlots() as $bookedSlot) {
                if (isset($slotIds[$bookedSlot->getId()->toRfc4122()])) {
                    return new BookingDateCheck($date, BookingDateCheck::OCCUPIED, $booking);
                }
            }
        }

        return new BookingDateCheck($date);
    }
}
