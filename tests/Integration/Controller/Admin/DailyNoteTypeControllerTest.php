<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DailyNoteTypeControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tipos-notas');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.dnt');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tipos-notas');

        self::assertResponseStatusCodeSame(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreatePostAddsTypeWithOccurrencesAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas');
        $token    = $crawler->filter('form[action$="tipos-notas/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/nuevo', [
            '_token'                 => $token,
            'name'                   => 'Retraso',
            'occurrences_for_report' => '3',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tipos-notas');

        $this->em->clear();
        $types = $this->em->getRepository(DailyNoteType::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $types);
        self::assertSame('Retraso', $types[0]->getName());
        self::assertSame(3, $types[0]->getOccurrencesForReport());
    }

    public function testCreateWithEmptyNameDoesNotPersist(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas');
        $token    = $crawler->filter('form[action$="tipos-notas/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/nuevo', [
            '_token'                 => $token,
            'name'                   => '',
            'occurrences_for_report' => '0',
        ]);

        $this->em->clear();
        $types = $this->em->getRepository(DailyNoteType::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(0, $types);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $type] = $this->makeScenarioWithType();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $typeId   = $type->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas/' . $typeId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/' . $typeId . '/editar', [
            '_token'                 => $token,
            'name'                   => 'Uso del móvil',
            'occurrences_for_report' => '2',
            'active'                 => '1',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tipos-notas');

        $this->em->clear();
        $updated = $this->em->find(DailyNoteType::class, $type->getId());
        self::assertNotNull($updated);
        self::assertSame('Uso del móvil', $updated->getName());
        self::assertSame(2, $updated->getOccurrencesForReport());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesTypeAndRedirects(): void
    {
        [$cadmin, $centre, $type] = $this->makeScenarioWithType();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $typeId   = $type->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas');
        $token    = $crawler->filter('form[action$="' . $typeId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/' . $typeId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tipos-notas');

        $this->em->clear();
        self::assertNull($this->em->find(DailyNoteType::class, $type->getId()));
    }

    public function testDeleteInUseKeepsType(): void
    {
        [$cadmin, $centre, $type] = $this->makeScenarioWithType();

        $teacher = $this->makeTeacher('reporter.' . uniqid('', false));
        $year    = $centre->getActiveAcademicYear();
        $course  = (new \App\Entity\Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . uniqid('', false));
        $this->persist($teacher, $course, $group, $student);

        $note = (new DailyNote())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setType($type)
            ->setRegisteredBy($teacher);
        $this->persist($note);

        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $typeId   = $type->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas');
        $token    = $crawler->filter('form[action$="' . $typeId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/' . $typeId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tipos-notas');

        $this->em->clear();
        self::assertNotNull($this->em->find(DailyNoteType::class, $type->getId()));
    }

    // ── toggleActive / moveUp ────────────────────────────────────────────────

    public function testToggleActiveFlipsFlag(): void
    {
        [$cadmin, $centre, $type] = $this->makeScenarioWithType();
        self::assertTrue($type->isActive());
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $typeId   = $type->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas');
        $token   = $crawler->filter('form[action$="/activar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/' . $typeId . '/activar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(DailyNoteType::class, $type->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive());
    }

    // ── export/import ────────────────────────────────────────────────────────

    public function testExportReturnsJsonWithTypes(): void
    {
        [$cadmin, $centre, $type] = $this->makeScenarioWithType();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tipos-notas/export');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($type->getName(), $data['types'][0]['name']);
        self::assertSame(3, $data['types'][0]['occurrences_for_report']);
    }

    public function testImportPostWithValidJsonCreatesTypes(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/tipos-notas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'types' => [
                ['name' => 'Otros', 'active' => true, 'occurrences_for_report' => 0],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/tipos-notas/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/tipos-notas');

        $this->em->clear();
        $types = $this->em->getRepository(DailyNoteType::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $types);
        self::assertSame('Otros', $types[0]->getName());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('42' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: DailyNoteType} */
    private function makeScenarioWithType(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $type = (new DailyNoteType())
            ->setEducationalCentre($centre)
            ->setName('Retraso')
            ->setOccurrencesForReport(3)
            ->setPosition(0);
        $this->persist($type);

        return [$cadmin, $centre, $type];
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

        return new UploadedFile($tmpFile, 'types.json', 'application/json', null, true);
    }
}
