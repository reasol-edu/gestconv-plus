<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IssuePriority;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\IssuePrioritySeeder;
use App\Service\IssueSettingsExporter;
use App\Service\IssueSettingsImporter;
use App\Service\IssueSettingsImportOptions;
use App\Service\SettingsManager;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Configuración de las incidencias: tipos jerárquicos con responsables, prioridades, y su importación y exportación. */
class IssueSettingsControllerTest extends ControllerTestCase
{
    private static int $seq = 0;

    // ── modelo ────────────────────────────────────────────────────────────────

    public function testTheManagersOfANodeAreItsOwnAndThoseOfAllItsAncestors(): void
    {
        $w = $this->makeWorld();

        $managers = $w['electricidad']->getEffectiveManagers();

        self::assertEqualsCanonicalizing(
            [$w['ana']->getUsername(), $w['luis']->getUsername()],
            array_map(static fn (Teacher $t): string => $t->getUsername(), $managers),
            'Luis lo es de «Electricidad» y Ana de «Mantenimiento», del que cuelga',
        );
        self::assertSame('Mantenimiento › Electricidad', $w['electricidad']->getPath());
        self::assertCount(1, $w['mantenimiento']->getEffectiveManagers(), 'un ascendente no hereda de sus descendientes');
    }

    public function testANodeCannotHangUnderItselfOrUnderADescendant(): void
    {
        $w = $this->makeWorld();

        self::assertTrue($w['mantenimiento']->wouldCycleUnder($w['mantenimiento']));
        self::assertTrue($w['mantenimiento']->wouldCycleUnder($w['electricidad']));
        self::assertFalse($w['electricidad']->wouldCycleUnder($w['mantenimiento']));
        self::assertFalse($w['electricidad']->wouldCycleUnder(null));
    }

    public function testANewCentreGetsTheDefaultPrioritiesFromGreenToRed(): void
    {
        $w = $this->makeWorld(withPriorities: false);

        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($w['centre']);
        $this->flush();

        $priorities = $this->em->getRepository(IssuePriority::class)->findBy(['educationalCentre' => $w['centre']], ['position' => 'ASC']);
        self::assertSame(['Crítica', 'Alta', 'Media', 'Baja'], array_map(static fn (IssuePriority $p): string => $p->getName(), $priorities), 'de más a menos importante');
        self::assertSame([1, 7, 0, 0], array_map(static fn (IssuePriority $p): int => $p->getMaxDays(), $priorities));
        self::assertSame([0, 0, 0, 0], array_map(static fn (IssuePriority $p): int => $p->getRecommendedDays(), $priorities));
        self::assertSame(['#dc2626', '#ea580c', '#ca8a04', '#16a34a'], array_map(static fn (IssuePriority $p): string => $p->getColor(), $priorities));
    }

    // ── pantallas de configuración ────────────────────────────────────────────

    public function testTheSettingsPageListsTheTreeAndThePriorities(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mantenimiento', $crawler->html());
        self::assertStringContainsString('Electricidad', $crawler->html());
        self::assertStringContainsString('Luis Docente', $crawler->html());
        self::assertStringContainsString('Crítica', $crawler->html());
    }

