<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class IncidentBehaviorControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/admin/centros/' . $centre->getId()->toRfc4122() . '/conductas');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/admin/centros/' . $centre->getId()->toRfc4122() . '/conductas');

        self::assertResponseStatusCodeSame(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreatePostAddsBehaviorAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/admin/centros/' . $centreId . '/conductas');
        $token    = $crawler->filter('form [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId . '/conductas/nueva', [
            '_token'  => $token,
            'name'    => 'Conducta de prueba',
            'serious' => '0',
        ]);

        self::assertResponseRedirects('/admin/centros/' . $centreId . '/conductas');

        $this->em->clear();
        $behaviors = $this->em->getRepository(IncidentBehavior::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $behaviors);
        self::assertSame('Conducta de prueba', $behaviors[0]->getName());
    }

    public function testCreateWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/admin/centros/' . $centre->getId()->toRfc4122() . '/conductas/nueva', [
            '_token' => 'invalid-token',
            'name'   => 'Conducta inválida',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateWithEmptyNameRendersErrorInPage(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/admin/centros/' . $centreId . '/conductas');
        $token    = $crawler->filter('form [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId . '/conductas/nueva', [
            '_token' => $token,
            'name'   => '',
        ]);

        // Flash error → redirect → renders index with no new behavior
        $this->em->clear();
        $behaviors = $this->em->getRepository(IncidentBehavior::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(0, $behaviors);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$cadmin, $centre, $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $this->client->request(
            'GET',
            '/admin/centros/' . $centre->getId()->toRfc4122() . '/conductas/' . $behavior->getId()->toRfc4122() . '/editar'
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId  = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/admin/centros/' . $centreId . '/conductas/' . $behaviorId . '/editar');
        $token     = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId . '/conductas/' . $behaviorId . '/editar', [
            '_token'  => $token,
            'name'    => 'Nombre actualizado',
            'serious' => '1',
        ]);

        self::assertResponseRedirects('/admin/centros/' . $centreId . '/conductas');

        $this->em->clear();
        $updated = $this->em->find(IncidentBehavior::class, $behavior->getId());
        self::assertNotNull($updated);
        self::assertSame('Nombre actualizado', $updated->getName());
        self::assertTrue($updated->isSerious());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesBehaviorAndRedirects(): void
    {
        [$cadmin, $centre, $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/admin/centros/' . $centreId . '/conductas');
        $token      = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId . '/conductas/' . $behaviorId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/centros/' . $centreId . '/conductas');

        $this->em->clear();
        self::assertNull($this->em->find(IncidentBehavior::class, $behavior->getId()));
    }

    // ── toggleActive ──────────────────────────────────────────────────────────

    public function testToggleActiveFlipsFlag(): void
    {
        [$cadmin, $centre, $behavior] = $this->makeScenarioWithBehavior();
        self::assertTrue($behavior->isActive());
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/admin/centros/' . $centreId . '/conductas');
        self::assertResponseIsSuccessful();

        $tokenNodes = $crawler->filter('form[action$="/activar"] [name="_token"]');
        self::assertGreaterThan(0, $tokenNodes->count(), 'Toggle-active form not found in page');
        $token = $tokenNodes->first()->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId . '/conductas/' . $behaviorId . '/activar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(IncidentBehavior::class, $behavior->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive());
    }

    // ── moveUp / moveDown ─────────────────────────────────────────────────────

    public function testMoveUpSwapsPositionWithPrevious(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $b1 = $this->makeBehavior($centre, 'Primera', 0);
        $b2 = $this->makeBehavior($centre, 'Segunda', 1);
        $this->persist($b1, $b2);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/admin/centros/' . $centreId . '/conductas');
        // The move-up button is on the second behavior (b2)
        $tokens   = $crawler->filter('form[action$="/subir"] [name="_token"]');
        $token    = $tokens->count() > 0 ? $tokens->first()->attr('value') : '';

        $this->client->request('POST', '/admin/centros/' . $centreId . '/conductas/' . $b2->getId()->toRfc4122() . '/subir', [
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

    /** @return array{0: Teacher, 1: EducationalCentre, 2: IncidentBehavior} */
    private function makeScenarioWithBehavior(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $behavior          = $this->makeBehavior($centre, 'Conducta inicial', 0);
        $this->persist($behavior);

        return [$cadmin, $centre, $behavior];
    }

    private function makeBehavior(EducationalCentre $centre, string $name, int $position, bool $active = true): IncidentBehavior
    {
        return (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position)
            ->setSerious(false)
            ->setActive($active);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
