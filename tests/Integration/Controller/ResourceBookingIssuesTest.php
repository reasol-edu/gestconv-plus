<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\BookingIssueStatus;
use App\Entity\EmailDigestItem;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\SettingDefinition;
use App\Entity\TeacherSettingValue;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Integration\ResourceBookingScenarioTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Incidencias de las reservas: permisos, avisos por correo, marca en el calendario y el listado, campana y panel. */
class ResourceBookingIssuesTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;
    use ResourceBookingScenarioTrait;

    protected function setUp(): void
    {
        self::mockTime('2026-09-14 09:00:00');
        parent::setUp();
    }

    // ── registrar ─────────────────────────────────────────────────────────────

    public function testTheTeacherOfTheBookingReportsAnIssueAndTheManagersAreQueuedInTheDigest(): void
    {
        $w = $this->scenario();
        $this->loginAs($w['owner'], $w['centre']);

        $this->report($w['booking'], 'El proyector no enciende');

        $booking = $this->reload($w['booking']);
        self::assertSame(BookingIssueStatus::Reported, $booking->getIssueStatus());
        self::assertSame('El proyector no enciende', $booking->getIssueDescription());

        $items = $this->em->getRepository(EmailDigestItem::class)->findAll();
        self::assertCount(1, $items, 'solo avisa al responsable, no a quien registra la incidencia');
        self::assertSame('booking_issue_reported', $items[0]->getEventKey());
        self::assertSame('Marta Responsable', $items[0]->getRecipient()->getName()->full());
    }

    public function testAnImmediateRecipientGetsAnEmailWithTheIssue(): void
    {
        $w = $this->scenario();
        $this->setTeacherSetting($w['manager'], 'notifications.email_delivery', 'immediate');
        $this->loginAs($w['owner'], $w['centre']);

        $this->report($w['booking'], 'El proyector no enciende');

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'manager@example.org');
        self::assertEmailHtmlBodyContains($email, 'El proyector no enciende');
    }

    public function testARecipientWhoDisabledThisNoticeIsNotWarned(): void
    {
        $w = $this->scenario();
        $this->setTeacherSetting($w['manager'], 'notifications.receive_booking_issue_reported', 'false');
        $this->loginAs($w['owner'], $w['centre']);

        $this->report($w['booking'], 'El proyector no enciende');

        self::assertCount(0, $this->em->getRepository(EmailDigestItem::class)->findAll(), 'ni siquiera en el resumen diario');
        self::assertEmailCount(0);
    }

    public function testWithoutManagersTheCentreAdminsAreWarned(): void
    {
        $w = $this->scenario();
        $w['category']->removeManager($w['manager']);
        $w['centre']->addAdmin($w['admin']);
        $w['admin']->setEmail('admin@example.org');
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->report($w['booking'], 'El proyector no enciende');

        $items = $this->em->getRepository(EmailDigestItem::class)->findAll();
        self::assertCount(1, $items);
        self::assertSame('Admin Reservas', $items[0]->getRecipient()->getName()->full());
    }

    public function testAnIssueWithoutDescriptionIsNotRegistered(): void
    {
        $w = $this->scenario();
        $this->loginAs($w['owner'], $w['centre']);

        $this->report($w['booking'], '   ');

        self::assertFalse($this->reload($w['booking'])->hasIssue());
        self::assertCount(0, $this->em->getRepository(EmailDigestItem::class)->findAll());
    }

    public function testOnlyTheTeacherOfTheBookingCanReportAnIssue(): void
    {
        $w = $this->scenario();

        foreach (['other', 'manager', 'admin'] as $who) {
            $this->loginAs($w[$who], $w['centre']);
            $this->client->request('POST', '/reservas/' . $w['booking']->getId()->toRfc4122() . '/incidencia', [
                '_token' => 'x', 'description' => 'Intento',
            ]);
            self::assertResponseStatusCodeSame(403, $who);
        }
        self::assertFalse($this->reload($w['booking'])->hasIssue());
    }

    public function testAPendingBookingCannotHaveAnIssue(): void
    {
        $w = $this->scenario();
        $pending = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-16', [$w['slots'][2][0]], ResourceBookingStatus::Pending);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->client->request('POST', '/reservas/' . $pending->getId()->toRfc4122() . '/incidencia', ['_token' => 'x', 'description' => 'Algo']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAPastBookingCanStillBeReported(): void
    {
        $w = $this->scenario('2026-09-07');
        $this->loginAs($w['owner'], $w['centre']);

        $this->report($w['booking'], 'Faltaba un cable');

        self::assertTrue($this->reload($w['booking'])->hasIssue(), 'las incidencias se registran después de usar el recurso');
    }

    // ── responder ─────────────────────────────────────────────────────────────

    public function testAManagerMarksTheIssueAsReceivedAndTheTeacherIsWarned(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->setTeacherSetting($w['owner'], 'notifications.email_delivery', 'immediate');
        $this->loginAs($w['manager'], $w['centre']);

        $this->respond($w['booking'], 'received', 'Lo miramos mañana');

        $booking = $this->reload($w['booking']);
        self::assertSame(BookingIssueStatus::Received, $booking->getIssueStatus());
        self::assertSame('Lo miramos mañana', $booking->getIssueComment());
        self::assertFalse($booking->isIssueResponseSeen());
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'owner@example.org');
        self::assertEmailHtmlBodyContains($email, 'Lo miramos mañana');
        self::assertEmailHtmlBodyContains($email, 'recibida');
    }

    public function testAnIssueIsSolvedAndTheTeacherGetsItInTheDigest(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['manager'], $w['centre']);

        $this->respond($w['booking'], 'resolved', 'Cambiada la lámpara');

        self::assertSame(BookingIssueStatus::Resolved, $this->reload($w['booking'])->getIssueStatus());
        $keys = array_map(
            static fn (EmailDigestItem $item): string => $item->getEventKey() . '>' . $item->getRecipient()->getName()->full(),
            $this->em->getRepository(EmailDigestItem::class)->findAll(),
        );
        self::assertContains('booking_issue_resolved>Ana Propietaria', $keys);
    }

    public function testTheCommentIsMandatoryToRespond(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['manager'], $w['centre']);

        $this->respond($w['booking'], 'resolved', '  ');

        self::assertSame(BookingIssueStatus::Reported, $this->reload($w['booking'])->getIssueStatus());
    }

    public function testAnUnrelatedTeacherCannotRespond(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['other'], $w['centre']);

        $this->client->request('POST', '/reservas/' . $w['booking']->getId()->toRfc4122() . '/incidencia/responder', [
            '_token' => 'x', 'status' => 'resolved', 'comment' => 'Hecho',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(BookingIssueStatus::Reported, $this->reload($w['booking'])->getIssueStatus());
    }

    public function testTheAuthorCannotRespondToTheirOwnIssue(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['owner'], $w['centre']);

        $this->client->request('POST', '/reservas/' . $w['booking']->getId()->toRfc4122() . '/incidencia/responder', [
            '_token' => 'x', 'status' => 'resolved', 'comment' => 'Ya está',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testATeacherWhoDisabledTheAnswerNoticeIsNotWarned(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->setTeacherSetting($w['owner'], 'notifications.receive_booking_issue_received', 'false');
        $this->loginAs($w['manager'], $w['centre']);

        $this->respond($w['booking'], 'received', 'Recibido');

        $keys = array_map(static fn (EmailDigestItem $i): string => $i->getEventKey(), $this->em->getRepository(EmailDigestItem::class)->findAll());
        self::assertNotContains('booking_issue_received', $keys);
        self::assertEmailCount(0);
    }

    // ── marca ─────────────────────────────────────────────────────────────────

    public function testTheMarkIsOnlyVisibleToTheAuthorTheManagersAndTheManagement(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo secreto');
        $w['centre']->addAdmin($w['admin']);
        $this->flush();

        foreach (['owner' => true, 'manager' => true, 'admin' => true, 'other' => false] as $who => $sees) {
            $this->loginAs($w[$who], $w['centre']);

            $calendar = $this->client->request('GET', '/reservas?date=2026-09-14');
            self::assertSame($sees, str_contains($calendar->html(), 'Incidencia: Registrada'), "calendario: $who");
            self::assertStringNotContainsString('Fallo secreto', $calendar->html(), "el texto nunca sale en el calendario: $who");

            $show = $this->client->request('GET', '/reservas/' . $w['booking']->getId()->toRfc4122());
            self::assertSame($sees, str_contains($show->html(), 'Fallo secreto'), "ficha: $who");
        }
    }

    public function testTheListShowsTheMarkToItsAudienceAndFiltersByIssue(): void
    {
        $w = $this->scenario();
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-17', [$w['slots'][3][0]]);
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['manager'], $w['centre']);

        $unfiltered = $this->client->request('GET', '/reservas/listado?scope=managed&from=all');
        self::assertCount(2, $unfiltered->filter('tbody tr'));

        $filtered = $this->client->request('GET', '/reservas/listado?scope=managed&from=all&issue=to_receive');
        self::assertCount(1, $filtered->filter('tbody tr'));
        self::assertStringContainsString('Registrada', $filtered->filter('tbody')->text());
    }

    // ── campana y panel ───────────────────────────────────────────────────────

    public function testTheBellAndTheDashboardWarnTheManagerOfTheNewIssue(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['manager'], $w['centre']);

        $crawler = $this->client->request('GET', '/');

        self::assertStringContainsString('Ana Propietaria ha registrado una incidencia en «Aula de informática»', $this->bellText());
        self::assertStringContainsString('Incidencias de reservas', $crawler->html());
        self::assertCount(1, $crawler->filter('a[href*="issue=to_receive"]'));
    }

    public function testTheBellAndTheDashboardWarnTheTeacherOfTheAnswerUntilTheyReadIt(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['manager'], $w['centre']);
        $this->respond($w['booking'], 'resolved', 'Cambiada la lámpara');

        $this->loginAs($w['owner'], $w['centre']);
        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('Tu incidencia en «Aula de informática» se ha solucionado', $this->bellText());
        self::assertCount(1, $crawler->filter('a[href*="issue=any"]'));

        $this->client->request('GET', '/reservas/' . $w['booking']->getId()->toRfc4122());
        self::assertStringNotContainsString('se ha solucionado', $this->bellText());
    }

    public function testOnceAnsweredTheManagerNoLongerSeesItAsToReceive(): void
    {
        $w = $this->scenario();
        $this->reportAs($w, 'Fallo del proyector');
        $this->loginAs($w['manager'], $w['centre']);
        $this->respond($w['booking'], 'received', 'Recibido');

        $this->client->request('GET', '/');

        self::assertStringNotContainsString('ha registrado una incidencia', $this->bellText());
    }

    // ── ayudas ────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function scenario(string $date = '2026-09-15'): array
    {
        $w = $this->makeBookingWorld();
        $w['manager']->setEmail('manager@example.org');
        $w['owner']->setEmail('owner@example.org');
        $w['booking'] = $this->makeBooking($w['year'], $w['resource'], $w['owner'], $date, [$w['slots'][1][0]]);
        $this->flush();

        return $w;
    }

    private function reload(ResourceBooking $booking): ResourceBooking
    {
        $this->em->clear();
        $found = $this->em->getRepository(ResourceBooking::class)->find($booking->getId());
        self::assertNotNull($found);

        return $found;
    }

    /** @param array<string, mixed> $w */
    private function reportAs(array $w, string $description): void
    {
        $this->loginAs($w['owner'], $w['centre']);
        $this->report($w['booking'], $description);
    }

    private function report(ResourceBooking $booking, string $description): void
    {
        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/incidencia"] [name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/incidencia', ['_token' => $token, 'description' => $description]);
    }

    private function respond(ResourceBooking $booking, string $status, string $comment): void
    {
        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/responder"] [name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/incidencia/responder', [
            '_token' => $token, 'status' => $status, 'comment' => $comment,
        ]);
    }

    private function setTeacherSetting(\App\Entity\Teacher $teacher, string $key, string $value): void
    {
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);
        self::assertNotNull($definition);
        // El gestor de entidades se limpia al recargar y el docente puede haber quedado desconectado: se vuelve a leer.
        $managed = $this->em->getRepository(\App\Entity\Teacher::class)->find($teacher->getId());
        self::assertNotNull($managed);
        $this->persist((new TeacherSettingValue())->setDefinition($definition)->setTeacher($managed)->setValue($value));
        $this->flush();
    }

    private function bellText(): string
    {
        return $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render()->crawler()->text();
    }
}
