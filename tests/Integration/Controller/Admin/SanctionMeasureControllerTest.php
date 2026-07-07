<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SanctionMeasureControllerTest extends ControllerTestCase
{
    // ── export ────────────────────────────────────────────────────────────────

    public function testExportReturnsJsonWithMeasures(): void
    {
        [$cadmin, $centre, , $measure] = $this->makeScenarioWithMeasure();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/sanciones/medidas/export');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($centre->getName(), $data['centre']);
        self::assertSame($measure->getName(), $data['categories'][0]['measures'][0]['name']);
    }

    public function testExportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.export');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/sanciones/medidas/export');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexIsAccessibleToCentreAdminWithoutGlobalRoleAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $pureCentreAdmin = $this->makeTeacher('pure.cadmin.' . uniqid('', false));
        $centre->addAdmin($pureCentreAdmin);
        $this->persist($pureCentreAdmin);
        $this->loginAs($pureCentreAdmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/sanciones/medidas');

        self::assertResponseIsSuccessful();
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/sanciones/medidas/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="json"]');
    }

    public function testImportPostWithValidJsonCreatesMeasuresAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/sanciones/medidas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'categories' => [
                ['name' => 'Expulsión', 'measures' => [
                    ['name' => 'Expulsión de 3 días', 'has_date_range' => true, 'active' => true],
                ]],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/sanciones/medidas/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/sanciones/medidas');

        $this->em->clear();
        $measures = $this->em->getRepository(SanctionMeasure::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $measures);
        self::assertSame('Expulsión de 3 días', $measures[0]->getName());
    }

    public function testImportPostWithInvalidJsonShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/sanciones/medidas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, 'not json');
        $file = new UploadedFile($tmpFile, 'measures.json', 'application/json', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/sanciones/medidas/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('div', 'JSON válido');
    }

    public function testImportPostWithReplaceExistingRemovesPreviousCategory(): void
    {
        [$cadmin, $centre, $oldCategory, $oldMeasure] = $this->makeScenarioWithMeasure();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/sanciones/medidas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'categories' => [
                ['name' => 'Categoría nueva', 'measures' => [
                    ['name' => 'Medida nueva', 'has_date_range' => false, 'active' => true],
                ]],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/sanciones/medidas/import', [
            '_token'           => $token,
            'replace_existing' => '1',
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/sanciones/medidas');

        $this->em->clear();
        self::assertNull($this->em->find(SanctionMeasureCategory::class, $oldCategory->getId()));
        self::assertNull($this->em->find(SanctionMeasure::class, $oldMeasure->getId()), 'La medida antigua debe eliminarse en cascada junto con su categoría.');
        $categories = $this->em->getRepository(SanctionMeasureCategory::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $categories);
        self::assertSame('Categoría nueva', $categories[0]->getName());
    }

    public function testImportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.import');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/sanciones/medidas/import');

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('41' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: SanctionMeasureCategory} */
    private function makeScenarioWithCategory(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $category          = (new SanctionMeasureCategory())->setEducationalCentre($centre)->setName('Sanciones')->setPosition(0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: SanctionMeasureCategory, 3: SanctionMeasure} */
    private function makeScenarioWithMeasure(): array
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $measure                      = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Medida inicial')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($measure);

        return [$cadmin, $centre, $category, $measure];
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

        return new UploadedFile($tmpFile, 'measures.json', 'application/json', null, true);
    }
}
