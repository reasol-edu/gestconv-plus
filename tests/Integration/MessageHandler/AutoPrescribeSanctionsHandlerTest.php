<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Message\AutoPrescribeSanctionsMessage;
use App\MessageHandler\AutoPrescribeSanctionsHandler;
use App\Tests\Integration\RepositoryTestCase;

class AutoPrescribeSanctionsHandlerTest extends RepositoryTestCase
{
    private AutoPrescribeSanctionsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $definition = (new SettingDefinition())
            ->setKey('notifications.sanction_auto_prescribe_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('0')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setMinValue(0)
            ->setMaxValue(365);
        $this->persist($definition);

        /** @var AutoPrescribeSanctionsHandler $handler */
        $handler       = self::getContainer()->get(AutoPrescribeSanctionsHandler::class);
        $this->handler = $handler;
    }

    public function testSanctionsNeverPrescribeByDefault(): void
    {
        $world    = $this->makeWorld('default');
        $sanction = $this->makeSanction($world, '-400 days');
        $this->persist($sanction);

        ($this->handler)(new AutoPrescribeSanctionsMessage());

        $this->em->refresh($sanction);
        self::assertFalse($sanction->isPrescribed());
    }

    public function testPrescribesUnnotifiedSanctionOlderThanTheThreshold(): void
    {
        $world    = $this->makeWorld('overdue');
        $sanction = $this->makeSanction($world, '-20 days');
        $this->persist($sanction);
        $this->setDays($world['centre'], 14);

        ($this->handler)(new AutoPrescribeSanctionsMessage());

        $this->em->refresh($sanction);
        self::assertTrue($sanction->isPrescribed());
    }

    public function testDoesNotPrescribeRecentSanction(): void
    {
        $world    = $this->makeWorld('recent');
        $sanction = $this->makeSanction($world, '-5 days');
        $this->persist($sanction);
        $this->setDays($world['centre'], 14);

        ($this->handler)(new AutoPrescribeSanctionsMessage());

        $this->em->refresh($sanction);
        self::assertFalse($sanction->isPrescribed());
    }

    public function testEachCentreUsesItsOwnThreshold(): void
    {
        $worldA    = $this->makeWorld('centreA');
        $worldB    = $this->makeWorld('centreB');
        $sanctionA = $this->makeSanction($worldA, '-10 days');
        $sanctionB = $this->makeSanction($worldB, '-10 days');
        $this->persist($sanctionA, $sanctionB);
        $this->setDays($worldA['centre'], 7);
        $this->setDays($worldB['centre'], 30);

        ($this->handler)(new AutoPrescribeSanctionsMessage());

        $this->em->refresh($sanctionA);
        $this->em->refresh($sanctionB);
        self::assertTrue($sanctionA->isPrescribed());
        self::assertFalse($sanctionB->isPrescribed());
    }

    public function testSendsAutoPrescribedNotificationEmail(): void
    {
        $world    = $this->makeWorld('email');
        $sanction = $this->makeSanction($world, '-20 days');
        $this->persist($sanction);
        $this->setDays($world['centre'], 14);
        $this->setChoiceSetting('notifications.email_sanction_prescribed', $world['centre'], 'report_teacher');

        ($this->handler)(new AutoPrescribeSanctionsMessage());

        self::assertEmailCount(1);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher}
     */
    private function makeWorld(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('42000' . substr(md5($suffix . 'x'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix . uniqid('', false));
        $creator = (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername('creator.' . $suffix . uniqid('', false))
            ->setEmail('creator.' . $suffix . '@ejemplo.local');

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $creator);

        return compact('centre', 'year', 'group', 'student', 'creator');
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher} $world
     */
    private function makeSanction(array $world, string $createdAgo): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['creator'])
            ->setDetails('<p>Test</p>')
            ->setNoMeasureApplied(true);
        (new \ReflectionProperty($sanction, 'createdAt'))->setValue($sanction, new \DateTimeImmutable($createdAgo));

        return $sanction;
    }

    private function setDays(EducationalCentre $centre, int $days): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'notifications.sanction_auto_prescribe_days']);

        $this->persist((new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $days));
    }

    private function setChoiceSetting(string $key, EducationalCentre $centre, string $value): void
    {
        $definition = (new SettingDefinition())
            ->setKey($key)
            ->setType(SettingType::Choice)
            ->setDefaultValue('none')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setChoices('none,report_teacher,group_tutor,both');
        $this->persist($definition);

        $this->persist((new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value));
    }
}
