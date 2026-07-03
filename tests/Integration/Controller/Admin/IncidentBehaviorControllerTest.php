<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class IncidentBehaviorControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexIsAccessibleToCentreAdminWithoutGlobalRoleAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $pureCentreAdmin = $this->makeTeacher('pure.cadmin.' . uniqid('', false));
        $centre->addAdmin($pureCentreAdmin);
        $this->persist($pureCentreAdmin);
        $this->loginAs($pureCentreAdmin);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas');

        self::assertResponseIsSuccessful();
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function testExportReturnsJsonWithBehaviors(): void
    {
        [$cadmin, $centre, , $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas/export');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($centre->getName(), $data['centre']);
        self::assertSame($behavior->getName(), $data['categories'][0]['behaviors'][0]['name']);
    }

    public function testExportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.export');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas/export');

        self::assertResponseStatusCodeSame(403);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="json"]');
    }

    public function testImportPostWithValidJsonCreatesBehaviorsAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/conductas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'categories' => [
                ['name' => 'Faltas leves', 'serious' => false, 'behaviors' => [
                    ['name' => 'Llegar tarde', 'active' => true],
                ]],
            ],
        ]);

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseRedirects('/centros/' . $centreId . '/conductas');

        $this->em->clear();
        $behaviors = $this->em->getRepository(IncidentBehavior::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $behaviors);
        self::assertSame('Llegar tarde', $behaviors[0]->getName());
    }

    public function testImportPostWithInvalidJsonShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/conductas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, 'not json');
        $file = new UploadedFile($tmpFile, 'behaviors.json', 'application/json', null, true);

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('div', 'JSON válido');
    }

    public function testImportPostWithReplaceExistingRemovesPreviousCategory(): void
    {
        [$cadmin, $centre, $oldCategory, $oldBehavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/conductas/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'categories' => [
                ['name' => 'Faltas nuevas', 'serious' => false, 'behaviors' => [
                    ['name' => 'Conducta nueva', 'active' => true],
                ]],
            ],
        ]);

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/import', [
            '_token'           => $token,
            'replace_existing' => '1',
        ], ['json' => $file]);

        self::assertResponseRedirects('/centros/' . $centreId . '/conductas');

        $this->em->clear();
        self::assertNull($this->em->find(IncidentBehaviorCategory::class, $oldCategory->getId()));
        self::assertNull($this->em->find(IncidentBehavior::class, $oldBehavior->getId()), 'La conducta antigua debe eliminarse en cascada junto con su categoría.');
        $categories = $this->em->getRepository(IncidentBehaviorCategory::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $categories);
        self::assertSame('Faltas nuevas', $categories[0]->getName());
    }

    public function testImportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.import');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centros/' . $centre->getId()->toRfc4122() . '/conductas/import');

        self::assertResponseStatusCodeSame(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreatePostAddsBehaviorAndRedirects(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/conductas');
        $token    = $crawler->filter('form[action$="conductas/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/nueva', [
            '_token'      => $token,
            'name'        => 'Conducta de prueba',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/centros/' . $centreId . '/conductas');

        $this->em->clear();
        $behaviors = $this->em->getRepository(IncidentBehavior::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $behaviors);
        self::assertSame('Conducta de prueba', $behaviors[0]->getName());
    }

    public function testCreateWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/centros/' . $centre->getId()->toRfc4122() . '/conductas/nueva', [
            '_token'      => 'invalid-token',
            'name'        => 'Conducta inválida',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateWithEmptyNameDoesNotPersist(): void
    {
        [$cadmin, $centre] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/conductas');
        $token    = $crawler->filter('form[action$="conductas/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/nueva', [
            '_token' => $token,
            'name'   => '',
        ]);

        $this->em->clear();
        $behaviors = $this->em->getRepository(IncidentBehavior::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(0, $behaviors);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$cadmin, $centre, , $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $this->client->request(
            'GET',
            '/centros/' . $centre->getId()->toRfc4122() . '/conductas/' . $behavior->getId()->toRfc4122() . '/editar'
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $category, $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centros/' . $centreId . '/conductas/' . $behaviorId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/' . $behaviorId . '/editar', [
            '_token'      => $token,
            'name'        => 'Nombre actualizado',
            'category_id' => $category->getId()->toRfc4122(),
            'active'      => '1',
        ]);

        self::assertResponseRedirects('/centros/' . $centreId . '/conductas');

        $this->em->clear();
        $updated = $this->em->find(IncidentBehavior::class, $behavior->getId());
        self::assertNotNull($updated);
        self::assertSame('Nombre actualizado', $updated->getName());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesBehaviorAndRedirects(): void
    {
        [$cadmin, $centre, , $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centros/' . $centreId . '/conductas');
        $token      = $crawler->filter('form[action$="' . $behaviorId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/' . $behaviorId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centros/' . $centreId . '/conductas');

        $this->em->clear();
        self::assertNull($this->em->find(IncidentBehavior::class, $behavior->getId()));
    }

    // ── toggleActive ──────────────────────────────────────────────────────────

    public function testToggleActiveFlipsFlag(): void
    {
        [$cadmin, $centre, , $behavior] = $this->makeScenarioWithBehavior();
        self::assertTrue($behavior->isActive());
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/centros/' . $centreId . '/conductas');
        self::assertResponseIsSuccessful();

        $tokenNodes = $crawler->filter('form[action$="/activar"] [name="_token"]');
        self::assertGreaterThan(0, $tokenNodes->count(), 'Toggle-active form not found in page');
        $token = $tokenNodes->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/' . $behaviorId . '/activar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(IncidentBehavior::class, $behavior->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive());
    }

    // ── moveUp ────────────────────────────────────────────────────────────────

    public function testMoveUpSwapsPositionWithPrevious(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $b1 = $this->makeBehavior($centre, $category, 'Primera', 0);
        $b2 = $this->makeBehavior($centre, $category, 'Segunda', 1);
        $this->persist($b1, $b2);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/conductas');
        $tokens   = $crawler->filter('form[action$="/subir"] [name="_token"]');
        $token    = $tokens->count() > 0 ? $tokens->first()->attr('value') : '';

        $this->client->request('POST', '/centros/' . $centreId . '/conductas/' . $b2->getId()->toRfc4122() . '/subir', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $refreshedB1 = $this->em->find(IncidentBehavior::class, $b1->getId());
        $refreshedB2 = $this->em->find(IncidentBehavior::class, $b2->getId());
        self::assertNotNull($refreshedB1);
        self::assertNotNull($refreshedB2);
        self::assertGreaterThan($refreshedB2->getPosition(), $refreshedB1->getPosition());
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

    /** @return array{0: Teacher, 1: EducationalCentre, 2: IncidentBehaviorCategory} */
    private function makeScenarioWithCategory(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $category          = $this->makeCategory($centre, 'Contrarias', false, 0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: IncidentBehaviorCategory, 3: IncidentBehavior} */
    private function makeScenarioWithBehavior(): array
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $behavior                     = $this->makeBehavior($centre, $category, 'Conducta inicial', 0);
        $this->persist($behavior);

        return [$cadmin, $centre, $category, $behavior];
    }

    private function makeCategory(EducationalCentre $centre, string $name, bool $serious, int $position): IncidentBehaviorCategory
    {
        return (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setSerious($serious)
            ->setPosition($position);
    }

    private function makeBehavior(
        EducationalCentre $centre,
        IncidentBehaviorCategory $category,
        string $name,
        int $position,
        bool $active = true,
    ): IncidentBehavior {
        return (new IncidentBehavior())
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

        return new UploadedFile($tmpFile, 'behaviors.json', 'application/json', null, true);
    }
}
