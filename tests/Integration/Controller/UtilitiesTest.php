<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\PrintableCalendar;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** Utilidades: la página de inicio, su entrada en el menú y quién puede entrar. */
final class UtilitiesTest extends ControllerTestCase
{
    private static int $seq = 0;

    public function testTheHubListsBothToolsAndCountsTheOwnCalendarsOfTheYear(): void
    {
        [$centre, $year, $teacher] = $this->world();
        $other = $this->teacher('otro');
        $mine  = new PrintableCalendar($centre, $year, $teacher, 'FCT');
        $their = new PrintableCalendar($centre, $year, $other, 'De otro');
        $this->persist($other, $mine, $their);

        $this->loginAs($teacher, $centre);
        $crawler = $this->client->request('GET', '/utilidades');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('1 guardado este curso.', $html);
        self::assertCount(1, $crawler->filter('a[href="/utilidades/generador-calendarios"]'));
        self::assertCount(1, $crawler->filter('a[href="/utilidades/calculadora-fechas"]'));
    }

    public function testTheSidebarLinksToUtilities(): void
    {
        [$centre, , $teacher] = $this->world();

        $this->loginAs($teacher, $centre);
        $crawler = $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav a[href="/utilidades"]'));
    }

    public function testNonTeachingStaffCannotUseThem(): void
    {
        [$centre, $year] = $this->world();
        $paco = $this->teacher('paco.util')->setNonTeaching(true);
        $paco->addAcademicYear($year);
        $this->persist($paco);
        $centre->addNonTeachingStaff($paco);
        $this->flush();

        $this->loginAs($paco, $centre);
        foreach (['/utilidades', '/utilidades/generador-calendarios', '/utilidades/calculadora-fechas'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username . '.' . ++self::$seq);
    }

    /** @return array{EducationalCentre, AcademicYear, Teacher} */
    private function world(): array
    {
        $seq    = ++self::$seq;
        $centre = (new EducationalCentre())->setCode("4700{$seq}77")->setName('IES Utilidades')->setCity('Jaén');
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher = $this->teacher('docente.util');
        $teacher->addAcademicYear($year);
        $this->persist($centre, $year, $teacher);

        return [$centre, $year, $teacher];
    }
}
