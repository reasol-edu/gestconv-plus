<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AcademicYear;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\ProgrammeRepository;
use App\Repository\ProgrammeYearRepository;
use App\Service\ProgrammeOfferExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ProgrammeOfferExporterTest extends TestCase
{
    private ProgrammeRepository&Stub $programmeRepo;
    private ProgrammeYearRepository&Stub $levelRepo;
    private GroupRepository&Stub $groupRepo;
    private ProgrammeOfferExporter $exporter;
    private AcademicYear $year;

    protected function setUp(): void
    {
        $this->programmeRepo = $this->createStub(ProgrammeRepository::class);
        $this->levelRepo     = $this->createStub(ProgrammeYearRepository::class);
        $this->groupRepo     = $this->createStub(GroupRepository::class);

        $this->exporter = new ProgrammeOfferExporter(
            $this->programmeRepo,
            $this->levelRepo,
            $this->groupRepo,
        );

        $this->year = new AcademicYear();
        $this->year->setName('2024-2025');
        $this->setId($this->year);
    }

    public function testExportContainsAcademicYearName(): void
    {
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([]);

        $data = $this->exporter->export($this->year);

        self::assertSame('2024-2025', $data['academic_year']);
    }

    public function testExportContainsExportedAtTimestamp(): void
    {
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([]);

        $data = $this->exporter->export($this->year);

        self::assertArrayHasKey('exported_at', $data);
        self::assertNotEmpty($data['exported_at']);
    }

    public function testExportReturnsEmptyProgrammesWhenNoneExist(): void
    {
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([]);

        $data = $this->exporter->export($this->year);

        self::assertSame([], $data['programmes']);
    }

    public function testExportIncludesProgrammeWithDetails(): void
    {
        $programme = $this->makeProgramme('DAW', 'Desarrollo de aplicaciones web');
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([$programme]);
        $this->levelRepo->method('findByProgrammeOrderedByName')->willReturn([]);

        $data = $this->exporter->export($this->year);

        $prog = $data['programmes'][0];
        self::assertSame('DAW', $prog['name']);
        self::assertSame('Desarrollo de aplicaciones web', $prog['details']);
    }

    public function testExportIncludesLevelAndGroup(): void
    {
        $programme = $this->makeProgramme('DAW', null);
        $level     = $this->makeLevel('1º DAW', null, $programme);
        $group     = $this->makeGroup('DAW1A', null, $level, [], []);
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([$programme]);
        $this->levelRepo->method('findByProgrammeOrderedByName')->willReturn([$level]);
        $this->groupRepo->method('findByLevelOrderedByName')->willReturn([$group]);

        $data = $this->exporter->export($this->year);

        $lvl = $data['programmes'][0]['levels'][0];
        self::assertSame('1º DAW', $lvl['name']);
        self::assertSame('DAW1A', $lvl['groups'][0]['name']);
    }

    public function testExportIncludesGroupTeacherAndTutorUsernames(): void
    {
        $t1    = $this->makeTeacher('teacher.one');
        $t2    = $this->makeTeacher('tutor.one');
        $prog  = $this->makeProgramme('DAW', null);
        $level = $this->makeLevel('1º DAW', null, $prog);
        $group = $this->makeGroup('DAW1A', null, $level, [$t1], [$t2]);
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([$prog]);
        $this->levelRepo->method('findByProgrammeOrderedByName')->willReturn([$level]);
        $this->groupRepo->method('findByLevelOrderedByName')->willReturn([$group]);

        $data = $this->exporter->export($this->year);

        $grp = $data['programmes'][0]['levels'][0]['groups'][0];
        self::assertSame(['teacher.one'], $grp['teachers']);
        self::assertSame(['tutor.one'], $grp['tutors']);
    }

    public function testExportSortsGroupTeachersAndTutors(): void
    {
        $prog  = $this->makeProgramme('DAW', null);
        $level = $this->makeLevel('1º DAW', null, $prog);
        $group = $this->makeGroup('DAW1A', null, $level,
            [$this->makeTeacher('z.teacher'), $this->makeTeacher('a.teacher')],
            [$this->makeTeacher('z.tutor'), $this->makeTeacher('a.tutor')],
        );
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([$prog]);
        $this->levelRepo->method('findByProgrammeOrderedByName')->willReturn([$level]);
        $this->groupRepo->method('findByLevelOrderedByName')->willReturn([$group]);

        $data = $this->exporter->export($this->year);

        $grp = $data['programmes'][0]['levels'][0]['groups'][0];
        self::assertSame(['a.teacher', 'z.teacher'], $grp['teachers']);
        self::assertSame(['a.tutor', 'z.tutor'], $grp['tutors']);
    }

    public function testExportGroupNullDetails(): void
    {
        $prog  = $this->makeProgramme('DAW', null);
        $level = $this->makeLevel('1º DAW', null, $prog);
        $group = $this->makeGroup('DAW1A', null, $level, [], []);
        $this->programmeRepo->method('findByAcademicYearOrdered')->willReturn([$prog]);
        $this->levelRepo->method('findByProgrammeOrderedByName')->willReturn([$level]);
        $this->groupRepo->method('findByLevelOrderedByName')->willReturn([$group]);

        $data = $this->exporter->export($this->year);

        self::assertNull($data['programmes'][0]['levels'][0]['groups'][0]['details']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProgramme(string $name, ?string $details): Programme
    {
        $prog = (new Programme())->setName($name)->setDetails($details)->setAcademicYear($this->year);
        $this->setId($prog);

        return $prog;
    }

    private function makeLevel(string $name, ?string $details, Programme $programme): ProgrammeYear
    {
        $level = (new ProgrammeYear())->setName($name)->setDetails($details)->setProgramme($programme);
        $this->setId($level);

        return $level;
    }

    /**
     * @param list<Teacher> $teachers
     * @param list<Teacher> $tutors
     */
    private function makeGroup(string $name, ?string $details, ProgrammeYear $level, array $teachers, array $tutors): Group
    {
        $group = (new Group())->setName($name)->setDetails($details)->setProgrammeYear($level);
        foreach ($teachers as $t) {
            $group->addTeacher($t);
        }
        foreach ($tutors as $t) {
            $group->addTutor($t);
        }
        $this->setId($group);

        return $group;
    }

    private function makeTeacher(string $username): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'User')))->setUsername($username);
        $this->setId($teacher);

        return $teacher;
    }

    private function setId(object $entity): void
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($entity, Uuid::v7());
    }
}
