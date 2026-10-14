<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ExitReasonControllerTest extends ControllerTestCase
{
    public function testIndexIsAccessibleToCentreAdminAndDeniedToTeacher(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);
        $this->client->request('GET', $this->base($centre));
        self::assertResponseIsSuccessful();

        $teacher = $this->makeTeacher('plain.' . uniqid('', false));
        $this->persist($teacher);
        $this->loginAs($teacher);
        $this->client->request('GET', $this->base($centre));
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateAddsReasonAtTheEnd(): void
    {
        [$cadmin, $centre, $first] = $this->makeScenarioWithReason();
        $this->loginAs($cadmin);

        $crawler = $this->client->request('GET', $this->base($centre));
        $token   = $crawler->filter('form[action$="motivos-salida/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->base($centre) . '/nuevo', ['_token' => $token, 'name' => 'Biblioteca']);

        self::assertResponseRedirects($this->base($centre));
        $this->em->clear();
        $reasons = $this->em->getRepository(ExitReason::class)->findBy(['educationalCentre' => $centre->getId()], ['position' => 'ASC']);
        self::assertSame(['Servicio', 'Biblioteca'], array_map(static fn (ExitReason $r): string => $r->getName(), $reasons));
        self::assertNotNull($first);
    }

    public function testCreateWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('POST', $this->base($centre) . '/nuevo', ['_token' => 'bad', 'name' => 'X']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditCanDeactivateReason(): void
    {
        [$cadmin, $centre, $reason] = $this->makeScenarioWithReason();
        $this->loginAs($cadmin);

        $url     = $this->base($centre) . '/' . $reason->getId()->toRfc4122() . '/editar';
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'name' => 'Aseos', 'active' => '0']);

        self::assertResponseRedirects($this->base($centre));
        $this->em->clear();
        $reloaded = $this->em->find(ExitReason::class, $reason->getId());
        self::assertSame('Aseos', $reloaded?->getName());
        self::assertFalse($reloaded->isActive());
    }

    public function testDeleteRemovesUnusedReason(): void
    {
        [$cadmin, $centre, $reason] = $this->makeScenarioWithReason();
        $this->loginAs($cadmin);

        $id      = $reason->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $this->base($centre));
        $token   = $crawler->filter('form[action$="' . $id . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->base($centre) . '/' . $id . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects($this->base($centre));
        $this->em->clear();
        self::assertNull($this->em->find(ExitReason::class, $reason->getId()));
    }

    public function testDeleteIsBlockedWhenTheReasonHasExits(): void
    {
        [$cadmin, $centre, $reason] = $this->makeScenarioWithReason();
        $year    = $centre->getActiveAcademicYear();
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . uniqid('', false));
        $this->persist($course, $group, $student);
        $this->persist((new StudentExit())->setAcademicYear($year)->setStudent($student)->setGroup($group)->setReason($reason)->setAuthorizedBy($cadmin));
        $this->loginAs($cadmin);

        $id      = $reason->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $this->base($centre));
        $token   = $crawler->filter('form[action$="' . $id . '/eliminar"] [name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->base($centre) . '/' . $id . '/eliminar', ['_token' => $token]);

        $this->em->clear();
        self::assertNotNull($this->em->find(ExitReason::class, $reason->getId()));
    }

    public function testToggleActiveAndMove(): void
    {
        [$cadmin, $centre, $first] = $this->makeScenarioWithReason();
        $second = (new ExitReason())->setEducationalCentre($centre)->setName('Fuente')->setPosition(1);
        $this->persist($second);
        $this->loginAs($cadmin);

        $crawler = $this->client->request('GET', $this->base($centre));
        $id2     = $second->getId()->toRfc4122();
        $up      = $crawler->filter('form[action$="' . $id2 . '/subir"] [name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->base($centre) . '/' . $id2 . '/subir', ['_token' => $up]);

        $this->em->clear();
        self::assertSame(0, $this->em->find(ExitReason::class, $second->getId())?->getPosition());
        self::assertSame(1, $this->em->find(ExitReason::class, $first->getId())?->getPosition());

        $crawler = $this->client->request('GET', $this->base($centre));
        $toggle  = $crawler->filter('form[action$="' . $id2 . '/activar"] [name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->base($centre) . '/' . $id2 . '/activar', ['_token' => $toggle]);

        $this->em->clear();
        self::assertFalse($this->em->find(ExitReason::class, $second->getId())?->isActive());
    }

    public function testExportAndImportRoundTrip(): void
    {
        [$cadmin, $centre] = $this->makeScenarioWithReason();
        $this->loginAs($cadmin);

        $this->client->request('GET', $this->base($centre) . '/export');
        self::assertResponseIsSuccessful();
        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Servicio', $json);

        $payload = json_encode(['reasons' => [['name' => 'Servicio', 'active' => true], ['name' => 'Enfermería', 'active' => true]]], JSON_THROW_ON_ERROR);
        $path    = tempnam(sys_get_temp_dir(), 'exit');
        file_put_contents((string) $path, $payload);

        $crawler = $this->client->request('GET', $this->base($centre) . '/import');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $this->base($centre) . '/import', ['_token' => $token], [
            'json' => new UploadedFile((string) $path, 'motivos.json', 'application/json', null, true),
        ]);

        self::assertResponseRedirects($this->base($centre));
        $this->em->clear();
        $names = array_map(
            static fn (ExitReason $r): string => $r->getName(),
            $this->em->getRepository(ExitReason::class)->findBy(['educationalCentre' => $centre->getId()], ['position' => 'ASC']),
        );
        self::assertSame(['Servicio', 'Enfermería'], $names);
        @unlink((string) $path);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function base(EducationalCentre $centre): string
    {
        return '/centro/' . $centre->getId()->toRfc4122() . '/motivos-salida';
    }

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

    /** @return array{0: Teacher, 1: EducationalCentre, 2: ExitReason} */
    private function makeScenarioWithReason(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $reason            = (new ExitReason())->setEducationalCentre($centre)->setName('Servicio')->setPosition(0);
        $this->persist($reason);

        return [$cadmin, $centre, $reason];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
