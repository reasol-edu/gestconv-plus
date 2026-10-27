<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\GlobalSettingValue;
use App\Entity\ResourceCategory;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SharedResource;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Integration\ResourceBookingScenarioTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ResourceCatalogControllerTest extends ControllerTestCase
{
    use ResourceBookingScenarioTrait;

    public function testIndexListsCategoriesAndResourcesToAdmin(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Dependencias');
        self::assertSelectorTextContains('body', 'Aula de informática');
        self::assertSelectorTextContains('body', 'Marta Responsable');
    }

    public function testNonAdminIsDenied(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerOfACategoryCannotEditTheCatalog(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['manager'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateCategoryRedirectsToItsEditForm(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', $this->url($centreId));
        $token   = $crawler->filter('form[action$="/categorias/nueva"] [name="_token"]')->attr('value');

        $this->client->request('POST', $this->url($centreId) . '/categorias/nueva', [
            '_token' => $token,
            'name'   => 'Carros de portátiles',
            'color'  => '#16a34a',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/editar', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $names = array_map(static fn (ResourceCategory $c): string => $c->getName(), $this->em->getRepository(ResourceCategory::class)->findAll());
        self::assertContains('Carros de portátiles', $names);
    }

    public function testEditCategorySavesRulesAndManagers(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId   = $w['centre']->getId()->toRfc4122();
        $categoryId = $w['category']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', $this->url($centreId) . '/categorias/' . $categoryId . '/editar');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form [name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->url($centreId) . '/categorias/' . $categoryId . '/editar', [
            '_token'            => $token,
            'name'              => 'Aulas',
            'color'             => '#ff0000',
            'active'            => '1',
            'restricted'        => '1',
            'requires_approval' => '1',
            'max_advance_days'  => '30',
            'managers'          => [$w['owner']->getId()->toRfc4122(), $w['other']->getId()->toRfc4122(), 'no-es-un-docente'],
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $category = $this->em->getRepository(ResourceCategory::class)->find($w['category']->getId());
        self::assertNotNull($category);
        self::assertSame('Aulas', $category->getName());
        self::assertSame('#ff0000', $category->getColor());
        self::assertTrue($category->isRestricted());
        self::assertTrue($category->requiresApproval());
        self::assertSame(30, $category->getMaxAdvanceDays());
        $managerNames = array_map(static fn ($t): string => $t->getName()->full(), $category->getManagers()->toArray());
        sort($managerNames);
        self::assertSame(['Ana Propietaria', 'Otro Docente'], $managerNames);
    }

    public function testBlankAdvanceMeansTheCentreSetting(): void
    {
        $w = $this->makeBookingWorld();
        $w['category']->setMaxAdvanceDays(10);
        $this->flush();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId   = $w['centre']->getId()->toRfc4122();
        $categoryId = $w['category']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', $this->url($centreId) . '/categorias/' . $categoryId . '/editar');
        $token   = $crawler->filter('form [name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->url($centreId) . '/categorias/' . $categoryId . '/editar', [
            '_token' => $token, 'name' => 'Dependencias', 'active' => '1', 'max_advance_days' => '',
        ]);

        $this->em->clear();
        $category = $this->em->getRepository(ResourceCategory::class)->find($w['category']->getId());
        self::assertNull($category?->getMaxAdvanceDays());
    }

    public function testCreateAndEditAResource(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId   = $w['centre']->getId()->toRfc4122();
        $categoryId = $w['category']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', $this->url($centreId));
        $token   = $crawler->filter('form[action$="/categorias/' . $categoryId . '/recursos/nuevo"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($centreId) . '/categorias/' . $categoryId . '/recursos/nuevo', [
            '_token' => $token, 'name' => 'Sala de profesores', 'location' => 'Planta baja',
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $created = null;
        foreach ($this->em->getRepository(SharedResource::class)->findAll() as $resource) {
            if ($resource->getName() === 'Sala de profesores') {
                $created = $resource;
            }
        }
        self::assertNotNull($created);
        self::assertSame('Planta baja', $created->getLocation());

        $resourceId = $created->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', $this->url($centreId) . '/recursos/' . $resourceId . '/editar');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form [name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->url($centreId) . '/recursos/' . $resourceId . '/editar', [
            '_token' => $token, 'name' => 'Sala de reuniones', 'category' => $categoryId, 'active' => '0', 'description' => 'Con proyector',
        ]);

        $this->em->clear();
        $edited = $this->em->getRepository(SharedResource::class)->find($created->getId());
        self::assertSame('Sala de reuniones', $edited?->getName());
        self::assertFalse($edited?->isActive());
        self::assertSame('Con proyector', $edited->getDescription());
    }

    public function testAResourceWithBookingsCannotBeDeleted(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-14', [$w['slots'][0][0]]);
        $this->loginAs($w['admin'], $w['centre']);
        $centreId   = $w['centre']->getId()->toRfc4122();
        $resourceId = $w['resource']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', $this->url($centreId));
        $token   = $crawler->filter('form[action$="/recursos/' . $resourceId . '/eliminar"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($centreId) . '/recursos/' . $resourceId . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'tiene reservas');

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(SharedResource::class)->find($w['resource']->getId()));
    }

    public function testAnUnusedCategoryIsDeletedWithItsResources(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId   = $w['centre']->getId()->toRfc4122();
        $categoryId = $w['category']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', $this->url($centreId));
        $token   = $crawler->filter('form[action$="/categorias/' . $categoryId . '/eliminar"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($centreId) . '/categorias/' . $categoryId . '/eliminar', ['_token' => $token]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ResourceCategory::class)->find($w['category']->getId()));
        self::assertNull($this->em->getRepository(SharedResource::class)->find($w['resource']->getId()));
    }

    public function testExportListsCategoriesWithManagersAndResources(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']->getId()->toRfc4122()) . '/export');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Dependencias', $data['categories'][0]['name']);
        self::assertSame([$w['manager']->getUsername()], $data['categories'][0]['managers']);
        self::assertSame('Aula de informática', $data['categories'][0]['resources'][0]['name']);
        self::assertSame('Planta 2', $data['categories'][0]['resources'][0]['location']);
    }

    public function testImportCreatesAndUpdatesByNameAndResolvesManagers(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();

        $this->importJson($centreId, [
            'categories' => [
                [
                    'name'              => 'dependencias',
                    'color'             => '#16a34a',
                    'restricted'        => true,
                    'requires_approval' => true,
                    'max_advance_days'  => 30,
                    'managers'          => [$w['other']->getUsername(), 'no.existe'],
                    'resources'         => [
                        ['name' => 'AULA DE INFORMÁTICA', 'location' => 'Planta 3'],
                        ['name' => 'Biblioteca', 'description' => 'Sala de lectura'],
                    ],
                ],
                ['name' => 'Carros de portátiles', 'active' => false, 'resources' => [['name' => 'Carro 1']]],
            ],
        ]);

        self::assertResponseRedirects($this->url($centreId));
        $this->em->clear();
        $categories = $this->em->getRepository(ResourceCategory::class)->findBy([], ['name' => 'ASC']);
        self::assertCount(2, $categories);
        [$carros, $dependencias] = $categories;
        self::assertSame('Dependencias', $dependencias->getName(), 'se empareja por nombre sin distinguir mayúsculas');
        self::assertTrue($dependencias->isRestricted());
        self::assertTrue($dependencias->requiresApproval());
        self::assertSame(30, $dependencias->getMaxAdvanceDays());
        self::assertCount(1, $dependencias->getManagers());
        self::assertSame($w['other']->getUsername(), $dependencias->getManagers()->first()->getUsername());
        self::assertFalse($carros->isActive());

        $resources = $this->em->getRepository(SharedResource::class)->findAll();
        self::assertCount(3, $resources);
        $names = array_map(static fn (SharedResource $r): string => $r->getName(), $resources);
        self::assertContains('Aula de informática', $names);
        self::assertNotContains('AULA DE INFORMÁTICA', $names);
    }

    public function testImportReplacingEmptiesTheCatalogFirst(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();

        $this->importJson($centreId, ['categories' => [['name' => 'Talleres', 'resources' => []]]], replace: true);

        $this->em->clear();
        $categories = $this->em->getRepository(ResourceCategory::class)->findAll();
        self::assertCount(1, $categories);
        self::assertSame('Talleres', $categories[0]->getName());
        self::assertCount(0, $this->em->getRepository(SharedResource::class)->findAll());
    }

    public function testImportRejectsInvalidJson(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();

        $this->importRaw($centreId, 'esto no es json');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'no es un JSON válido');
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(ResourceCategory::class)->findAll());
    }

    public function testImportAndExportAreDeniedToNonAdmins(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['manager'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();

        $this->client->request('GET', $this->url($centreId) . '/export');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->url($centreId) . '/import');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, mixed> $data */
    private function importJson(string $centreId, array $data, bool $replace = false): void
    {
        $this->importRaw($centreId, (string) json_encode($data), $replace);
    }

    private function importRaw(string $centreId, string $content, bool $replace = false): void
    {
        $crawler = $this->client->request('GET', $this->url($centreId) . '/import');
        $token   = $crawler->filter('form [name="_token"]')->attr('value');

        $path = tempnam(sys_get_temp_dir(), 'gestconv_resources_');
        file_put_contents($path, $content);

        $this->client->request(
            'POST',
            $this->url($centreId) . '/import',
            ['_token' => $token] + ($replace ? ['replace_existing' => '1'] : []),
            ['json' => new UploadedFile($path, 'recursos.json', 'application/json', null, true)],
        );
    }

    public function testTheCatalogIsNotFoundWhenTheSectionIsOff(): void
    {
        $w = $this->makeBookingWorld();
        $definition = (new SettingDefinition())->setKey('features.bookings')->setType(SettingType::Boolean)->setCategory('settings.category.features')
            ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue('false'));
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
    }

    private function url(string $centreId): string
    {
        return '/centro/' . $centreId . '/recursos';
    }
}
