<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class LocationOptionControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.loc');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexIsAccessibleToCentreAdminWithoutGlobalRoleAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $pureCentreAdmin = $this->makeTeacher('pure.cadmin.loc.' . uniqid('', false));
        $centre->addAdmin($pureCentreAdmin);
        $this->persist($pureCentreAdmin);
        $this->loginAs($pureCentreAdmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones');

        self::assertResponseIsSuccessful();
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function testExportReturnsJsonWithLocations(): void
    {
        [$cadmin, $centre, , $option] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones/export');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($centre->getName(), $data['centre']);
        self::assertSame($option->getName(), $data['categories'][0]['options'][0]['name']);
    }

    public function testExportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.loc.export');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones/export');

        self::assertResponseStatusCodeSame(403);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="json"]');
    }

    public function testImportPostWithValidJsonCreatesOptionsAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'categories' => [
                ['name' => 'General', 'options' => [
                    ['name' => 'En clase', 'active' => true],
                ]],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/ubicaciones');

        $this->em->clear();
        $options = $this->em->getRepository(LocationOption::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $options);
        self::assertSame('En clase', $options[0]->getName());
    }

    public function testImportPostWithInvalidJsonShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, 'not json');
        $file = new UploadedFile($tmpFile, 'locations.json', 'application/json', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('div', 'JSON válido');
    }

    public function testImportPostWithReplaceExistingRemovesPreviousCategory(): void
    {
        [$cadmin, $centre, $oldCategory, $oldOption] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'categories' => [
                ['name' => 'Nueva categoría', 'options' => [
                    ['name' => 'Ubicación nueva', 'active' => true],
                ]],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/import', [
            '_token'           => $token,
            'replace_existing' => '1',
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/ubicaciones');

        $this->em->clear();
        self::assertNull($this->em->find(LocationOptionCategory::class, $oldCategory->getId()));
        self::assertNull($this->em->find(LocationOption::class, $oldOption->getId()), 'La ubicación antigua debe eliminarse en cascada junto con su categoría.');
        $categories = $this->em->getRepository(LocationOptionCategory::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $categories);
        self::assertSame('Nueva categoría', $categories[0]->getName());
    }

    public function testImportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.loc.import');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones/import');

        self::assertResponseStatusCodeSame(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreatePostAddsOptionAndRedirects(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $token    = $crawler->filter('form[action$="ubicaciones/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/nueva', [
            '_token'      => $token,
            'name'        => 'Ubicación de prueba',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/ubicaciones');

        $this->em->clear();
        $options = $this->em->getRepository(LocationOption::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $options);
        self::assertSame('Ubicación de prueba', $options[0]->getName());
    }

    public function testCreateWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones/nueva', [
            '_token'      => 'invalid-token',
            'name'        => 'Ubicación inválida',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateWithEmptyNameDoesNotPersist(): void
    {
        [$cadmin, $centre] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $token    = $crawler->filter('form[action$="ubicaciones/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/nueva', [
            '_token' => $token,
            'name'   => '',
        ]);

        $this->em->clear();
        $options = $this->em->getRepository(LocationOption::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(0, $options);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$cadmin, $centre, , $option] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $this->client->request(
            'GET',
            '/centro/' . $centre->getId()->toRfc4122() . '/ubicaciones/' . $option->getId()->toRfc4122() . '/editar'
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $category, $option] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $optionId = $option->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/editar', [
            '_token'      => $token,
            'name'        => 'Nombre actualizado',
            'category_id' => $category->getId()->toRfc4122(),
            'active'      => '1',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/ubicaciones');

        $this->em->clear();
        $updated = $this->em->find(LocationOption::class, $option->getId());
        self::assertNotNull($updated);
        self::assertSame('Nombre actualizado', $updated->getName());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesOptionAndRedirects(): void
    {
        [$cadmin, $centre, , $option] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $optionId = $option->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $token    = $crawler->filter('form[action$="' . $optionId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/ubicaciones');

        $this->em->clear();
        self::assertNull($this->em->find(LocationOption::class, $option->getId()));
    }

    // ── toggleActive ──────────────────────────────────────────────────────────

    public function testToggleActiveFlipsFlag(): void
    {
        [$cadmin, $centre, , $option] = $this->makeScenarioWithOption();
        self::assertTrue($option->isActive());
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $optionId = $option->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        self::assertResponseIsSuccessful();

        $tokenNodes = $crawler->filter('form[action$="/activar"] [name="_token"]');
        self::assertGreaterThan(0, $tokenNodes->count(), 'Toggle-active form not found in page');
        $token = $tokenNodes->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/activar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(LocationOption::class, $option->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive());
    }

    // ── moveUp ────────────────────────────────────────────────────────────────

    public function testMoveUpSwapsPositionWithPrevious(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $o1 = $this->makeOption($centre, $category, 'Primera', 0);
        $o2 = $this->makeOption($centre, $category, 'Segunda', 1);
        $this->persist($o1, $o2);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $tokens   = $crawler->filter('form[action$="/subir"] [name="_token"]');
        $token    = $tokens->count() > 0 ? $tokens->first()->attr('value') : '';

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/' . $o2->getId()->toRfc4122() . '/subir', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $refreshedO1 = $this->em->find(LocationOption::class, $o1->getId());
        $refreshedO2 = $this->em->find(LocationOption::class, $o2->getId());
        self::assertNotNull($refreshedO1);
        self::assertNotNull($refreshedO2);
        self::assertGreaterThan($refreshedO2->getPosition(), $refreshedO1->getPosition());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.loc.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('43' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: LocationOptionCategory} */
    private function makeScenarioWithCategory(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $category          = $this->makeCategory($centre, 'General', 0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: LocationOptionCategory, 3: LocationOption} */
    private function makeScenarioWithOption(): array
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $option                       = $this->makeOption($centre, $category, 'Ubicación inicial', 0);
        $this->persist($option);

        return [$cadmin, $centre, $category, $option];
    }

    private function makeCategory(EducationalCentre $centre, string $name, int $position): LocationOptionCategory
    {
        return (new LocationOptionCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    private function makeOption(
        EducationalCentre $centre,
        LocationOptionCategory $category,
        string $name,
        int $position,
        bool $active = true,
    ): LocationOption {
        return (new LocationOption())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position)
            ->setActive($active);
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

        return new UploadedFile($tmpFile, 'locations.json', 'application/json', null, true);
    }
}
