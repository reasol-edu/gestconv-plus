<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
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

    public function testGlobalAdminIsGrantedReassign(): void
    {
        [$report] = $this->makeScenario();
        $admin    = $this->makeTeacher('global.admin.reassign', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $report, [IncidentReportVoter::REASSIGN])
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

    public function testCentreAdminIsGrantedReassign(): void
    {
        [$report, $centre] = $this->makeScenario();
        $cadmin            = $this->makeTeacher('centre.admin.reassign');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $report, [IncidentReportVoter::REASSIGN])
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

    // ── Comisión de convivencia ──────────────────────────────────────────────

    public function testCommitteeMemberIsGrantedView(): void
    {
        [$report, $centre] = $this->makeScenario();
        $committee          = $this->makeTeacher('committee.view');
        $this->persist($committee);
        $centre->addCommitteeMember($committee);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($committee), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testCommitteeMemberIsDeniedEdit(): void
    {
        [$report, $centre] = $this->makeScenario();
        $committee          = $this->makeTeacher('committee.edit');
        $this->persist($committee);
        $centre->addCommitteeMember($committee);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($committee), $report, [IncidentReportVoter::EDIT])
        );
    }

    // ── Orientador/a ─────────────────────────────────────────────────────────

    public function testCounselorIsGrantedView(): void
    {
        [$report, $centre] = $this->makeScenario();
        $counselor          = $this->makeTeacher('counselor.view');
        $this->persist($counselor);
        $centre->addCounselor($counselor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($counselor), $report, [IncidentReportVoter::VIEW])
        );
    }

    public function testCounselorIsDeniedEdit(): void
    {
        [$report, $centre] = $this->makeScenario();
        $counselor          = $this->makeTeacher('counselor.edit');
        $this->persist($counselor);
        $centre->addCounselor($counselor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($counselor), $report, [IncidentReportVoter::EDIT])
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

    public function testCreatorIsDeniedReassign(): void
    {
        [$report] = $this->makeScenario();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($report->getRegisteredBy()), $report, [IncidentReportVoter::REASSIGN])
        );
    }

    public function testCreatorIsDeniedEditOnceNotified(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('notified.creator');
        $this->notifyReport($report, $centre, $creator);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($creator), $report, [IncidentReportVoter::EDIT])
        );
    }

    public function testGlobalAdminIsGrantedEditOnceNotified(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('notified.global');
        $this->notifyReport($report, $centre, $creator);
        $admin = $this->makeTeacher('global.admin.notified.edit', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $report, [IncidentReportVoter::EDIT])
        );
    }

    public function testCentreAdminIsGrantedEditOnceNotified(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('notified.cadmin');
        $this->notifyReport($report, $centre, $creator);
        $cadmin = $this->makeTeacher('centre.admin.notified.edit');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $report, [IncidentReportVoter::EDIT])
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

    // ── Notificar (NOTIFY) ───────────────────────────────────────────────────

    public function testGlobalAdminIsGrantedNotifyRegardlessOfSetting(): void
    {
        [$report, $centre] = $this->makeScenario('notify.admin');
        $this->setReportNotifierSetting($centre, 'report_teacher');
        $admin = $this->makeTeacher('global.admin.notify', admin: true);
        $this->persist($admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($admin), $report, [IncidentReportVoter::NOTIFY])
        );
    }

    public function testCentreAdminIsGrantedNotifyRegardlessOfSetting(): void
    {
        [$report, $centre] = $this->makeScenario('notify.cadmin');
        $this->setReportNotifierSetting($centre, 'report_teacher');
        $cadmin = $this->makeTeacher('centre.admin.notify');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($cadmin), $report, [IncidentReportVoter::NOTIFY])
        );
    }

    public function testReportTeacherSettingGrantsCreatorAndDeniesTutor(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('rt');
        $this->setReportNotifierSetting($centre, 'report_teacher');
        $tutor = $this->makeTeacher('tutor.rt');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($creator), $report, [IncidentReportVoter::NOTIFY])
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::NOTIFY])
        );
    }

    public function testGroupTutorSettingGrantsTutorAndDeniesCreator(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('gt');
        $this->setReportNotifierSetting($centre, 'group_tutor');
        $tutor = $this->makeTeacher('tutor.gt');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($creator), $report, [IncidentReportVoter::NOTIFY])
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::NOTIFY])
        );
    }

    public function testBothSettingGrantsCreatorAndTutor(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('both');
        $this->setReportNotifierSetting($centre, 'both');
        $tutor = $this->makeTeacher('tutor.both');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($creator), $report, [IncidentReportVoter::NOTIFY])
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::NOTIFY])
        );
    }

    public function testNoSettingDefinedDefaultsToBothBehaviour(): void
    {
        [$report, , $group, $creator] = $this->makeScenario('nodef');
        $tutor = $this->makeTeacher('tutor.nodef');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($creator), $report, [IncidentReportVoter::NOTIFY])
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($tutor), $report, [IncidentReportVoter::NOTIFY])
        );
    }

    public function testUnrelatedTeacherIsDeniedNotify(): void
    {
        [$report, $centre] = $this->makeScenario('unrel');
        $this->setReportNotifierSetting($centre, 'both');
        $other = $this->makeTeacher('unrelated.notify');
        $this->persist($other);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($other), $report, [IncidentReportVoter::NOTIFY])
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

    private function setReportNotifierSetting(EducationalCentre $centre, string $value): void
    {
        $definition = (new SettingDefinition())
            ->setKey('notifications.report_notifier')
            ->setType(SettingType::Choice)
            ->setDefaultValue('both')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setChoices('report_teacher,group_tutor,both');
        $this->persist($definition);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value);
        $this->persist($centreValue);
    }

    private function notifyReport(IncidentReport $report, EducationalCentre $centre, Teacher $teacher): void
    {
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $communication = Communication::forIncidentReport(
            $report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified,
        );
        $this->persist($method, $communication);

        $report->setNotifiedCommunication($communication);
        $this->flush();
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
