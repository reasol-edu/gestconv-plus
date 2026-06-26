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
            if (!is_array($progData)) {
                continue;
            }
            $nameRaw  = $progData['name'] ?? null;
            $progName = is_string($nameRaw) ? trim($nameRaw) : '';
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

            $detailsRaw = $progData['details'] ?? null;
            $details    = is_string($detailsRaw) ? $detailsRaw : null;
            $programme->setDetails($details !== null && $details !== '' ? $details : null);

            $existingLevels = [];
            foreach ($this->levels->findByProgrammeOrderedByName($programme) as $l) {
                $existingLevels[mb_strtolower($l->getName())] = $l;
            }

            foreach ((array) ($progData['levels'] ?? []) as $levelData) {
                if (!is_array($levelData)) {
                    continue;
                }
                $levelNameRaw = $levelData['name'] ?? null;
                $levelName    = is_string($levelNameRaw) ? trim($levelNameRaw) : '';
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

                $levelDetailsRaw = $levelData['details'] ?? null;
                $levelDetails    = is_string($levelDetailsRaw) ? $levelDetailsRaw : null;
                $level->setDetails($levelDetails !== null && $levelDetails !== '' ? $levelDetails : null);

                $existingGroups = [];
                foreach ($this->groups->findByLevelOrderedByName($level) as $g) {
                    $existingGroups[mb_strtolower($g->getName())] = $g;
                }

                foreach ((array) ($levelData['groups'] ?? []) as $groupData) {
                    if (!is_array($groupData)) {
                        continue;
                    }
                    $groupNameRaw = $groupData['name'] ?? null;
                    $groupName    = is_string($groupNameRaw) ? trim($groupNameRaw) : '';
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

                    $groupDetailsRaw = $groupData['details'] ?? null;
                    $groupDetails    = is_string($groupDetailsRaw) ? $groupDetailsRaw : null;
                    $group->setDetails($groupDetails !== null && $groupDetails !== '' ? $groupDetails : null);

                    if ($options->importTeachers) {
                        foreach ($group->getTeachers()->toArray() as $t) {
                            $group->removeTeacher($t);
                        }
                        $rawTeachers = $groupData['teachers'] ?? [];
                        foreach ($this->uniqueUsernames(is_array($rawTeachers) ? $rawTeachers : []) as $username) {
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
                        $rawTutors = $groupData['tutors'] ?? [];
                        foreach ($this->uniqueUsernames(is_array($rawTutors) ? $rawTutors : []) as $username) {
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
            $username = is_string($u) ? trim($u) : '';
            if ($username !== '' && !isset($seen[$username])) {
                $seen[$username] = true;
                $result[]        = $username;
            }
        }

        return $result;
    }
}
