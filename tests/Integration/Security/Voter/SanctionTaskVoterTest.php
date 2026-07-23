<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Security\Voter\SanctionTaskVoter;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class SanctionTaskVoterTest extends RepositoryTestCase
{
    private SanctionTaskVoter $voter;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SanctionTaskVoter $voter */
        $voter       = self::getContainer()->get(SanctionTaskVoter::class);
        $this->voter = $voter;
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        [$task] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($task->getGroupTeacher()->getTeacher()), $task, ['unknown'])
        );
    }

    public function testAbstainsWhenSubjectIsNotSanctionTask(): void
    {
        $teacher = $this->makeTeacher('abstain.subject');
        $this->persist($teacher);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($teacher), new \stdClass(), [SanctionTaskVoter::VIEW])
        );
    }

    public function testAssignedTeacherIsGrantedEverything(): void
    {
        [$task] = $this->makeScenario();

        foreach ([SanctionTaskVoter::VIEW, SanctionTaskVoter::EDIT] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->voter->vote($this->token($task->getGroupTeacher()->getTeacher()), $task, [$attribute])
            );
        }
    }

    public function testGlobalAdminIsGrantedEverythingOnAnotherTeachersTask(): void
    {
        [$task] = $this->makeScenario();
        $admin  = $this->makeTeacher('global.admin', admin: true);
        $this->persist($admin);

        foreach ([SanctionTaskVoter::VIEW, SanctionTaskVoter::EDIT] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->voter->vote($this->token($admin), $task, [$attribute])
            );
        }
    }

    public function testCentreAdminIsGrantedEverythingOnAnotherTeachersTask(): void
    {
        [$task, $centre] = $this->makeScenario();
        $cadmin           = $this->makeTeacher('centre.admin');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        foreach ([SanctionTaskVoter::VIEW, SanctionTaskVoter::EDIT] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->voter->vote($this->token($cadmin), $task, [$attribute])
            );
        }
    }

    public function testCentreAdminOfDifferentCentreIsDenied(): void
    {
        [$task] = $this->makeScenario();

        $otherCentre = (new EducationalCentre())->setCode('41900097')->setName('Other')->setCity('Sevilla');
        $cadmin      = $this->makeTeacher('other.centre.admin');
        $this->persist($otherCentre, $cadmin);
        $otherCentre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $task, [SanctionTaskVoter::EDIT])
        );
    }

    public function testUnrelatedTeacherIsDeniedEdit(): void
    {
        [$task] = $this->makeScenario();
        $other  = $this->makeTeacher('unrelated');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $task, [SanctionTaskVoter::EDIT])
        );
    }

    public function testAnonymousIsDenied(): void
    {
        [$task] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonymousToken(), $task, [SanctionTaskVoter::VIEW])
        );
    }

    /** @return array{0: SanctionTask, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $suffix        = uniqid('', true);
        $centre        = (new EducationalCentre())->setCode('41000' . substr(md5($suffix), 0, 3))->setName('IES')->setCity('Sevilla');
        $year          = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course        = (new Course())->setName('DAW')->setAcademicYear($year);
        $group         = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student       = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix);
        $registeredBy  = $this->makeTeacher('registered.' . $suffix);
        $assignedTeacher = $this->makeTeacher('assigned.' . $suffix);
        $groupTeacher  = new GroupTeacher($group, $assignedTeacher, 'Matemáticas');
        $this->persist($centre, $year, $course, $group, $student, $registeredBy, $assignedTeacher, $groupTeacher);

        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($registeredBy)
            ->setDetails('Detalles de la sanción')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);

        $task = new SanctionTask($sanction, $groupTeacher);
        $this->persist($task);

        return [$task, $centre];
    }

    private function makeTeacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setAdmin($admin);
    }

    private function token(Teacher $teacher): TokenInterface
    {
        $stub = $this->createStub(TokenInterface::class);
        $stub->method('getUser')->willReturn($teacher);

        return $stub;
    }

    private function anonymousToken(): TokenInterface
    {
        $stub = $this->createStub(TokenInterface::class);
        $stub->method('getUser')->willReturn(null);

        return $stub;
    }
}
