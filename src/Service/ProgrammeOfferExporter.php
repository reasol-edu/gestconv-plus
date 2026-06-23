<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\GroupRepository;
use App\Repository\ProgrammeRepository;
use App\Repository\ProgrammeYearRepository;

class ProgrammeOfferExporter
{
    public function __construct(
        private readonly ProgrammeRepository $programmes,
        private readonly ProgrammeYearRepository $levels,
        private readonly GroupRepository $groups,
    ) {}

    /** @return array<string, mixed> */
    public function export(AcademicYear $year): array
    {
        $data = [
            'exported_at'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'academic_year' => $year->getName(),
            'programmes'    => [],
        ];

        foreach ($this->programmes->findByAcademicYearOrdered($year) as $programme) {
            $programmeData = [
                'name'    => $programme->getName(),
                'details' => $programme->getDetails(),
                'levels'  => [],
            ];

            foreach ($this->levels->findByProgrammeOrderedByName($programme) as $level) {
                $levelData = [
                    'name'    => $level->getName(),
                    'details' => $level->getDetails(),
                    'groups'  => [],
                ];

                foreach ($this->groups->findByLevelOrderedByName($level) as $group) {
                    $teachers = array_map(
                        static fn($t) => $t->getUsername(),
                        $group->getTeachers()->toArray(),
                    );
                    sort($teachers);

                    $tutors = array_map(
                        static fn($t) => $t->getUsername(),
                        $group->getTutors()->toArray(),
                    );
                    sort($tutors);

                    $levelData['groups'][] = [
                        'name'     => $group->getName(),
                        'details'  => $group->getDetails(),
                        'teachers' => $teachers,
                        'tutors'   => $tutors,
                    ];
                }

                $programmeData['levels'][] = $levelData;
            }

            $data['programmes'][] = $programmeData;
        }

        return $data;
    }
}
