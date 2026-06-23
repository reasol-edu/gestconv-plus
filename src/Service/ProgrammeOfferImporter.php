<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Group;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Repository\GroupRepository;
use App\Repository\ProgrammeRepository;
use App\Repository\ProgrammeYearRepository;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProgrammeOfferImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProgrammeRepository $programmes,
        private readonly ProgrammeYearRepository $levels,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{programmes: int, levels: int, groups: int, missing_teachers: list<string>}
     */
    public function import(array $data, AcademicYear $year, ImportOptions $options): array
    {
        $stats = [
            'programmes'       => 0,
            'levels'           => 0,
            'groups'           => 0,
            'missing_teachers' => [],
        ];

        $existingProgrammes = [];
        foreach ($this->programmes->findByAcademicYearOrdered($year) as $p) {
            $existingProgrammes[mb_strtolower($p->getName())] = $p;
        }

        foreach ((array) ($data['programmes'] ?? []) as $progData) {
            $progName = trim((string) ($progData['name'] ?? ''));
            if ($progName === '') {
                continue;
            }

            $programme = $existingProgrammes[mb_strtolower($progName)] ?? null;
            if ($programme === null) {
                $programme = (new Programme())
                    ->setName($progName)
                    ->setAcademicYear($year);
                $this->em->persist($programme);
                $existingProgrammes[mb_strtolower($progName)] = $programme;
                $stats['programmes']++;
            }

            $details = $progData['details'] ?? null;
            $programme->setDetails($details !== null && $details !== '' ? (string) $details : null);

            $existingLevels = [];
            foreach ($this->levels->findByProgrammeOrderedByName($programme) as $l) {
                $existingLevels[mb_strtolower($l->getName())] = $l;
            }

            foreach ((array) ($progData['levels'] ?? []) as $levelData) {
                $levelName = trim((string) ($levelData['name'] ?? ''));
                if ($levelName === '') {
                    continue;
                }

                $level = $existingLevels[mb_strtolower($levelName)] ?? null;
                if ($level === null) {
                    $level = (new ProgrammeYear())
                        ->setName($levelName)
                        ->setProgramme($programme);
                    $this->em->persist($level);
                    $existingLevels[mb_strtolower($levelName)] = $level;
                    $stats['levels']++;
                }

                $levelDetails = $levelData['details'] ?? null;
                $level->setDetails($levelDetails !== null && $levelDetails !== '' ? (string) $levelDetails : null);

                $existingGroups = [];
                foreach ($this->groups->findByLevelOrderedByName($level) as $g) {
                    $existingGroups[mb_strtolower($g->getName())] = $g;
                }

                foreach ((array) ($levelData['groups'] ?? []) as $groupData) {
                    $groupName = trim((string) ($groupData['name'] ?? ''));
                    if ($groupName === '') {
                        continue;
                    }

                    $group = $existingGroups[mb_strtolower($groupName)] ?? null;
                    if ($group === null) {
                        $group = (new Group())
                            ->setName($groupName)
                            ->setProgrammeYear($level);
                        $this->em->persist($group);
                        $existingGroups[mb_strtolower($groupName)] = $group;
                        $stats['groups']++;
                    }

                    $groupDetails = $groupData['details'] ?? null;
                    $group->setDetails($groupDetails !== null && $groupDetails !== '' ? (string) $groupDetails : null);

                    if ($options->importTeachers) {
                        foreach ($group->getTeachers()->toArray() as $t) {
                            $group->removeTeacher($t);
                        }
                        foreach ($this->uniqueUsernames((array) ($groupData['teachers'] ?? [])) as $username) {
                            $teacher = $this->teachers->findByUsername($username);
                            if ($teacher === null) {
                                $stats['missing_teachers'][] = $username;
                            } else {
                                $group->addTeacher($teacher);
                            }
                        }
                    }

                    if ($options->importTutors) {
                        foreach ($group->getTutors()->toArray() as $t) {
                            $group->removeTutor($t);
                        }
                        foreach ($this->uniqueUsernames((array) ($groupData['tutors'] ?? [])) as $username) {
                            $teacher = $this->teachers->findByUsername($username);
                            if ($teacher === null) {
                                $stats['missing_teachers'][] = $username;
                            } else {
                                $group->addTutor($teacher);
                            }
                        }
                    }
                }
            }
        }

        $this->em->flush();

        /** @var list<string> $missing */
        $missing = array_values(array_unique($stats['missing_teachers']));
        sort($missing);
        $stats['missing_teachers'] = $missing;

        return $stats;
    }

    /**
     * @param array<mixed> $raw
     * @return list<string>
     */
    private function uniqueUsernames(array $raw): array
    {
        $seen   = [];
        $result = [];
        foreach ($raw as $u) {
            $username = trim((string) $u);
            if ($username !== '' && !isset($seen[$username])) {
                $seen[$username] = true;
                $result[]        = $username;
            }
        }

        return $result;
    }
}
