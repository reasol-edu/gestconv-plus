<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Activity;
use App\Entity\ActivityAttachment;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;

class KioskModeSubscriberTest extends ControllerTestCase
{
    public function testAttachmentDownloadIsAllowedInsideKioskMode(): void
    {
        [$admin, $centre, $year] = $this->makeScenario();
        $attachment = $this->makeAbsenceWithAttachment($admin, $year);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/calendario/tablon');
        self::assertResponseIsSuccessful();

        $activity = $attachment->getActivity();
        $this->client->request(
            'GET',
            '/ausencias/' . $activity->getAbsence()->getId()->toRfc4122()
                . '/actividades/' . $activity->getId()->toRfc4122()
                . '/adjuntos/' . $attachment->getId()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
    }

    public function testUnrelatedRouteIsRedirectedToBoardInsideKioskMode(): void
    {
        [$admin, $centre] = $this->makeScenario();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/calendario/tablon');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/perfil');

        self::assertResponseRedirects('/calendario/tablon');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear} */
    private function makeScenario(): array
    {
        $admin = (new Teacher(new PersonName('Test', 'Admin')))
            ->setUsername('kiosk.admin.' . uniqid('', false))
            ->setAdmin(true);
        $centre = (new EducationalCentre())->setCode((string) random_int(10000000, 99999999))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($admin);

        $this->persist($admin, $centre, $year);

        return [$admin, $centre, $year];
    }

    private function makeAbsenceWithAttachment(Teacher $teacher, AcademicYear $year): ActivityAttachment
    {
        $today = new \DateTimeImmutable('today');
        $dow   = ((int) $today->format('N')) - 1;

        $timeSlot = (new TimeSlot())
            ->setName('Tramo 1')
            ->setDayOfWeek($dow)
            ->setStartTime(new \DateTimeImmutable('08:00'))
            ->setEndTime(new \DateTimeImmutable('09:00'))
            ->setAcademicYear($year);

        $absence = (new Absence())
            ->setTeacher($teacher)
            ->setAcademicYear($year)
            ->setStartDate($today)
            ->setEndDate($today);

        $activity = (new Activity())
            ->setAbsence($absence)
            ->setDate($today)
            ->setTimeSlot($timeSlot)
            ->setDescription('<p>Actividad de prueba.</p>');

        $attachment = new ActivityAttachment($activity, 'notas.txt', 'text/plain', 4, 'test');
        $activity->addAttachment($attachment);

        $this->persist($timeSlot, $absence, $activity, $attachment);

        return $attachment;
    }
}