    public function testOnlyCentreManagementCanOpenTheSettings(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['luis'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSettingsAreBlockedWhileTheModuleIsOff(): void
    {
        $w = $this->makeWorld();
        $this->switchOffTheModule();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatingAndEditingATypeWithManagersAndParent(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', $this->url($w['centre']));
        $token   = $crawler->filter('form[action$="/tipos/nuevo"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($w['centre']) . '/tipos/nuevo', ['_token' => $token, 'name' => 'Fontanería', 'parent' => $w['mantenimiento']->getId()->toRfc4122()]);
        self::assertResponseRedirects();

        $this->em->clear();
        $created = $this->findType('Fontanería');
        self::assertSame('Mantenimiento › Fontanería', $created->getPath());

        $edit  = $this->client->request('GET', $this->url($w['centre']) . '/tipos/' . $created->getId()->toRfc4122() . '/editar');
        $token = $edit->filter('form [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($w['centre']) . '/tipos/' . $created->getId()->toRfc4122() . '/editar', [
            '_token' => $token, 'name' => 'Fontanería y agua', 'parent' => '', 'active' => '0', 'managers' => [$w['luis']->getId()->toRfc4122()],
        ]);

        $this->em->clear();
        $edited = $this->findType('Fontanería y agua');
        self::assertNull($edited->getParent());
        self::assertFalse($edited->isActive());
        self::assertSame([$w['luis']->getUsername()], array_map(static fn (Teacher $t): string => $t->getUsername(), $edited->getManagers()->toArray()));
    }

    public function testAMoveThatWouldCreateACycleIsRefused(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $id    = $w['mantenimiento']->getId()->toRfc4122();
        $edit  = $this->client->request('GET', $this->url($w['centre']) . '/tipos/' . $id . '/editar');
        $token = $edit->filter('form [name="_token"]')->attr('value');

        $this->client->request('POST', $this->url($w['centre']) . '/tipos/' . $id . '/editar', [
            '_token' => $token, 'name' => 'Mantenimiento', 'parent' => $w['electricidad']->getId()->toRfc4122(), 'active' => '1',
        ]);

        $this->em->clear();
        self::assertNull($this->findType('Mantenimiento')->getParent(), 'no cambia');
    }

    public function testATypeWithChildrenCannotBeDeletedButALeafCan(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $crawler = $this->client->request('GET', $this->url($w['centre']));

        $parentToken = $crawler->filter('form[action$="/tipos/' . $w['mantenimiento']->getId()->toRfc4122() . '/eliminar"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($w['centre']) . '/tipos/' . $w['mantenimiento']->getId()->toRfc4122() . '/eliminar', ['_token' => $parentToken]);
        $this->em->clear();
        self::assertNotNull($this->findType('Mantenimiento'), 'tiene un tipo debajo');

        $leafToken = $crawler->filter('form[action$="/tipos/' . $w['electricidad']->getId()->toRfc4122() . '/eliminar"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($w['centre']) . '/tipos/' . $w['electricidad']->getId()->toRfc4122() . '/eliminar', ['_token' => $leafToken]);
        $this->em->clear();
        self::assertNull($this->em->getRepository(IssueType::class)->findOneBy(['name' => 'Electricidad']));
    }

    public function testPrioritiesCanBeEditedAndReorderedAndKeepAContiguousOrder(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);
        $media = $this->findPriority('Media');

        $edit  = $this->client->request('GET', $this->url($w['centre']) . '/prioridades/' . $media->getId()->toRfc4122() . '/editar');
        $token = $edit->filter('form [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($w['centre']) . '/prioridades/' . $media->getId()->toRfc4122() . '/editar', [
            '_token' => $token, 'name' => 'Media', 'color' => '#abcdef', 'recommended_days' => '3', 'max_days' => '10',
        ]);
        $this->em->clear();
        $media = $this->findPriority('Media');
        self::assertSame('#abcdef', $media->getColor());
        self::assertSame(3, $media->getRecommendedDays());
        self::assertSame(10, $media->getMaxDays());

        // Subir «Media» por encima de «Alta».
        $crawler = $this->client->request('GET', $this->url($w['centre']));
        $token   = $crawler->filter('form[action$="/prioridades/' . $media->getId()->toRfc4122() . '/mover/subir"] [name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($w['centre']) . '/prioridades/' . $media->getId()->toRfc4122() . '/mover/subir', ['_token' => $token]);
        $this->em->clear();
        $names = array_map(static fn (IssuePriority $p): string => $p->getName(), $this->em->getRepository(IssuePriority::class)->findBy(['educationalCentre' => $w['centre']], ['position' => 'ASC']));
        self::assertSame(['Crítica', 'Media', 'Alta', 'Baja'], $names);
    }

    // ── exportar ──────────────────────────────────────────────────────────────

    public function testExportWritesTheTreeWithManagersAsUsernameAndNameAndThePriorities(): void
    {
        $w = $this->makeWorld();

        $data = self::getContainer()->get(IssueSettingsExporter::class)->export($w['centre']);

        self::assertSame(IssueSettingsExporter::FORMAT, $data['format']);
        self::assertSame(['Crítica', 'Alta', 'Media', 'Baja'], array_column($data['priorities'], 'name'));
        self::assertSame('Mantenimiento', $data['types'][0]['name']);
        self::assertSame([['username' => $w['ana']->getUsername(), 'name' => 'Ana Responsable']], $data['types'][0]['managers']);
        self::assertSame('Electricidad', $data['types'][0]['children'][0]['name']);
        self::assertSame([['username' => $w['luis']->getUsername(), 'name' => 'Luis Docente']], $data['types'][0]['children'][0]['managers']);
    }

    public function testExportRouteDownloadsTheJson(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']) . '/exportar');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('Mantenimiento', json_decode((string) $this->client->getResponse()->getContent(), true)['types'][0]['name']);
    }

    // ── importar: servicio ────────────────────────────────────────────────────

    public function testRoundTripIntoAnotherCentreResolvesManagersByUsername(): void
    {
        $w      = $this->makeWorld();
        $other  = $this->makeSecondCentre($w);
        $parsed = $this->importer()->parse($this->exporter()->export($w['centre']));
        self::assertNotNull($parsed);

        $result = $this->importer()->import($parsed, $other['centre'], [0, 1], [0, 1, 2, 3], new IssueSettingsImportOptions());

        self::assertSame(2, $result->typesCreated);
        self::assertSame(0, $result->prioritiesCreated, 'el otro centro ya tiene las cuatro de nombre igual y se ignoran');
        self::assertSame(4, $result->prioritiesIgnored);
        $this->em->clear();
        $electricidad = $this->em->getRepository(IssueType::class)->findOneBy(['name' => 'Electricidad', 'educationalCentre' => $other['centre']]);
        self::assertNotNull($electricidad);
        self::assertSame('Mantenimiento › Electricidad', $electricidad->getPath());
        self::assertEqualsCanonicalizing(
            [$w['ana']->getUsername(), $w['luis']->getUsername()],
            array_map(static fn (Teacher $t): string => $t->getUsername(), $electricidad->getEffectiveManagers()),
        );
    }

    public function testChoosingANodeAlsoImportsItsAncestors(): void
    {
        $w      = $this->makeWorld();
        $other  = $this->makeSecondCentre($w);
        $parsed = $this->importer()->parse($this->exporter()->export($w['centre']));

        // Solo «Electricidad» (posición 1): hace falta «Mantenimiento» del que cuelga.
        $result = $this->importer()->import($parsed, $other['centre'], [1], [], new IssueSettingsImportOptions());

        self::assertSame(2, $result->typesCreated);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(IssueType::class)->findOneBy(['name' => 'Mantenimiento', 'educationalCentre' => $other['centre']]));
    }

    public function testIgnoreKeepsWhatExistsAndReplaceUpdatesItWithoutDeletingAnything(): void
    {
        $w     = $this->makeWorld();
        $other = $this->makeSecondCentre($w);
        $existing = (new IssueType())->setEducationalCentre($other['centre'])->setName('mantenimiento')->setActive(true);
        $this->persist($existing);
        $this->flush();
        $data = $this->exporter()->export($w['centre']);
        $data['types'][0]['active'] = false;
        $data['priorities'][0]['color'] = '#123456';
        $parsed = $this->importer()->parse($data);

        $ignored = $this->importer()->import($parsed, $other['centre'], [0], [0], new IssueSettingsImportOptions(replace: false));
        self::assertSame(1, $ignored->typesIgnored);
        $this->em->clear();
        self::assertTrue($this->findType('mantenimiento')->isActive(), 'ignorar no toca lo emparejado (sin distinguir mayúsculas)');
        self::assertCount(0, $this->findType('mantenimiento')->getManagers());

        $replaced = $this->importer()->import($parsed, $other['centre'], [0], [0], new IssueSettingsImportOptions(replace: true));
        self::assertSame(1, $replaced->typesUpdated);
        self::assertSame(1, $replaced->prioritiesUpdated);
        $this->em->clear();
        self::assertFalse($this->findType('mantenimiento')->isActive());
        self::assertSame([$w['ana']->getUsername()], array_map(static fn (Teacher $t): string => $t->getUsername(), $this->findType('mantenimiento')->getManagers()->toArray()));
        self::assertSame('#123456', $this->findPriority('Crítica', $other['centre'])->getColor());
        self::assertCount(1, $this->em->getRepository(IssueType::class)->findBy(['educationalCentre' => $other['centre']]), 'sustituir ni borra ni crea lo que no se ha elegido');
    }

    public function testTheManagersOptionOffLeavesNewTypesWithoutThem(): void
    {
        $w      = $this->makeWorld();
        $other  = $this->makeSecondCentre($w);
        $parsed = $this->importer()->parse($this->exporter()->export($w['centre']));

        $this->importer()->import($parsed, $other['centre'], [0, 1], [], new IssueSettingsImportOptions(managers: false));

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(IssueType::class)->findOneBy(['name' => 'Electricidad', 'educationalCentre' => $other['centre']])->getManagers());
    }

    public function testUnknownManagersAreReportedAndSkipped(): void
    {
        $w     = $this->makeWorld();
        $other = $this->makeSecondCentre($w);
        $data  = $this->exporter()->export($w['centre']);
        $data['types'][0]['managers'][] = ['username' => 'no.existe', 'name' => 'Fantasma Nadie'];
        $parsed = $this->importer()->parse($data);

        $preview = $this->importer()->preview($parsed, $other['centre']);
        $result  = $this->importer()->import($parsed, $other['centre'], [0], [], new IssueSettingsImportOptions());

        self::assertSame(['no.existe' => 'Fantasma Nadie'], $preview['unresolved']);
        self::assertSame(['no.existe (Fantasma Nadie)'], $result->unresolved);
    }

    public function testNewPrioritiesGoToTheEndInFileOrder(): void
    {
        $w     = $this->makeWorld();
        $data  = $this->exporter()->export($w['centre']);
        $data['priorities'][] = ['name' => 'Urgentísima', 'color' => '#000000', 'recommended_days' => 0, 'max_days' => 0];
        $parsed = $this->importer()->parse($data);

        $this->importer()->import($parsed, $w['centre'], [], [4], new IssueSettingsImportOptions());

        $this->em->clear();
        $names = array_map(static fn (IssuePriority $p): string => $p->getName(), $this->em->getRepository(IssuePriority::class)->findBy(['educationalCentre' => $w['centre']], ['position' => 'ASC']));
        self::assertSame('Urgentísima', end($names));
    }

    public function testParseRejectsOtherFilesAndNewerVersions(): void
    {
        self::assertNull($this->importer()->parse(['categories' => []]));
        self::assertNull($this->importer()->parse(['format' => IssueSettingsExporter::FORMAT, 'version' => 99]));
        self::assertNull($this->importer()->parse('texto'));
        self::assertSame([], $this->importer()->parse(['format' => IssueSettingsExporter::FORMAT])['types']);
    }

    // ── importar: flujo HTTP ──────────────────────────────────────────────────

    public function testUploadPreviewAndConfirmImportsTheSelection(): void
    {
        $w     = $this->makeWorld();
        $other = $this->makeSecondCentre($w);
        $this->loginAs($w['admin'], $other['centre']);
        $data = $this->exporter()->export($w['centre']);

        $preview = $this->upload($other['centre'], $data);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $preview->filter('input[name="types[]"]'));
        self::assertCount(4, $preview->filter('input[name="priorities[]"]'));
        self::assertCount(1, $preview->filter('input[name="mode"][value="ignore"][checked]'));

        $this->confirm($other['centre'], $preview, ['types' => ['0', '1'], 'include_managers' => '1', 'mode' => 'ignore']);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(IssueType::class)->findOneBy(['name' => 'Electricidad', 'educationalCentre' => $other['centre']]));
    }

