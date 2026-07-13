<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\MessageHandler\AutoPrescribeIncidentReportsHandler;
use App\Message\AutoPrescribeIncidentReportsMessage;
use App\Tests\Integration\RepositoryTestCase;

class AutoPrescribeIncidentReportsHandlerTest extends RepositoryTestCase
{
    private AutoPrescribeIncidentReportsHandler $handler;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDaysDefinition();

        /** @var AutoPrescribeIncidentReportsHandler $handler */
        $handler       = self::getContainer()->get(AutoPrescribeIncidentReportsHandler::class);
        $this->handler = $handler;
    }

    public function testPrescribesEligibleOverdueReport(): void
    {
        $world  = $this->makeWorld('overdue');
        $report = $this->makeReport($world, occurredAt: new \DateTimeImmutable('-20 days'));
        $this->persist($report);
        $this->setDays($world['centre'], 14);

        ($this->handler)(new AutoPrescribeIncidentReportsMessage());

        $this->em->refresh($report);
        self::assertTrue($report->isPrescribed());
    }

    public function testDoesNotPrescribeReportStillWithinGracePeriod(): void
    {
        $world  = $this->makeWorld('recent');
        $report = $this->makeReport($world, occurredAt: new \DateTimeImmutable('-5 days'));
        $this->persist($report);
        $this->setDays($world['centre'], 14);

        ($this->handler)(new AutoPrescribeIncidentReportsMessage());

        $this->em->refresh($report);
        self::assertFalse($report->isPrescribed());
    }

    public function testZeroDaysSettingDisablesAutoPrescriptionForThatCentre(): void
    {
        $world  = $this->makeWorld('disabled');
        $report = $this->makeReport($world, occurredAt: new \DateTimeImmutable('-100 days'));
        $this->persist($report);
        $this->setDays($world['centre'], 0);

        ($this->handler)(new AutoPrescribeIncidentReportsMessage());

        $this->em->refresh($report);
        self::assertFalse($report->isPrescribed());
    }

    public function testEachCentreUsesItsOwnThreshold(): void
    {
        $worldA  = $this->makeWorld('centreA');
        $worldB  = $this->makeWorld('centreB');
        $reportA = $this->makeReport($worldA, occurredAt: new \DateTimeImmutable('-10 days'));
        $reportB = $this->makeReport($worldB, occurredAt: new \DateTimeImmutable('-10 days'));
        $this->persist($reportA, $reportB);
        $this->setDays($worldA['centre'], 7);
        $this->setDays($worldB['centre'], 30);

        ($this->handler)(new AutoPrescribeIncidentReportsMessage());

        $this->em->refresh($reportA);
        $this->em->refresh($reportB);
        self::assertTrue($reportA->isPrescribed());
        self::assertFalse($reportB->isPrescribed());
    }

    public function testSendsAutoPrescribedNotificationEmail(): void
    {
        $world  = $this->makeWorld('email');
        $report = $this->makeReport($world, occurredAt: new \DateTimeImmutable('-20 days'));
        $this->persist($report);
        $this->setDays($world['centre'], 14);
        $this->setChoiceSetting('notifications.email_report_prescribed', $world['centre'], 'report_teacher');

        ($this->handler)(new AutoPrescribeIncidentReportsMessage());

        self::assertEmailCount(1);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher}
     */
    private function makeWorld(string $suffix): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'x'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix . uniqid('', false));
        $creator   = (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername('creator.' . $suffix . uniqid('', false))
            ->setEmail('creator.' . $suffix . '@ejemplo.local');

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $creator);

        return compact('centre', 'year', 'group', 'student', 'creator');
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher} $world
     */
    private function makeReport(array $world, \DateTimeImmutable $occurredAt): IncidentReport
    {
        return (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['creator'])
            ->setOccurredAt($occurredAt)
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
    }

    private function seedDaysDefinition(): void
    {
        $definition = (new SettingDefinition())
            ->setKey('notifications.report_auto_prescribe_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('14')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setMinValue(0)
            ->setMaxValue(365);
        $this->persist($definition);
    }

    private function setDays(EducationalCentre $centre, int $days): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'notifications.report_auto_prescribe_days']);

        $value = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $days);
        $this->persist($value);
    }

    private function setChoiceSetting(string $key, EducationalCentre $centre, string $value): void
    {
        $definition = (new SettingDefinition())
            ->setKey($key)
            ->setType(SettingType::Choice)
            ->setDefaultValue('none')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setChoices('none,report_teacher,group_tutor,both,committee');
        $this->persist($definition);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value);
        $this->persist($centreValue);
    }
}
