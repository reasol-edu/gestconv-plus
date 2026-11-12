<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Message\CloseForgottenExitsMessage;
use App\MessageHandler\CloseForgottenExitsHandler;
use App\Repository\StudentExitRepository;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class CloseForgottenExitsHandlerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private CloseForgottenExitsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-14 10:00:00');

        /** @var CloseForgottenExitsHandler $handler */
        $handler       = self::getContainer()->get(CloseForgottenExitsHandler::class);
        $this->handler = $handler;
    }

    public function testClosesOnlyOpenExitsFromPreviousDaysWithoutInventingAReturn(): void
    {
        $w         = $this->makeWorld('a');
        $forgotten = $this->makeExit($w, '2026-10-13 12:30:00');
        $justBefore = $this->makeExit($w, '2026-10-13 23:59:00');
        $today     = $this->makeExit($w, '2026-10-14 00:05:00');
        $returned  = $this->makeExit($w, '2026-10-13 09:00:00');
        $returned->markReturned($w['teacher'], new \DateTimeImmutable('2026-10-13 09:10:00'));
        $this->em->flush();
        $ids = [
            'forgotten'  => $forgotten->getId(),
            'justBefore' => $justBefore->getId(),
            'today'      => $today->getId(),
            'returned'   => $returned->getId(),
        ];

        ($this->handler)(new CloseForgottenExitsMessage());

        $this->em->clear();
        foreach (['forgotten', 'justBefore'] as $key) {
            $exit = $this->reload($ids[$key]);
            self::assertTrue($exit->isAutoClosed(), $key);
            self::assertFalse($exit->isReturned(), $key . ': no se inventa un regreso');
            self::assertNull($exit->getReturnedAt(), $key);
            self::assertFalse($exit->isOut(), $key);
        }
        self::assertTrue($this->reload($ids['today'])->isOut(), 'La salida de hoy sigue abierta.');
        $returnedReloaded = $this->reload($ids['returned']);
        self::assertFalse($returnedReloaded->isAutoClosed(), 'Una salida con regreso no se toca.');
        self::assertTrue($returnedReloaded->isReturned());
    }

    public function testAClosedExitNoLongerCountsAsCurrentSoTheStudentCanLeaveAgain(): void
    {
        $w = $this->makeWorld('b');
        $this->makeExit($w, '2026-10-13 12:30:00');
        $this->em->flush();

        /** @var StudentExitRepository $repo */
        $repo = self::getContainer()->get(StudentExitRepository::class);
        self::assertCount(1, $repo->findCurrent($w['year']));

        ($this->handler)(new CloseForgottenExitsMessage());

        $this->em->clear();
        self::assertCount(0, $repo->findCurrent($w['year']));
    }

    public function testRunningTwiceDoesNotMoveTheClosingInstant(): void
    {
        $w    = $this->makeWorld('c');
        $exit = $this->makeExit($w, '2026-10-13 12:30:00');
        $this->em->flush();
        $id = $exit->getId();

        ($this->handler)(new CloseForgottenExitsMessage());
        $this->em->clear();
        $first = $this->reload($id)->getAutoClosedAt();

        self::mockTime('2026-10-15 03:00:00');
        ($this->handler)(new CloseForgottenExitsMessage());
        $this->em->clear();

        self::assertEquals($first, $this->reload($id)->getAutoClosedAt());
    }

    public function testSkipsCentresWithTheExitsSectionDisabled(): void
    {
        $w = $this->makeWorld('d');
        $exit = $this->makeExit($w, '2026-10-13 12:30:00');
        $definition = (new SettingDefinition())->setKey('features.exits')->setType(SettingType::Boolean)
            ->setCategory('settings.category.features')->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($w['centre'])->setValue('false'));
        $id = $exit->getId();

        ($this->handler)(new CloseForgottenExitsMessage());

        $this->em->clear();
        self::assertTrue($this->reload($id)->isOut());
    }

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, reason: ExitReason} */
    private function makeWorld(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('41' . substr(md5($suffix . 'cfe'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.cfe.' . $suffix);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-cfe-' . $suffix);
        $student->addGroup($group);
        $reason = (new ExitReason())->setEducationalCentre($centre)->setName('Servicio')->setPosition(0);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher, $student, $reason);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'reason');
    }

    /** @param array{year: AcademicYear, group: Group, student: Student, teacher: Teacher, reason: ExitReason} $w */
    private function makeExit(array $w, string $at): StudentExit
    {
        $exit = (new StudentExit())
            ->setAcademicYear($w['year'])
            ->setStudent($w['student'])
            ->setGroup($w['group'])
            ->setReason($w['reason'])
            ->setAuthorizedBy($w['teacher'])
            ->setExitedAt(new \DateTimeImmutable($at));
        $this->em->persist($exit);

        return $exit;
    }

    private function reload(\Symfony\Component\Uid\Uuid $id): StudentExit
    {
        /** @var StudentExitRepository $repo */
        $repo   = self::getContainer()->get(StudentExitRepository::class);
        $result = $repo->findById($id->toRfc4122());
        self::assertNotNull($result);

        return $result;
    }
}
