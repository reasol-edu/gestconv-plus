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
use App\Message\WarnUpcomingSanctionPrescriptionsMessage;
use App\MessageHandler\WarnUpcomingSanctionPrescriptionsHandler;
use App\Tests\Integration\RepositoryTestCase;

class WarnUpcomingSanctionPrescriptionsHandlerTest extends RepositoryTestCase
{
    private WarnUpcomingSanctionPrescriptionsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persist(
            (new SettingDefinition())
                ->setKey('notifications.sanction_auto_prescribe_days')
                ->setType(SettingType::Integer)
                ->setDefaultValue('0')
                ->setGlobalScope(true)
                ->setCentreScope(true)
                ->setMinValue(0)
                ->setMaxValue(365),
            (new SettingDefinition())
                ->setKey('notifications.sanction_prescription_warning_days')
                ->setType(SettingType::Integer)
                ->setDefaultValue('7')
                ->setGlobalScope(true)
                ->setCentreScope(true)
                ->setTeacherScope(true)
                ->setMinValue(0)
                ->setMaxValue(365),
        );

        /** @var WarnUpcomingSanctionPrescriptionsHandler $handler */
        $handler       = self::getContainer()->get(WarnUpcomingSanctionPrescriptionsHandler::class);
        $this->handler = $handler;
    }

    public function testSendsOneDigestWhenASanctionIsWithinTheWarningWindow(): void
    {
        $world = $this->makeWorld('near');
        $this->persist($this->makeSanction($world, '-12 days'));
        $this->setDays($world['centre'], 14);

        ($this->handler)(new WarnUpcomingSanctionPrescriptionsMessage());

        self::assertEmailCount(1);
    }

    public function testSendsNothingWhenTheSanctionIsStillFarFromPrescribing(): void
    {
        $world = $this->makeWorld('far');
        $this->persist($this->makeSanction($world, '-3 days'));
        $this->setDays($world['centre'], 14);

        ($this->handler)(new WarnUpcomingSanctionPrescriptionsMessage());

        self::assertEmailCount(0);
    }

    public function testSendsNothingWhenSanctionPrescriptionIsDisabled(): void
    {
        $world = $this->makeWorld('off');
        $this->persist($this->makeSanction($world, '-400 days'));

        ($this->handler)(new WarnUpcomingSanctionPrescriptionsMessage());

        self::assertEmailCount(0);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher}
     */
    private function makeWorld(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('43000' . substr(md5($suffix . 'x'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
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
}
