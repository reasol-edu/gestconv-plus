<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TimeSlotControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.ts');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexReturns404ForUnknownCentre(): void
    {
        [$cadmin] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/00000000-0000-0000-0000-000000000000/tramos-horarios');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexPreselectsTimeSlotFromQueryParam(): void
    {
        [$cadmin, $centre, , $slot] = $this->makeScenarioWithSlot();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios?slot=' . $slot->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="1ª hora"]');
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function testExportReturnsJsonWithTimeSlots(): void
    {
        [$cadmin, $centre, , $slot] = $this->makeScenarioWithSlot();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/export');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($slot->getName(), $data['time_slots'][0]['name']);
        self::assertSame($slot->getDayOfWeek(), $data['time_slots'][0]['day_of_week']);
    }

    public function testExportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.ts.export');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/export');

        self::assertResponseStatusCodeSame(403);
    }

    // ── pdf ───────────────────────────────────────────────────────────────────

    public function testPdfReturnsAPdfDocument(): void
    {
        [$cadmin, $centre] = $this->makeScenarioWithSlot();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testPdfReturns404WithoutActiveYear(): void
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.ts.pdf.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('43' . substr(uniqid('', false), 0, 6))->setName('IES Sin Curso')->setCity('Sevilla');
        $centre->addAdmin($cadmin);
        $this->persist($cadmin, $centre);
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPdfIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.ts.pdf');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/importar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="json"]');
    }

    public function testImportPostWithValidJsonCreatesTimeSlotsAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tramos-horarios/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'time_slots' => [
                ['name' => '1ª hora', 'day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '08:55'],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/tramos-horarios/importar', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tramos-horarios');

        $this->em->clear();
        $slots = $this->em->getRepository(TimeSlot::class)->findAll();
        self::assertCount(1, $slots);
        self::assertSame('1ª hora', $slots[0]->getName());
    }

    public function testImportPostWithInvalidJsonShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tramos-horarios/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, 'not json');
        $file = new UploadedFile($tmpFile, 'tramos.json', 'application/json', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/tramos-horarios/importar', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(TimeSlot::class)->findAll());
    }

    public function testImportPostWithoutFileShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tramos-horarios/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tramos-horarios/importar', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testImportPostWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $file = $this->makeJsonUploadFile(['time_slots' => []]);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/importar', [
            '_token' => 'invalid-token',
        ], ['json' => $file]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportPostWithReplaceExistingRemovesPreviousSlots(): void
    {
        [$cadmin, $centre, , $oldSlot] = $this->makeScenarioWithSlot();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tramos-horarios/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'time_slots' => [
                ['name' => 'Recreo', 'day_of_week' => 0, 'start_time' => '11:00', 'end_time' => '11:30'],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/tramos-horarios/importar', [
            '_token'           => $token,
            'replace_existing' => '1',
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tramos-horarios');

        $this->em->clear();
        self::assertNull($this->em->find(TimeSlot::class, $oldSlot->getId()));
        $slots = $this->em->getRepository(TimeSlot::class)->findAll();
        self::assertCount(1, $slots);
        self::assertSame('Recreo', $slots[0]->getName());
    }

    public function testImportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.ts.import');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tramos-horarios/importar');

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.ts.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('43' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear, 3: TimeSlot} */
    private function makeScenarioWithSlot(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $year = $centre->getActiveAcademicYear();
        self::assertNotNull($year);

        $slot = (new TimeSlot())
            ->setAcademicYear($year)
            ->setName('1ª hora')
            ->setDayOfWeek(0)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', '08:00'))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', '08:55'));
        $this->persist($slot);

        return [$cadmin, $centre, $year, $slot];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    /** @param array<string, mixed> $data */
    private function makeJsonUploadFile(array $data): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, (string) json_encode($data));

        return new UploadedFile($tmpFile, 'tramos.json', 'application/json', null, true);
    }
}
