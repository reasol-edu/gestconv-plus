<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class AcademicYearControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToCentreAdmin(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/cursos');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsAccessibleToCentreAdminWithoutGlobalRoleAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $pureCentreAdmin = $this->makeTeacher('pure.cadmin.years.' . uniqid('', false));
        $centre->addAdmin($pureCentreAdmin);
        $this->persist($pureCentreAdmin);
        $this->loginAs($pureCentreAdmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/cursos');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsDeniedToNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.no.priv.years');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/cursos');

        self::assertResponseStatusCodeSame(403);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddYearCreatesYearAndRedirectsToIndex(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', [
            '_token' => $token,
            'name'   => '2026-2027',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/centro/' . $centreId . '/cursos', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAddYearWithInvalidCsrfIsDenied(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', [
            '_token' => 'token-invalido',
            'name'   => '2026-2027',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAddingYearLogsAcademicYearCreated(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';

        try {
            [$cadmin, $centre] = $this->makeScenario();
            $this->loginAs($cadmin);

            $centreId = $centre->getId()->toRfc4122();
            $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
            $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

            $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', [
                '_token' => $token,
                'name'   => '2026-2027',
            ]);

            self::assertResponseRedirects();

            $this->em->clear();
            $logs = $this->em->getRepository(ActivityLog::class)->findAll();
            self::assertCount(1, $logs);
            self::assertSame('academic_year.created', $logs[0]->getActionType());
            self::assertSame('2026-2027', $logs[0]->getData()['name'] ?? null);
            self::assertSame($centreId, $logs[0]->getData()['centreId'] ?? null);
        } finally {
            putenv('APP_LOG=false');
            $_ENV['APP_LOG']    = 'false';
            $_SERVER['APP_LOG'] = 'false';
        }
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditYearGetRendersForm(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $year = $this->makeYear($centre, '2024-2025');
        $this->persist($year);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $year->getId()->toRfc4122();

        $this->client->request('GET', '/centro/' . $centreId . '/cursos/' . $yearId . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditYearPostSavesChanges(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $year = $this->makeYear($centre, '2024-2025');
        $this->persist($year);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $year->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/cursos/' . $yearId . '/editar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/' . $yearId . '/editar', [
            '_token'     => $token,
            'name'       => '2027-2028',
            'start_date' => '2027-09-10',
            'end_date'   => '2028-06-30',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(AcademicYear::class, $year->getId());
        self::assertSame('2027-2028', $updated->getName());
        self::assertSame('2027-09-10', $updated->getStartDate()->format('Y-m-d'));
        self::assertSame('2028-06-30', $updated->getEndDate()->format('Y-m-d'));
    }

    public function testEditYearRejectsAnEndDateNotAfterTheStart(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $year = $this->makeYear($centre, '2024-2025');
        $this->persist($year);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $year->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos/' . $yearId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/' . $yearId . '/editar', [
            '_token'     => $token,
            'name'       => '2024-2025',
            'start_date' => '2025-06-30',
            'end_date'   => '2024-09-15',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form', 'posterior a la de comienzo');

        $this->em->clear();
        $unchanged = $this->em->find(AcademicYear::class, $year->getId());
        self::assertSame('2024-09-15', $unchanged->getStartDate()->format('Y-m-d'));
    }

    public function testEditFormShowsTheYearDates(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $year = $this->makeYear($centre, '2024-2025');
        $this->persist($year);
        $this->loginAs($cadmin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/cursos/' . $year->getId()->toRfc4122() . '/editar');

        self::assertInputValueSame('start_date', '2024-09-15');
        self::assertInputValueSame('end_date', '2025-09-14');
    }

    public function testAddYearWithoutDatesTakesThemFromTheName(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', ['_token' => $token, 'name' => '2031-2032']);

        $this->em->clear();
        $created = $this->em->getRepository(AcademicYear::class)->findOneBy(['name' => '2031-2032']);
        self::assertNotNull($created);
        self::assertSame('2031-09-15', $created->getStartDate()->format('Y-m-d'));
        self::assertSame('2032-09-14', $created->getEndDate()->format('Y-m-d'));
    }

    public function testAddYearWithDatesKeepsThem(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', [
            '_token' => $token, 'name' => '2033-2034', 'start_date' => '2033-09-01', 'end_date' => '2034-07-15',
        ]);

        $this->em->clear();
        $created = $this->em->getRepository(AcademicYear::class)->findOneBy(['name' => '2033-2034']);
        self::assertNotNull($created);
        self::assertSame('2033-09-01', $created->getStartDate()->format('Y-m-d'));
        self::assertSame('2034-07-15', $created->getEndDate()->format('Y-m-d'));
    }

    public function testAddYearRejectsInvertedOrHalfGivenDates(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action$="/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', [
            '_token' => $token, 'name' => '2035-2036', 'start_date' => '2036-01-01', 'end_date' => '2035-01-01',
        ]);
        $this->client->request('POST', '/centro/' . $centreId . '/cursos/nuevo', [
            '_token' => $token, 'name' => '2037-2038', 'start_date' => '2037-09-15', 'end_date' => '',
        ]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(AcademicYear::class)->findOneBy(['name' => '2035-2036']));
        self::assertNull($this->em->getRepository(AcademicYear::class)->findOneBy(['name' => '2037-2038']));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteYearDeletesNonActiveYear(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $extra = $this->makeYear($centre, '2023-2024');
        $this->persist($extra);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $extra->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action*="/cursos/' . $yearId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/' . $yearId . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNull($this->em->find(AcademicYear::class, $extra->getId()));
    }

    public function testDeleteActiveYearIsBlocked(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $centre->getActiveAcademicYear()->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action*="/cursos/' . $yearId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/' . $yearId . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNotNull($this->em->find(AcademicYear::class, $yearId));
    }

    // ── activate ──────────────────────────────────────────────────────────────

    public function testActivateYearSetsActiveYear(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $other = $this->makeYear($centre, '2023-2024');
        $this->persist($other);
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $otherId  = $other->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/cursos');
        $token    = $crawler->filter('form[action*="/cursos/' . $otherId . '/activar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/cursos/' . $otherId . '/activar', ['_token' => $token]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(EducationalCentre::class, $centre->getId());
        self::assertSame($otherId, $updated->getActiveAcademicYear()->getId()->toRfc4122());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeYear(EducationalCentre $centre, string $name): AcademicYear
    {
        return (new AcademicYear())->setName($name)->setEducationalCentre($centre);
    }

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.years.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('44' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }
}
