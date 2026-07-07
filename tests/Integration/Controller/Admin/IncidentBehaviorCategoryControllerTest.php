<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class IncidentBehaviorCategoryControllerTest extends ControllerTestCase
{
    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/conductas/categorias/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testCreateGetIsAccessibleToCentreAdminWithoutGlobalRoleAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $pureCentreAdmin = $this->makeTeacher('pure.cadmin.' . uniqid('', false));
        $centre->addAdmin($pureCentreAdmin);
        $this->persist($pureCentreAdmin);
        $this->loginAs($pureCentreAdmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/conductas/categorias/nueva');

        self::assertResponseIsSuccessful();
    }

    public function testCreatePostAddsCategoryAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/conductas/categorias/nueva');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/nueva', [
            '_token'  => $token,
            'name'    => 'Categoría de prueba',
            'serious' => '0',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/conductas');

        $this->em->clear();
        $categories = $this->em->getRepository(IncidentBehaviorCategory::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $categories);
        self::assertSame('Categoría de prueba', $categories[0]->getName());
        self::assertFalse($categories[0]->isSerious());
    }

    public function testCreateSeriousCategoryIsMarkedSerious(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/conductas/categorias/nueva');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/nueva', [
            '_token'  => $token,
            'name'    => 'Conductas graves',
            'serious' => '1',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $categories = $this->em->getRepository(IncidentBehaviorCategory::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $categories);
        self::assertTrue($categories[0]->isSerious());
    }

    public function testCreateWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/conductas/categorias/nueva', [
            '_token' => 'invalid',
            'name'   => 'Categoría inválida',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $this->client->request(
            'GET',
            '/centro/' . $centre->getId()->toRfc4122() . '/conductas/categorias/' . $category->getId()->toRfc4122() . '/editar'
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/conductas/categorias/' . $categoryId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/' . $categoryId . '/editar', [
            '_token'  => $token,
            'name'    => 'Nombre actualizado',
            'serious' => '1',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/conductas');

        $this->em->clear();
        $updated = $this->em->find(IncidentBehaviorCategory::class, $category->getId());
        self::assertNotNull($updated);
        self::assertSame('Nombre actualizado', $updated->getName());
        self::assertTrue($updated->isSerious());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesCategoryAndRedirects(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/conductas');
        $token      = $crawler->filter('form[action$="categorias/' . $categoryId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/' . $categoryId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/conductas');

        $this->em->clear();
        self::assertNull($this->em->find(IncidentBehaviorCategory::class, $category->getId()));
    }

    // ── moveUp ────────────────────────────────────────────────────────────────

    public function testMoveUpSwapsCategoryPositions(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $cat1 = $this->makeCategory($centre, 'Primera', 0);
        $cat2 = $this->makeCategory($centre, 'Segunda', 1);
        $this->persist($cat1, $cat2);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/conductas');
        $tokens   = $crawler->filter('form[action*="categorias"][action$="/subir"] [name="_token"]');
        $token    = $tokens->count() > 0 ? $tokens->first()->attr('value') : '';

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/' . $cat2->getId()->toRfc4122() . '/subir', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $r1 = $this->em->find(IncidentBehaviorCategory::class, $cat1->getId());
        $r2 = $this->em->find(IncidentBehaviorCategory::class, $cat2->getId());
        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertGreaterThan($r2->getPosition(), $r1->getPosition());
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
        $category          = $this->makeCategory($centre, 'Contrarias', 0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    private function makeCategory(EducationalCentre $centre, string $name, int $position, bool $serious = false): IncidentBehaviorCategory
    {
        return (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setSerious($serious)
            ->setPosition($position);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
