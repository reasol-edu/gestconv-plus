<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Security\Voter\IncidentReportVoter;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class IncidentReportVoterTest extends RepositoryTestCase
{
    private IncidentReportVoter $voter;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentReportVoter $voter */
        $voter       = self::getContainer()->get(IncidentReportVoter::class);
        $this->voter = $voter;
    }

    // ── supports() ──────────────────────────────────────────────────────────

    public function testAbstainsOnUnknownAttribute(): void
    {
        [$report] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($report->getRegisteredBy()), $report, ['unknown'])
        );
    }

    public function testAbstainsWhenSubjectIsNotIncidentReport(): void
    {
        $teacher = $this->makeTeacher('t.abstain');
        $this->persist($teacher);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($teacher), new \stdClass(), [IncidentReportVoter::VIEW])
        );
    }

    // ── Administrador global ─────────────────────────────────────────────────

    public function testGlobalAdminIsGrantedView(): void
    {
        [$report] = $this->makeScenario();
        $admin    = $this->makeTeacher('global.admin', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testGlobalAdminIsGrantedEdit(): void
    {
        [$report] = $this->makeScenario();
        $admin    = $this->makeTeacher('global.admin.edit', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $report, [IncidentReportVoter::EDIT])
        );
    }

    public function testGlobalAdminIsGrantedDelete(): void
    {
        [$report] = $this->makeScenario();
        $admin    = $this->makeTeacher('global.admin.del', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $report, [IncidentReportVoter::DELETE])
        );
    }

    // ── Administrador de centro ──────────────────────────────────────────────

    public function testCentreAdminIsGrantedView(): void
    {
        [$report, $centre] = $this->makeScenario();
        $cadmin            = $this->makeTeacher('centre.admin.v');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testCentreAdminIsGrantedDelete(): void
    {
        [$report, $centre] = $this->makeScenario();
        $cadmin            = $this->makeTeacher('centre.admin.d');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $report, [IncidentReportVoter::DELETE])
        );
    }

    public function testCentreAdminOfDifferentCentreIsDenied(): void
    {
        [$report] = $this->makeScenario();

        $otherCentre = (new EducationalCentre())->setCode('41900099')->setName('Other')->setCity('Sevilla');
        $cadmin      = $this->makeTeacher('other.centre.admin');
        $this->persist($otherCentre, $cadmin);
        $otherCentre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $report, [IncidentReportVoter::VIEW])
        );
    }

    // ── Docente creador del parte ────────────────────────────────────────────

    public function testCreatorIsGrantedView(): void
    {
        [$report] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($report->getRegisteredBy()), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testCreatorIsGrantedEdit(): void
    {
        [$report] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($report->getRegisteredBy()), $report, [IncidentReportVoter::EDIT])
        );
    }

    public function testCreatorIsDeniedDelete(): void
    {
        [$report] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($report->getRegisteredBy()), $report, [IncidentReportVoter::DELETE])
        );
    }

    // ── Tutor del grupo ──────────────────────────────────────────────────────

    public function testGroupTutorIsGrantedView(): void
    {
        [$report, , $group] = $this->makeScenario();
        $tutor              = $this->makeTeacher('tutor.view');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testGroupTutorIsDeniedEdit(): void
    {
        [$report, , $group] = $this->makeScenario();
        $tutor              = $this->makeTeacher('tutor.edit');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::EDIT])
        );
    }

    public function testGroupTutorIsDeniedDelete(): void
    {
        [$report, , $group] = $this->makeScenario();
        $tutor              = $this->makeTeacher('tutor.del');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::DELETE])
        );
    }

    // ── Docente no relacionado ───────────────────────────────────────────────

    public function testUnrelatedTeacherIsDeniedView(): void
    {
        [$report] = $this->makeScenario();
        $other    = $this->makeTeacher('unrelated.view');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testUnrelatedTeacherIsDeniedEdit(): void
    {
        [$report] = $this->makeScenario();
        $other    = $this->makeTeacher('unrelated.edit');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $report, [IncidentReportVoter::EDIT])
        );
    }

    // ── Usuario anónimo ──────────────────────────────────────────────────────

    public function testAnonymousIsDeniedView(): void
    {
        [$report] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonymousToken(), $report, [IncidentReportVoter::VIEW])
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Builds a minimal entity graph and persists it.
     * Returns [report, centre, group, creator].
     *
     * @return array{0: IncidentReport, 1: EducationalCentre, 2: Group, 3: Teacher}
     */
    private function makeScenario(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix), 0, 3))->setName('IES')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . $suffix)->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix . uniqid('', false));
        $creator   = $this->makeTeacher('creator.' . $suffix . uniqid('', false));
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

        $this->persist($centre, $year, $programme, $level, $group, $student, $creator, $category, $behavior);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Incidente de prueba</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        $this->persist($report);

        return [$report, $centre, $group, $creator];
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
