<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\ResourceBooking;
use App\Entity\TimeSlot;
use App\Repository\ResourceBookingRepository;
use App\Repository\TimeSlotRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Prepara los datos del calendario de reservas: la vista semanal (tramos en filas, días en columnas) y la mensual
 * (una celda por día con sus reservas). Ambas leen las reservas con los filtros del usuario, sin las rechazadas.
 *
 * @phpstan-type Day array{date: \DateTimeImmutable, isToday: bool, nonWorking: bool, nonWorkingLabel: ?string}
 */
final class ResourceBookingCalendarBuilder
{
    /** Reservas que caben en una celda del mes antes de resumir el resto en «+N más». */
    public const MONTH_CELL_LIMIT = 3;

    public function __construct(
        private readonly ResourceBookingRepository $bookings,
        private readonly TimeSlotRepository $timeSlots,
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly ClockInterface $clock,
    ) {}

    /** El lunes de la semana de la fecha. */
    public function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->setTime(0, 0, 0);
    }

    /**
     * @return array{
     *     from: \DateTimeImmutable,
     *     to: \DateTimeImmutable,
     *     days: list<Day>,
     *     rows: list<array{label: string, start: \DateTimeImmutable, end: \DateTimeImmutable, cells: array<string, list<ResourceBooking>>}>,
     *     total: int
     * }
     */
    public function week(AcademicYear $year, \DateTimeImmutable $anchor, ResourceBookingFilters $filters): array
    {
        $monday = $this->mondayOf($anchor);
        $sunday = $monday->modify('+6 days');
        $found  = $this->bookings->findForCalendar($year, $monday, $sunday, $filters);

        // Sábado y domingo solo se muestran si hay algo en ellos (tramos o reservas).
        $lastDay = 4;
        $slots   = $this->timeSlots->findByAcademicYearOrdered($year);
        foreach ($slots as $slot) {
            $lastDay = max($lastDay, $slot->getDayOfWeek());
        }
        foreach ($found as $booking) {
            $lastDay = max($lastDay, (int) $booking->getDate()->format('N') - 1);
        }

        $days = [];
        for ($i = 0; $i <= $lastDay; ++$i) {
            $days[] = $this->day($year, $monday->modify('+' . $i . ' days'));
        }

        // Los tramos no tienen por qué ser iguales todos los días: una fila por franja horaria distinta.
        /** @var array<string, TimeSlot> $firstSlot la primera franja vista de cada horario (da nombre a la fila) */
        $firstSlot = [];
        /** @var array<string, string> $slotRow id del tramo => clave de su fila */
        $slotRow = [];
        foreach ($slots as $slot) {
            $key = $slot->getStartTime()->format('H:i') . '-' . $slot->getEndTime()->format('H:i');
            $firstSlot[$key] ??= $slot;
            $slotRow[$slot->getId()->toRfc4122()] = $key;
        }
        ksort($firstSlot);

        /** @var array<string, array<string, list<ResourceBooking>>> $cells */
        $cells = [];
        foreach ($found as $booking) {
            $date = $booking->getDate()->format('Y-m-d');
            foreach ($booking->getTimeSlots() as $slot) {
                $key = $slotRow[$slot->getId()->toRfc4122()] ?? null;
                if ($key !== null) {
                    $cells[$key][$date][] = $booking;
                }
            }
        }

        $rows = [];
        foreach ($firstSlot as $key => $slot) {
            $rows[] = ['label' => $slot->getName(), 'start' => $slot->getStartTime(), 'end' => $slot->getEndTime(), 'cells' => $cells[$key] ?? []];
        }

        return [
            'from'  => $monday,
            'to'    => $sunday,
            'days'  => $days,
            'rows'  => $rows,
            'total' => count($found),
        ];
    }

    /**
     * @return array{
     *     first: \DateTimeImmutable,
     *     weeks: list<list<array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, nonWorking: bool, nonWorkingLabel: ?string, bookings: list<ResourceBooking>, hidden: int}>>,
     *     total: int
     * }
     */
    public function month(AcademicYear $year, \DateTimeImmutable $anchor, ResourceBookingFilters $filters): array
    {
        $first   = $anchor->modify('first day of this month')->setTime(0, 0, 0);
        $last    = $first->modify('last day of this month');
        $from    = $this->mondayOf($first);
        $to      = $this->mondayOf($last)->modify('+6 days');
        $found   = $this->bookings->findForCalendar($year, $from, $to, $filters);

        $byDate = [];
        foreach ($found as $booking) {
            $byDate[$booking->getDate()->format('Y-m-d')][] = $booking;
        }
        foreach ($byDate as $date => $list) {
            usort($list, fn (ResourceBooking $a, ResourceBooking $b): int => $this->startOf($a) <=> $this->startOf($b));
            $byDate[$date] = $list;
        }

        $weeks = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+7 days')) {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                $date   = $cursor->modify('+' . $i . ' days');
                $list   = $byDate[$date->format('Y-m-d')] ?? [];
                $week[] = $this->day($year, $date) + [
                    'inMonth'  => $date->format('Y-m') === $first->format('Y-m'),
                    'bookings' => array_slice($list, 0, self::MONTH_CELL_LIMIT),
                    'hidden'   => max(0, count($list) - self::MONTH_CELL_LIMIT),
                ];
            }
            $weeks[] = $week;
        }

        return ['first' => $first, 'weeks' => $weeks, 'total' => count($found)];
    }

    /**
     * @return Day
     */
    private function day(AcademicYear $year, \DateTimeImmutable $date): array
    {
        $nonWorking = $this->nonWorkingDays->isNonWorkingDay($year, $date);

        return [
            'date'            => $date,
            'isToday'         => $date->format('Y-m-d') === $this->clock->now()->format('Y-m-d'),
            'nonWorking'      => $nonWorking,
            'nonWorkingLabel' => $nonWorking ? $this->nonWorkingDays->descriptionFor($year, $date) : null,
        ];
    }

    /** Hora de inicio de la primera franja de la reserva (para ordenar las de un día). */
    private function startOf(ResourceBooking $booking): string
    {
        $first = $booking->getTimeSlotsOrdered()[0] ?? null;

        return $first instanceof TimeSlot ? $first->getStartTime()->format('H:i') : '99:99';
    }
}
