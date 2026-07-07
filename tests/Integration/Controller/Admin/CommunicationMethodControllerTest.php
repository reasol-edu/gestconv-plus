<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CommunicationMethodControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexIsAccessibleToCentreAdminWithoutGlobalRoleAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $pureCentreAdmin = $this->makeTeacher('pure.cadmin.' . uniqid('', false));
        $centre->addAdmin($pureCentreAdmin);
        $this->persist($pureCentreAdmin);
        $this->loginAs($pureCentreAdmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion');

        self::assertResponseIsSuccessful();
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreatePostAddsMethodAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion');
        $token    = $crawler->filter('form[action$="metodos-comunicacion/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/nuevo', [
            '_token' => $token,
            'name'   => 'Mensajería Pasen',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        $methods = $this->em->getRepository(CommunicationMethod::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $methods);
        self::assertSame('Mensajería Pasen', $methods[0]->getName());
    }

    public function testCreateWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion/nuevo', [
            '_token' => 'invalid-token',
            'name'   => 'Método inválido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateWithEmptyNameDoesNotPersist(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion');
        $token    = $crawler->filter('form[action$="metodos-comunicacion/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/nuevo', [
            '_token' => $token,
            'name'   => '',
        ]);

        $this->em->clear();
        $methods = $this->em->getRepository(CommunicationMethod::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(0, $methods);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $this->client->request(
            'GET',
            '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion/' . $method->getId()->toRfc4122() . '/editar'
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChanges(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $methodId = $method->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion/' . $methodId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/' . $methodId . '/editar', [
            '_token' => $token,
            'name'   => 'Nombre actualizado',
            'active' => '1',
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        $updated = $this->em->find(CommunicationMethod::class, $method->getId());
        self::assertNotNull($updated);
        self::assertSame('Nombre actualizado', $updated->getName());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesMethodAndRedirects(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $methodId = $method->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion');
        $token    = $crawler->filter('form[action$="' . $methodId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/' . $methodId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        self::assertNull($this->em->find(CommunicationMethod::class, $method->getId()));
    }

    public function testDeleteInUseShowsErrorAndKeepsMethod(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();

        $teacher  = $this->makeTeacher('reporter.' . uniqid('', false));
        $year     = $centre->getActiveAcademicYear();
        $category = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Contrarias')->setSerious(false)->setPosition(0);
        $behavior = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($category)->setName('Perturbación')->setPosition(0)->setActive(true);
        $group    = (new \App\Entity\Group())->setName('1ºA')->setProgrammeYear(
            (new \App\Entity\ProgrammeYear())->setName('1º')->setProgramme(
                (new \App\Entity\Programme())->setName('DAW')->setAcademicYear($year)
            )
        );
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . uniqid('', false));
        $this->persist($teacher, $category, $behavior, $group->getProgrammeYear()->getProgramme(), $group->getProgrammeYear(), $group, $student);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(1)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);
        $this->persist($report);

        $communication = Communication::forIncidentReport($report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);

        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $methodId = $method->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion');
        $token    = $crawler->filter('form[action$="' . $methodId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/' . $methodId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        self::assertNotNull($this->em->find(CommunicationMethod::class, $method->getId()));
    }

    // ── toggleActive ──────────────────────────────────────────────────────────

    public function testToggleActiveFlipsFlag(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        self::assertTrue($method->isActive());
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $methodId = $method->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion');
        self::assertResponseIsSuccessful();

        $tokenNodes = $crawler->filter('form[action$="/activar"] [name="_token"]');
        self::assertGreaterThan(0, $tokenNodes->count(), 'Toggle-active form not found in page');
        $token = $tokenNodes->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/' . $methodId . '/activar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(CommunicationMethod::class, $method->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive());
    }

    // ── moveUp ────────────────────────────────────────────────────────────────

    public function testMoveUpSwapsPositionWithPrevious(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $m1 = $this->makeMethod($centre, 'Primera', 0);
        $m2 = $this->makeMethod($centre, 'Segunda', 1);
        $this->persist($m1, $m2);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion');
        $tokens   = $crawler->filter('form[action$="/subir"] [name="_token"]');
        $token    = $tokens->count() > 0 ? $tokens->first()->attr('value') : '';

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/' . $m2->getId()->toRfc4122() . '/subir', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $refreshedM1 = $this->em->find(CommunicationMethod::class, $m1->getId());
        $refreshedM2 = $this->em->find(CommunicationMethod::class, $m2->getId());
        self::assertNotNull($refreshedM1);
        self::assertNotNull($refreshedM2);
        self::assertGreaterThan($refreshedM2->getPosition(), $refreshedM1->getPosition());
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function testExportReturnsJsonWithMethods(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion/export');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($centre->getName(), $data['centre']);
        self::assertSame($method->getName(), $data['methods'][0]['name']);
    }

    public function testExportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.export');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion/export');

        self::assertResponseStatusCodeSame(403);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="json"]');
    }

    public function testImportPostWithValidJsonCreatesMethodsAndRedirects(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'methods' => [
                ['name' => 'Mensajería Pasen', 'active' => true],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        $methods = $this->em->getRepository(CommunicationMethod::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $methods);
        self::assertSame('Mensajería Pasen', $methods[0]->getName());
    }

    public function testImportPostWithInvalidJsonShowsError(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, 'not json');
        $file = new UploadedFile($tmpFile, 'methods.json', 'application/json', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/import', [
            '_token' => $token,
        ], ['json' => $file]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('div', 'JSON válido');
    }

    public function testImportPostWithReplaceExistingRemovesPreviousMethod(): void
    {
        [$cadmin, $centre, $oldMethod] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'methods' => [
                ['name' => 'Método nuevo', 'active' => true],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/import', [
            '_token'           => $token,
            'replace_existing' => '1',
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        self::assertNull($this->em->find(CommunicationMethod::class, $oldMethod->getId()));
        $methods = $this->em->getRepository(CommunicationMethod::class)->findBy(['educationalCentre' => $centre->getId()]);
        self::assertCount(1, $methods);
        self::assertSame('Método nuevo', $methods[0]->getName());
    }

    public function testImportPostWithReplaceExistingKeepsMethodInUse(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();

        $teacher  = $this->makeTeacher('reporter.' . uniqid('', false));
        $year     = $centre->getActiveAcademicYear();
        $category = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Contrarias')->setSerious(false)->setPosition(0);
        $behavior = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($category)->setName('Perturbación')->setPosition(0)->setActive(true);
        $group    = (new \App\Entity\Group())->setName('1ºA')->setProgrammeYear(
            (new \App\Entity\ProgrammeYear())->setName('1º')->setProgramme(
                (new \App\Entity\Programme())->setName('DAW')->setAcademicYear($year)
            )
        );
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . uniqid('', false));
        $this->persist($teacher, $category, $behavior, $group->getProgrammeYear()->getProgramme(), $group->getProgrammeYear(), $group, $student);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(1)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);
        $this->persist($report);

        $communication = Communication::forIncidentReport($report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);

        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/metodos-comunicacion/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $file = $this->makeJsonUploadFile([
            'methods' => [
                ['name' => 'Método nuevo', 'active' => true],
            ],
        ]);

        $this->client->request('POST', '/centro/' . $centreId . '/metodos-comunicacion/import', [
            '_token'           => $token,
            'replace_existing' => '1',
        ], ['json' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/metodos-comunicacion');

        $this->em->clear();
        self::assertNotNull($this->em->find(CommunicationMethod::class, $method->getId()), 'El método en uso no debe eliminarse aunque se marque «vaciar antes de importar».');
    }

    public function testImportIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.import');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/metodos-comunicacion/import');

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

    /** @return array{0: Teacher, 1: EducationalCentre, 2: CommunicationMethod} */
    private function makeScenarioWithMethod(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $method             = $this->makeMethod($centre, 'Llamada telefónica', 0);
        $this->persist($method);

        return [$cadmin, $centre, $method];
    }

    private function makeMethod(EducationalCentre $centre, string $name, int $position, bool $active = true): CommunicationMethod
    {
        return (new CommunicationMethod())
            ->setEducationalCentre($centre)
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

        return new UploadedFile($tmpFile, 'methods.json', 'application/json', null, true);
    }
}
