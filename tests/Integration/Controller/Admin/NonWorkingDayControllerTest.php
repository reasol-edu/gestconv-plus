<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class NonWorkingDayControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.nwd');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexReturns404ForUnknownCentre(): void
    {
        [$cadmin] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/00000000-0000-0000-0000-000000000000/dias-no-lectivos');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexReturns404WithoutActiveYear(): void
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.nwd.noyear.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('45' . substr(uniqid('', false), 0, 6))->setName('IES Sin Curso')->setCity('Sevilla');
        $centre->addAdmin($cadmin);
        $this->persist($cadmin, $centre);
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos');

        self::assertResponseStatusCodeSame(404);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddCreatesNonWorkingDayAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/nuevo', [
            '_token'      => $token,
            'date'        => '2025-10-13',
            'description' => 'Día de la Hispanidad',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $days = $this->em->getRepository(NonWorkingDay::class)->findAll();
        self::assertCount(1, $days);
        self::assertSame('2025-10-13', $days[0]->getDate()->format('Y-m-d'));
        self::assertSame('Día de la Hispanidad', $days[0]->getDescription());
    }

    public function testAddWithoutDateShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/nuevo', [
            '_token' => $token,
            'date'   => '',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(NonWorkingDay::class)->findAll());
    }

    public function testAddDuplicateDateIsRejected(): void
    {
        [$cadmin, $centre, $year] = $this->makeScenario();
        $this->persist($this->makeDay($year, '2025-10-13'));
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/nuevo', [
            '_token' => $token,
            'date'   => '2025-10-13',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(NonWorkingDay::class)->findAll());
    }

    public function testAddWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos/nuevo', [
            '_token' => 'token-invalido',
            'date'   => '2025-10-13',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$cadmin, $centre, $year] = $this->makeScenario();
        $day = $this->makeDay($year, '2025-10-13');
        $this->persist($day);
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos/' . $day->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $year] = $this->makeScenario();
        $day = $this->makeDay($year, '2025-10-13');
        $this->persist($day);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $dayId    = $day->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos/' . $dayId . '/editar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/' . $dayId . '/editar', [
            '_token'      => $token,
            'date'        => '2025-12-08',
            'description' => 'Puente de la Constitución',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(NonWorkingDay::class, $day->getId());
        self::assertSame('2025-12-08', $updated->getDate()->format('Y-m-d'));
        self::assertSame('Puente de la Constitución', $updated->getDescription());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesNonWorkingDay(): void
    {
        [$cadmin, $centre, $year] = $this->makeScenario();
        $day = $this->makeDay($year, '2025-10-13');
        $this->persist($day);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $dayId    = $day->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos');
        $token    = $crawler->filter('form[action*="/dias-no-lectivos/' . $dayId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/' . $dayId . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNull($this->em->find(NonWorkingDay::class, $day->getId()));
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos/importar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="ics"]');
    }

    public function testImportPostWithValidIcsCreatesNonWorkingDaysAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeIcsUploadFile([
            ['20251013', 'Día de la Hispanidad'],
            ['20251208', 'Puente de la Constitución'],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/importar', [
            '_token' => $token,
        ], ['ics' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/dias-no-lectivos');

        $this->em->clear();
        $days = $this->em->getRepository(NonWorkingDay::class)->findAll();
        self::assertCount(2, $days);
    }

    public function testImportPostASecondTimeReportsExistingEntries(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $events   = [['20251013', 'Día de la Hispanidad']];

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos/importar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/importar', [
            '_token' => $token,
        ], ['ics' => $this->makeIcsUploadFile($events)]);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos/importar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/importar', [
            '_token' => $token,
        ], ['ics' => $this->makeIcsUploadFile($events)]);
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(NonWorkingDay::class)->findAll());
    }

    public function testImportPostWithoutFileShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/dias-no-lectivos/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/dias-no-lectivos/importar', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testImportPostWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $file = $this->makeIcsUploadFile([['20251013', 'Día de la Hispanidad']]);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos/importar', [
            '_token' => 'invalid-token',
        ], ['ics' => $file]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.nwd.import');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/dias-no-lectivos/importar');

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.nwd.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('45' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre, $year];
    }

    private function makeDay(AcademicYear $year, string $date, ?string $description = null): NonWorkingDay
    {
        return (new NonWorkingDay())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable($date))
            ->setDescription($description);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    /** @param list<array{0: string, 1: string}> $events */
    private function makeIcsUploadFile(array $events): UploadedFile
    {
        $lines = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Test//Test//ES"];

        foreach ($events as $i => [$date, $summary]) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $i . '@test';
            $lines[] = 'DTSTART;VALUE=DATE:' . $date;
            $lines[] = 'SUMMARY:' . $summary;
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, implode("\r\n", $lines));

        return new UploadedFile($tmpFile, 'calendario.ics', 'text/calendar', null, true);
    }
}
