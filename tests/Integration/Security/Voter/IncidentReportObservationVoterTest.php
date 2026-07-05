<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Security\Voter\IncidentReportObservationVoter;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class IncidentReportObservationVoterTest extends RepositoryTestCase
{
    private IncidentReportObservationVoter $voter;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentReportObservationVoter $voter */
        $voter       = self::getContainer()->get(IncidentReportObservationVoter::class);
        $this->voter = $voter;
    }

    // ── supports() ──────────────────────────────────────────────────────────

    public function testAbstainsOnUnknownAttribute(): void
    {
        [$observation, , , $registeredBy] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($registeredBy), $observation, ['unknown'])
        );
    }

    public function testAbstainsWhenSubjectIsNotObservation(): void
    {
        $teacher = $this->makeTeacher('t.abstain');
        $this->persist($teacher);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($teacher), new \stdClass(), [IncidentReportObservationVoter::EDIT])
        );
    }

    // ── Administrador global ─────────────────────────────────────────────────

    public function testGlobalAdminIsGrantedEdit(): void
    {
        [$observation] = $this->makeScenario('global.edit');
        $admin         = $this->makeTeacher('global.admin.edit', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    public function testGlobalAdminIsGrantedDelete(): void
    {
        [$observation] = $this->makeScenario('global.delete');
        $admin         = $this->makeTeacher('global.admin.delete', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $observation, [IncidentReportObservationVoter::DELETE])
        );
    }

    public function testGlobalAdminIsGrantedEditOnObservationOlderThanOneHour(): void
    {
        [$observation] = $this->makeScenario('global.old', new \DateTimeImmutable('-2 hours'));
        $admin         = $this->makeTeacher('global.admin.old', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    // ── Administrador de centro ──────────────────────────────────────────────

    public function testCentreAdminIsGrantedEdit(): void
    {
        [$observation, $centre] = $this->makeScenario('cadmin.edit');
        $cadmin                 = $this->makeTeacher('centre.admin.edit');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    public function testCentreAdminIsGrantedDelete(): void
    {
        [$observation, $centre] = $this->makeScenario('cadmin.delete');
        $cadmin                 = $this->makeTeacher('centre.admin.delete');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $observation, [IncidentReportObservationVoter::DELETE])
        );
    }

    public function testCentreAdminIsGrantedEditOnObservationOlderThanOneHour(): void
    {
        [$observation, $centre] = $this->makeScenario('cadmin.old', new \DateTimeImmutable('-2 hours'));
        $cadmin                 = $this->makeTeacher('centre.admin.old');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    public function testCentreAdminOfDifferentCentreIsDenied(): void
    {
        [$observation] = $this->makeScenario('other.centre');

        $otherCentre = (new EducationalCentre())->setCode('41900098')->setName('Other')->setCity('Sevilla');
        $cadmin      = $this->makeTeacher('other.centre.admin');
        $this->persist($otherCentre, $cadmin);
        $otherCentre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    // ── Docente que registró la observación ──────────────────────────────────

    public function testRegisteredByTeacherIsGrantedEditWithinOneHour(): void
    {
        [$observation, , , $registeredBy] = $this->makeScenario('owner.edit');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($registeredBy), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    public function testRegisteredByTeacherIsGrantedDeleteWithinOneHour(): void
    {
        [$observation, , , $registeredBy] = $this->makeScenario('owner.delete');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($registeredBy), $observation, [IncidentReportObservationVoter::DELETE])
        );
    }

    public function testRegisteredByTeacherIsDeniedEditAfterOneHour(): void
    {
        [$observation, , , $registeredBy] = $this->makeScenario('owner.edit.old', new \DateTimeImmutable('-61 minutes'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($registeredBy), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    public function testRegisteredByTeacherIsDeniedDeleteAfterOneHour(): void
    {
        [$observation, , , $registeredBy] = $this->makeScenario('owner.delete.old', new \DateTimeImmutable('-61 minutes'));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($registeredBy), $observation, [IncidentReportObservationVoter::DELETE])
        );
    }

    public function testRegisteredByTeacherIsGrantedEditJustBeforeOneHourExpires(): void
    {
        [$observation, , , $registeredBy] = $this->makeScenario('owner.edge', new \DateTimeImmutable('-59 minutes'));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($registeredBy), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    // ── Docente no relacionado ───────────────────────────────────────────────

    public function testUnrelatedTeacherIsDeniedEdit(): void
    {
        [$observation] = $this->makeScenario('unrelated.edit');
        $other         = $this->makeTeacher('unrelated.edit.teacher');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    public function testUnrelatedTeacherIsDeniedDelete(): void
    {
        [$observation] = $this->makeScenario('unrelated.delete');
        $other         = $this->makeTeacher('unrelated.delete.teacher');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $observation, [IncidentReportObservationVoter::DELETE])
        );
    }

    // ── Usuario anónimo ──────────────────────────────────────────────────────

    public function testAnonymousIsDeniedEdit(): void
    {
        [$observation] = $this->makeScenario('anonymous');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonymousToken(), $observation, [IncidentReportObservationVoter::EDIT])
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Builds a minimal entity graph (report + observation) and persists it.
     * Returns [observation, centre, report, registeredBy].
     *
     * @return array{0: IncidentReportObservation, 1: EducationalCentre, 2: IncidentReport, 3: Teacher}
     */
    private function makeScenario(string $suffix = '', ?\DateTimeImmutable $createdAt = null): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix), 0, 3))->setName('IES')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . $suffix)->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix . uniqid('', false));
        $registeredBy = $this->makeTeacher('registrant.' . $suffix . uniqid('', false));
        $category  = (new \App\Entity\IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior  = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación')
            ->setPosition(0)
            ->setActive(true);

        $this->persist($centre, $year, $programme, $level, $group, $student, $registeredBy, $category, $behavior);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($registeredBy)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Incidente de prueba</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        $observation = new IncidentReportObservation(
            $report,
            $registeredBy,
            new \DateTimeImmutable(),
            '<p>Observación de prueba.</p>',
            $createdAt,
        );

        $this->persist($report, $observation);

        return [$observation, $centre, $report, $registeredBy];
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
