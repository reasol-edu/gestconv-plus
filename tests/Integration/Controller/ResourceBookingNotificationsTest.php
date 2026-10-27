<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EmailDigestItem;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\SettingDefinition;
use App\Entity\TeacherSettingValue;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Integration\ResourceBookingScenarioTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Avisos de las reservas con validación: correo y resumen diario, campana de notificaciones y panel de inicio. */
class ResourceBookingNotificationsTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;
    use ResourceBookingScenarioTrait;

    protected function setUp(): void
    {
        self::mockTime('2026-09-14 09:00:00');
        parent::setUp();
    }

    public function testARequestIsQueuedInTheDigestOfTheCategoryManagers(): void
    {
        $w = $this->makeBookingWorld(true);
        $w['manager']->setEmail('manager@example.org');
        $w['owner']->setEmail('owner@example.org');
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->book($w, '2026-09-15', $w['slots'][1][0]->getId()->toRfc4122());

        $items = $this->em->getRepository(EmailDigestItem::class)->findAll();
        self::assertCount(1, $items, 'solo avisa al responsable, no a quien solicita');
        self::assertSame('booking_requested', $items[0]->getEventKey());
        self::assertSame('Marta Responsable', $items[0]->getRecipient()->getName()->full());
        self::assertSame('Aula de informática', $items[0]->getParams()['%resource%']);
    }

    public function testARequestIsEmailedRightAwayToAManagerWhoPrefersImmediateDelivery(): void
    {
        $w = $this->makeBookingWorld(true);
        $w['manager']->setEmail('manager@example.org');
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => 'notifications.email_delivery']);
        self::assertNotNull($definition);
        $this->persist((new TeacherSettingValue())->setDefinition($definition)->setTeacher($w['manager'])->setValue('immediate'));
        $this->loginAs($w['owner'], $w['centre']);

        $this->book($w, '2026-09-15', $w['slots'][1][0]->getId()->toRfc4122());

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'manager@example.org');
        self::assertEmailHtmlBodyContains($email, 'Aula de informática');
    }

    public function testWithoutManagersTheCentreAdminsAreWarned(): void
    {
        $w = $this->makeBookingWorld(true);
        $w['category']->removeManager($w['manager']);
        $w['centre']->addAdmin($w['admin']);
        $w['admin']->setEmail('admin@example.org');
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->book($w, '2026-09-15', $w['slots'][1][0]->getId()->toRfc4122());

        $items = $this->em->getRepository(EmailDigestItem::class)->findAll();
        self::assertCount(1, $items);
        self::assertSame('Admin Reservas', $items[0]->getRecipient()->getName()->full());
    }

    public function testNothingIsSentWhenTheBookingNeedsNoValidation(): void
    {
        $w = $this->makeBookingWorld(false);
        $w['manager']->setEmail('manager@example.org');
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->book($w, '2026-09-15', $w['slots'][1][0]->getId()->toRfc4122());

        self::assertCount(0, $this->em->getRepository(EmailDigestItem::class)->findAll());
    }

    public function testTheResolutionIsQueuedForTheTeacherAndTheBellShowsIt(): void
    {
        $w = $this->makeBookingWorld(true);
        $w['owner']->setEmail('owner@example.org');
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['manager'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/rechazar"] [name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/rechazar', ['_token' => $token, 'reason' => 'Examen']);

        $items = $this->em->getRepository(EmailDigestItem::class)->findAll();
        self::assertCount(1, $items);
        self::assertSame('booking_rejected', $items[0]->getEventKey());
        self::assertSame('Ana Propietaria', $items[0]->getRecipient()->getName()->full());

        // La campana del docente avisa de la respuesta hasta que la ve.
        $this->loginAs($w['owner'], $w['centre']);
        self::assertStringContainsString('Tu reserva de «Aula de informática» se ha rechazado', $this->bellText());

        $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        self::assertStringNotContainsString('se ha rechazado', $this->bellText());
    }

    public function testTheBellOfAManagerListsThePendingRequestsAndTheDashboardCountsThem(): void
    {
        $w = $this->makeBookingWorld(true);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-16', [$w['slots'][2][0]], ResourceBookingStatus::Pending);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-17', [$w['slots'][3][0]]);
        $this->loginAs($w['manager'], $w['centre']);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $bell = $this->bellText();
        self::assertStringContainsString('Ana Propietaria solicita reservar «Aula de informática»', $bell);
        self::assertStringContainsString('Otro Docente solicita reservar «Aula de informática»', $bell);
        self::assertStringContainsString('Reservas por validar', $crawler->html());
        $tile = $crawler->filter('a[href*="scope=managed"]');
        self::assertCount(1, $tile);
        self::assertStringContainsString('2', $tile->text());
    }

    public function testATeacherWhoManagesNothingSeesNoValidationNotices(): void
    {
        $w = $this->makeBookingWorld(true);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['other'], $w['centre']);

        $crawler = $this->client->request('GET', '/');

        self::assertStringNotContainsString('solicita reservar', $this->bellText());
        self::assertCount(0, $crawler->filter('a[href*="scope=managed"]'));
    }

    public function testTheValidationLinkOpensTheListAlreadyFiltered(): void
    {
        $w = $this->makeBookingWorld(true);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-16', [$w['slots'][2][0]]);
        $this->loginAs($w['manager'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/listado?scope=managed&status=pending&from=all');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Ana Propietaria');
    }

    public function testAPlainTeacherCannotWidenTheListToOthersBookings(): void
    {
        $w = $this->makeBookingWorld(true);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['other'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/listado?scope=managed&from=all');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('tbody tr'), 'sin permiso de gestión, «managed» no amplía el listado');
    }

    public function testAManagerSeesOnlyTheCategoriesTheyManageInTheManagedScope(): void
    {
        $w = $this->makeBookingWorld(true);
        $otherCategory = (new \App\Entity\ResourceCategory())->setEducationalCentre($w['centre'])->setName('Carros')->addManager($w['other']);
        $cart = (new \App\Entity\SharedResource())->setCategory($otherCategory)->setName('Carro 1');
        $this->persist($otherCategory, $cart);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->makeBooking($w['year'], $cart, $w['owner'], '2026-09-16', [$w['slots'][2][0]]);
        $this->loginAs($w['manager'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/listado?scope=managed&from=all');

        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Aula de informática');
        self::assertSelectorTextNotContains('tbody', 'Carro 1');
    }

    /** El texto que pinta la campana para el usuario con sesión. */
    private function bellText(): string
    {
        return $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render()->crawler()->text();
    }

    /**
     * @param array<string, mixed> $w
     */
    private function book(array $w, string $date, string $slotId): void
    {
        $crawler = $this->client->request('GET', '/reservas/nueva');
        $token   = $crawler->filter('form[data-controller*="booking-form"] [name="_token"]')->attr('value');
        $this->client->request('POST', '/reservas/nueva', [
            '_token'   => $token,
            'resource' => $w['resource']->getId()->toRfc4122(),
            'date'     => $date,
            'slots'    => [$slotId],
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(ResourceBooking::class)->findAll());
    }
}