    public function testNothingSelectedKeepsThePreviewWithAnError(): void
    {
        $w     = $this->makeWorld();
        $other = $this->makeSecondCentre($w);
        $this->loginAs($w['admin'], $other['centre']);
        $preview = $this->upload($other['centre'], $this->exporter()->export($w['centre']));

        $crawler = $this->confirm($other['centre'], $preview, ['mode' => 'ignore']);

        self::assertStringContainsString('al menos un tipo o una prioridad', $crawler->html());
    }

    public function testAnInvalidFileExpiredPreviewAndBadCsrfAreRejected(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->upload($w['centre'], ['categories' => []]);
        self::assertStringContainsString('no es una configuración de incidencias', (string) $this->client->getResponse()->getContent());

        $preview = $this->upload($w['centre'], $this->exporter()->export($w['centre']));
        $this->confirm($w['centre'], $preview, ['types' => ['0'], 'import_id' => '00000000-0000-4000-8000-000000000000']);
        self::assertResponseRedirects($this->url($w['centre']) . '/importar');

        $this->client->request('POST', $this->url($w['centre']) . '/importar/confirmar', ['_token' => 'malo', 'import_id' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    // ── ayudas ────────────────────────────────────────────────────────────────

    /**
     * Un centro con los tipos «Mantenimiento» (responsable: Ana) › «Electricidad» (responsable: Luis), las cuatro
     * prioridades por defecto y los dos docentes en el curso activo.
     *
     * @return array{admin: Teacher, ana: Teacher, luis: Teacher, centre: EducationalCentre, year: AcademicYear, mantenimiento: IssueType, electricidad: IssueType}
     */
    private function makeWorld(bool $withPriorities = true): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Incidencias')))->setUsername("admin.issues.$seq")->setAdmin(true);
        $ana    = (new Teacher(new PersonName('Ana', 'Responsable')))->setUsername("ana.resp.$seq");
        $luis   = (new Teacher(new PersonName('Luis', 'Docente')))->setUsername("luis.docente.i$seq");
        $centre = (new EducationalCentre())->setCode("4300{$seq}88")->setName("IES Incidencias $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $year->addTeacher($ana);
        $year->addTeacher($luis);
        $mantenimiento = (new IssueType())->setEducationalCentre($centre)->setName('Mantenimiento')->addManager($ana);
        $electricidad  = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->setParent($mantenimiento)->addManager($luis);

        $this->persist($admin, $ana, $luis, $centre, $year, $mantenimiento, $electricidad);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        if ($withPriorities) {
            self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        }
        $this->flush();

        return ['admin' => $admin, 'ana' => $ana, 'luis' => $luis, 'centre' => $centre, 'year' => $year, 'mantenimiento' => $mantenimiento, 'electricidad' => $electricidad];
    }

    /**
     * Otro centro, con sus propias prioridades por defecto y los mismos docentes en su curso activo.
     *
     * @param array<string, mixed> $w
     * @return array{centre: EducationalCentre, year: AcademicYear}
     */
    private function makeSecondCentre(array $w): array
    {
        $seq    = ++self::$seq;
        $centre = (new EducationalCentre())->setCode("4400{$seq}99")->setName("IES Destino $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $year->addTeacher($w['ana']);
        $year->addTeacher($w['luis']);
        $this->persist($centre, $year);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($w['admin']);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        $this->flush();

        return ['centre' => $centre, 'year' => $year];
    }

    private function findType(string $name): IssueType
    {
        $found = $this->em->getRepository(IssueType::class)->findOneBy(['name' => $name]);
        self::assertNotNull($found, $name);

        return $found;
    }

    private function findPriority(string $name, ?EducationalCentre $centre = null): IssuePriority
    {
        $criteria = ['name' => $name] + ($centre !== null ? ['educationalCentre' => $centre] : []);
        $found    = $this->em->getRepository(IssuePriority::class)->findOneBy($criteria);
        self::assertNotNull($found, $name);

        return $found;
    }

    private function switchOffTheModule(): void
    {
        $definition = (new \App\Entity\SettingDefinition())->setKey('features.issues')->setType(\App\Entity\SettingType::Boolean)->setCategory('settings.category.features')
            ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new \App\Entity\GlobalSettingValue())->setDefinition($definition)->setValue('false'));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function upload(EducationalCentre $centre, array $content): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->url($centre) . '/importar');
        $token   = $crawler->filter('[name="_token"]')->attr('value');
        $path    = tempnam(sys_get_temp_dir(), 'issues') . '.json';
        file_put_contents($path, json_encode($content));

        return $this->client->request('POST', $this->url($centre) . '/importar', ['_token' => $token], ['json' => new UploadedFile($path, 'incidencias.json', 'application/json', null, true)]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function confirm(EducationalCentre $centre, \Symfony\Component\DomCrawler\Crawler $preview, array $fields): \Symfony\Component\DomCrawler\Crawler
    {
        $form = $preview->filter('form[action$="/importar/confirmar"]');

        return $this->client->request('POST', $this->url($centre) . '/importar/confirmar', $fields + [
            '_token'    => $form->filter('[name="_token"]')->attr('value'),
            'import_id' => $form->filter('[name="import_id"]')->attr('value'),
        ]);
    }

    private function url(EducationalCentre $centre): string
    {
        return '/centro/' . $centre->getId()->toRfc4122() . '/incidencias';
    }

    private function exporter(): IssueSettingsExporter
    {
        return self::getContainer()->get(IssueSettingsExporter::class);
    }

    private function importer(): IssueSettingsImporter
    {
        return self::getContainer()->get(IssueSettingsImporter::class);
    }
}
