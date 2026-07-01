<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Security\Voter\SanctionVoter;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class SanctionVoterTest extends RepositoryTestCase
{
    private SanctionVoter $voter;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SanctionVoter $voter */
        $voter       = self::getContainer()->get(SanctionVoter::class);
        $this->voter = $voter;
    }

    // ── supports() ──────────────────────────────────────────────────────────

    public function testAbstainsOnCreateAttribute(): void
    {
        $sanction = $this->makeSanction();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($sanction->getRegisteredBy()), $sanction, [SanctionVoter::CREATE])
        );
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $sanction = $this->makeSanction();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($sanction->getRegisteredBy()), $sanction, ['unknown'])
        );
    }

    public function testAbstainsWhenSubjectIsNotSanction(): void
    {
        $teacher = $this->makeTeacher('t.abstain');
        $this->persist($teacher);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($teacher), new \stdClass(), [SanctionVoter::VIEW])
        );
    }

    // ── Administrador global ─────────────────────────────────────────────────

    public function testGlobalAdminIsGrantedView(): void
    {
        $sanction = $this->makeSanction();
        $admin    = $this->makeTeacher('global.admin.v', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $sanction, [SanctionVoter::VIEW])
        );
    }

    public function testGlobalAdminIsGrantedEdit(): void
    {
        $sanction = $this->makeSanction();
        $admin    = $this->makeTeacher('global.admin.e', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $sanction, [SanctionVoter::EDIT])
        );
    }

    public function testGlobalAdminIsGrantedDelete(): void
    {
        $sanction = $this->makeSanction();
        $admin    = $this->makeTeacher('global.admin.d', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $sanction, [SanctionVoter::DELETE])
        );
    }

    // ── Administrador de centro ──────────────────────────────────────────────

    public function testCentreAdminIsGrantedView(): void
    {
        [$sanction, $centre] = $this->makeSanctionWithCentre();
        $cadmin              = $this->makeTeacher('cadmin.v');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $sanction, [SanctionVoter::VIEW])
        );
    }

    public function testCentreAdminIsGrantedEdit(): void
    {
        [$sanction, $centre] = $this->makeSanctionWithCentre();
        $cadmin              = $this->makeTeacher('cadmin.e');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $sanction, [SanctionVoter::EDIT])
        );
    }

    public function testCentreAdminIsGrantedDelete(): void
    {
        [$sanction, $centre] = $this->makeSanctionWithCentre();
        $cadmin              = $this->makeTeacher('cadmin.d');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $sanction, [SanctionVoter::DELETE])
        );
    }

    public function testCentreAdminOfDifferentCentreIsDeniedView(): void
    {
        [$sanction] = $this->makeSanctionWithCentre('A');

        $otherCentre = (new EducationalCentre())->setCode('41900099')->setName('Other')->setCity('Sevilla');
        $cadmin      = $this->makeTeacher('other.cadmin');
        $this->persist($otherCentre, $cadmin);
        $otherCentre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($cadmin), $sanction, [SanctionVoter::VIEW])
        );
    }

    // ── Docente: creador de un parte vinculado a la sanción ──────────────────

    public function testReportCreatorIsGrantedView(): void
    {
        $creator  = $this->makeTeacher('report.creator.v');
        $this->persist($creator);
        $sanction = $this->makeSanctionWithReport($creator);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($creator), $sanction, [SanctionVoter::VIEW])
        );
    }

    public function testReportCreatorIsDeniedEdit(): void
    {
        $creator  = $this->makeTeacher('report.creator.e');
        $this->persist($creator);
        $sanction = $this->makeSanctionWithReport($creator);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($creator), $sanction, [SanctionVoter::EDIT])
        );
    }

    public function testReportCreatorIsDeniedDelete(): void
    {
        $creator  = $this->makeTeacher('report.creator.d');
        $this->persist($creator);
        $sanction = $this->makeSanctionWithReport($creator);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($creator), $sanction, [SanctionVoter::DELETE])
        );
    }

    // ── Tutor del grupo ──────────────────────────────────────────────────────

    public function testGroupTutorIsGrantedView(): void
    {
        [$sanction, , $group] = $this->makeSanctionWithCentreAndReport('tutor.v.world');
        $tutor                = $this->makeTeacher('group.tutor.v');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($tutor), $sanction, [SanctionVoter::VIEW])
        );
    }

    public function testGroupTutorIsDeniedEdit(): void
    {
        [$sanction, , $group] = $this->makeSanctionWithCentreAndReport('tutor.e.world');
        $tutor                = $this->makeTeacher('group.tutor.e');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($tutor), $sanction, [SanctionVoter::EDIT])
        );
    }

    public function testGroupTutorIsDeniedDelete(): void
    {
        [$sanction, , $group] = $this->makeSanctionWithCentreAndReport('tutor.d.world');
        $tutor                = $this->makeTeacher('group.tutor.d');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($tutor), $sanction, [SanctionVoter::DELETE])
        );
    }

    // ── Docente no relacionado ───────────────────────────────────────────────

    public function testUnrelatedTeacherIsDeniedView(): void
    {
        $sanction = $this->makeSanction();
        $other    = $this->makeTeacher('unrelated.v');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $sanction, [SanctionVoter::VIEW])
        );
    }

    public function testUnrelatedTeacherIsDeniedEdit(): void
    {
        $sanction = $this->makeSanction();
        $other    = $this->makeTeacher('unrelated.e');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $sanction, [SanctionVoter::EDIT])
        );
    }

    // ── Usuario anónimo ──────────────────────────────────────────────────────

    public function testAnonymousIsDeniedView(): void
    {
        $sanction = $this->makeSanction();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonymousToken(), $sanction, [SanctionVoter::VIEW])
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeSanction(string $suffix = ''): Sanction
    {
        [$sanction] = $this->makeSanctionWithCentre($suffix);

        return $sanction;
    }

    /**
     * Returns [sanction, centre, group, registeredBy].
     *
     * @return array{0: Sanction, 1: EducationalCentre, 2: Group, 3: Teacher}
     */
    private function makeSanctionWithCentre(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix), 0, 3))->setName('IES')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . $suffix)->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix . uniqid('', false));
        $creator   = $this->makeTeacher('sanction.creator.' . $suffix . uniqid('', false));

        $this->persist($centre, $year, $programme, $level, $group, $student, $creator);

        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalles de la sanción')
            ->setNoMeasureApplied(false);

        $this->persist($sanction);

        return [$sanction, $centre, $group, $creator];
    }

    /**
     * Like makeSanctionWithCentre but also links a report so that tutor-based
     * visibility works (the non-admin query does an INNER JOIN on s.reports).
     *
     * The report is also added to the in-memory reports collection so that the
     * voter's $sanction->getReports()->exists(...) check works without needing
     * a full DB reload (a PersistentCollection created from new() is initialized
     * as empty and won't lazy-load unless we populate the inverse side explicitly).
     *
     * @return array{0: Sanction, 1: EducationalCentre, 2: Group, 3: Teacher}
     */
    private function makeSanctionWithCentreAndReport(string $suffix = ''): array
    {
        [$sanction, $centre, $group, $creator] = $this->makeSanctionWithCentre($suffix);

        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($category, $behavior);

        $report = (new IncidentReport())
            ->setAcademicYear($sanction->getAcademicYear())
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($sanction->getStudent())
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false)
            ->setSanction($sanction);
        $report->addBehavior($behavior);

        // Keep inverse-side collection in sync so the voter's exists() check works
        $sanction->getReports()->add($report);

        $this->persist($report);

        return [$sanction, $centre, $group, $creator];
    }

    /**
     * Creates a sanction with a linked IncidentReport registered by $creator.
     *
     * The report is added to the inverse-side reports collection so the voter's
     * getReports()->exists() check works without a DB reload.
     */
    private function makeSanctionWithReport(Teacher $creator): Sanction
    {
        $centre    = (new EducationalCentre())->setCode('41' . substr(uniqid('', false), 0, 6))->setName('IES')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . uniqid('', false))->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . uniqid('', false));
        $category  = (new IncidentBehaviorCategory())
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

        $this->persist($centre, $year, $programme, $level, $group, $student, $category, $behavior);

        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalles de la sanción')
            ->setNoMeasureApplied(false);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false)
            ->setSanction($sanction);
        $report->addBehavior($behavior);

        // Keep inverse-side collection in sync before persisting
        $sanction->getReports()->add($report);

        $this->persist($sanction, $report);

        return $sanction;
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
