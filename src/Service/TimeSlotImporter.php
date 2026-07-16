<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\TimeSlot;
use App\Repository\TimeSlotRepository;
use Doctrine\ORM\EntityManagerInterface;

class TimeSlotImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeSlotRepository $timeSlots,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{time_slots: int}
     */
    public function import(array $data, AcademicYear $year, bool $replaceExisting = false): array
    {
        $stats = ['time_slots' => 0];

        if ($replaceExisting) {
            foreach ($this->timeSlots->findByAcademicYearOrdered($year) as $existing) {
                $this->em->remove($existing);
            }
            $this->em->flush();
        }

        $existingSignatures = [];
        foreach ($this->timeSlots->findByAcademicYearOrdered($year) as $existing) {
            $existingSignatures[$this->signature(
                $existing->getName(),
                $existing->getDayOfWeek(),
                $existing->getStartTime()->format('H:i'),
                $existing->getEndTime()->format('H:i'),
            )] = true;
        }

        foreach ((array) ($data['time_slots'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $nameRaw = $row['name'] ?? null;
            $name    = is_string($nameRaw) ? trim($nameRaw) : '';
            if ($name === '') {
                continue;
            }

            $dayOfWeekRaw = $row['day_of_week'] ?? null;
            if (!is_int($dayOfWeekRaw) && !(is_string($dayOfWeekRaw) && ctype_digit($dayOfWeekRaw))) {
                continue;
            }
            $dayOfWeek = (int) $dayOfWeekRaw;
            if ($dayOfWeek < 0 || $dayOfWeek > 6) {
                continue;
            }

            $startTime = $this->parseTime($row['start_time'] ?? null);
            $endTime   = $this->parseTime($row['end_time'] ?? null);
            if ($startTime === null || $endTime === null || $startTime >= $endTime) {
                continue;
            }

            $signature = $this->signature($name, $dayOfWeek, $startTime->format('H:i'), $endTime->format('H:i'));
            if (isset($existingSignatures[$signature])) {
                continue;
            }
            $existingSignatures[$signature] = true;

            $timeSlot = (new TimeSlot())
                ->setAcademicYear($year)
                ->setName($name)
                ->setDayOfWeek($dayOfWeek)
                ->setStartTime($startTime)
                ->setEndTime($endTime);
            $this->em->persist($timeSlot);
            $stats['time_slots']++;
        }

        $this->em->flush();

        return $stats;
    }

    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('H:i', $value);

        return $time === false ? null : $time;
    }

    private function signature(string $name, int $dayOfWeek, string $startTime, string $endTime): string
    {
        return mb_strtolower($name) . '|' . $dayOfWeek . '|' . $startTime . '|' . $endTime;
    }
}
