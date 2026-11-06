<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueType;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Service\IssueManager;
use App\Service\IssuePrioritySeeder;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Integration\ResourceBookingScenarioTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Las incidencias de las reservas, ahora del módulo de incidencias: alta desde la reserva, marcas y filtro. */
class BookingIssuesTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use ResourceBookingScenarioTrait;

    protected function setUp(): void
    {
        self::mockTime('2026-09-14 09:00:00');
        parent::setUp();
    }

    public function testTheTeacherOfTheBookingIsOfferedToReportAnIssueFromIt(): void
    {
        $w = $this->scenario();
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/' . $w['booking']->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/incidencias/nueva?booking=' . $w['booking']->getId()->toRfc4122() . '"]'));
    }

    public function testTheCategoryManagerAndManagementAreOfferedItToo(): void
    {
        $w = $this->scenario();

        foreach (['manager' => 1, 'admin' => 1, 'other' => 0] as $who => $count) {
            $this->loginAs($w[$who], $w['centre']);
            $crawler = $this->client->request('GET', '/reservas/' . $w['booking']->getId()->toRfc4122());
            self::assertCount($count, $crawler->filter('a[href^="/incidencias/nueva"]'), $who);
        }
    }

    public function testAResponsibleCanCreateTheIssueFromSomeoneElsesBookingAndIsItsReporter(): void
    {
        $w  = $this->scenario();
        $id = $w['booking']->getId()->toRfc4122();
        $this->loginAs($w['manager'], $w['centre']);

        $crawler = $this->client->request('GET', '/incidencias/nueva?booking=' . $id);
        self::assertCount(0, $crawler->filter('select[name="location"]'));
        $token = (string) $crawler->filter('form[method="post"] [name="_token"]')->attr('value');
        $this->client->request('POST', '/incidencias/nueva', [
            '_token' => $token, 'booking' => $id, 'type' => $w['type']->getId()->toRfc4122(), 'description' => '<p>Teclado roto</p>',
        ]);

        $this->em->clear();
        $issues = $this->em->getRepository(Issue::class)->findAll();
        self::assertCount(1, $issues);
        self::assertSame($id, $issues[0]->getBooking()?->getId()->toRfc4122());
        self::assertSame($w['manager']->getId()->toRfc4122(), $issues[0]->getReporter()->getId()->toRfc4122());
    }

    public function testAnIssueCreatedFromTheBookingIsLinkedToItAndDoesNotAskForALocation(): void
    {
        $w  = $this->scenario();
        $id = $w['booking']->getId()->toRfc4122();
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/incidencias/nueva?booking=' . $id);
        self::assertCount(0, $crawler->filter('select[name="location"]'), 'la reserva ya incluye el recurso');
        $token = (string) $crawler->filter('form[method="post"] [name="_token"]')->attr('value');

        $this->client->request('POST', '/incidencias/nueva', [
            '_token' => $token, 'booking' => $id, 'type' => $w['type']->getId()->toRfc4122(), 'description' => '<p>El proyector no enciende</p>',
        ]);

        $this->em->clear();
        $issues = $this->em->getRepository(Issue::class)->findAll();
        self::assertCount(1, $issues);
        self::assertSame($id, $issues[0]->getBooking()?->getId()->toRfc4122());
        self::assertNull($issues[0]->getLocation());
    }

    public function testAnUnrelatedTeachersBookingIsIgnoredWhenReporting(): void
    {
        $w = $this->scenario();
        $this->loginAs($w['other'], $w['centre']);

        $crawler = $this->client->request('GET', '/incidencias/nueva?booking=' . $w['booking']->getId()->toRfc4122());

        self::assertCount(1, $crawler->filter('select[name="location"]'), 'no es su reserva: se pide la dependencia como siempre');
    }

    public function testAPendingBookingCannotBeTheOriginOfAnIssue(): void
    {
        $w       = $this->scenario();
        $pending = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-16', [$w['slots'][2][0]], ResourceBookingStatus::Pending);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/incidencias/nueva?booking=' . $pending->getId()->toRfc4122());

        self::assertCount(1, $crawler->filter('select[name="location"]'));
    }

    public function testTheBookingPageListsItsIssuesOnlyForWhoCanSeeThem(): void
    {
        $w = $this->scenario();
        $this->reportFromBooking($w, 'El proyector no enciende');
        $url = '/reservas/' . $w['booking']->getId()->toRfc4122();

        foreach (['owner' => true, 'manager' => true, 'admin' => true, 'other' => false] as $who => $sees) {
            $this->loginAs($w[$who], $w['centre']);
            $crawler = $this->client->request('GET', $url);
            self::assertCount($sees ? 1 : 0, $crawler->filter('#issue-title'), $who . ' (sección de incidencias)');
            self::assertSame($sees, str_contains($crawler->text(), '#1'), $who);
        }
    }

    public function testTheCalendarMarksTheBookingOnlyForWhoCanSeeItsIssues(): void
    {
        $w = $this->scenario();
        $this->reportFromBooking($w, 'El proyector no enciende');
        $url = '/reservas?date=2026-09-15';

        foreach (['owner' => true, 'manager' => true, 'other' => false] as $who => $sees) {
            $this->loginAs($w[$who], $w['centre']);
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame($sees, str_contains($crawler->html(), '1 incidencia sin cerrar'), $who);
        }
    }

    public function testTheBookingListCanBeFilteredByIssues(): void
    {
        $w     = $this->scenario();
        $clean = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-17', [$w['slots'][3][0]]);
        $this->flush();
        $this->reportFromBooking($w, 'El proyector no enciende');
        $this->loginAs($w['owner'], $w['centre']);

        $count = fn (string $issue): int => $this->client->request('GET', '/reservas/listado?from=all&issue=' . $issue)->filter('tbody tr')->count();

        self::assertSame(2, $count(''));
        self::assertSame(1, $count('any'));
        self::assertSame(1, $count('open'));
        self::assertNotSame($clean->getId()->toRfc4122(), $w['booking']->getId()->toRfc4122());

        // Al cerrar la incidencia, deja de contar como abierta pero sigue contando como «con incidencias».
        $this->em->clear();
        $issue = $this->em->getRepository(Issue::class)->findAll()[0];
        $issue->setStatus(\App\Entity\IssueStatus::Solved);
        $this->flush();
        self::assertSame(1, $count('any'));
        self::assertSame(0, $count('open'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $w       = $this->makeBookingWorld();
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $root    = (new IssueType())->setEducationalCentre($w['centre'])->setName('Reservas de recursos');
        $type    = (new IssueType())->setEducationalCentre($w['centre'])->setName('Dependencias')->setParent($root)->addManager($w['manager']);
        $this->persist($root, $type);
        $w['centre']->addAdmin($w['admin']);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($w['centre']);
        $this->flush();

        return $w + ['booking' => $booking, 'type' => $type];
    }

    /** @param array<string, mixed> $w */
    private function reportFromBooking(array $w, string $description): Issue
    {
        /** @var EducationalCentre $centre */
        $centre = $w['centre'];
        /** @var ResourceBooking $booking */
        $booking = $w['booking'];

        return self::getContainer()->get(IssueManager::class)->create($centre, $w['year'], $w['owner'], $w['type'], null, $booking, "<p>$description</p>", []);
    }
}
