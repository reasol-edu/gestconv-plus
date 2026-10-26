<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SchoolEvent;
use App\Entity\Teacher;

/** Una jornada lectiva de la pantalla «Próximos días» del tablón. */
final readonly class BoardUpcomingDay
{
    /**
     * @param list<array{name: string, color: array{hex: string, bg: string, text: string, border: string, style: string}, items: list<array{student: string, details: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable}>}> $groups sanciones que cubren el día, por grupo
     * @param list<Teacher>     $absentTeachers
     * @param list<SchoolEvent> $events
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public array $groups,
        public array $absentTeachers,
        public array $events,
    ) {}
}
