<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\GlobalSettingValue;
use App\Entity\Group;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Integration\ResourceBookingScenarioTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class ResourceBookingControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use ResourceBookingScenarioTrait;

    protected function setUp(): void
    {
        // Lunes 2026-09-14: «hoy» no depende del día real de ejecución.
        self::mockTime('2026-09-14 09:00:00');
        parent::setUp();
    }

    // ── acceso ────────────────────────────────────────────────────────────────

    public function testTeacherOfTheYearSeesTheCalendar(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $this->client->request('GET', '/reservas');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reservas');
    }

    public function testTeacherOutsideTheYearIsDenied(): void
    {
        $w = $this->makeBookingWorld();
        $stranger = (new Teacher(new \App\Entity\PersonName('Ajeno', 'Total')))->setUsername('stranger.book.' . uniqid('', false));
        $this->persist($stranger);
        $this->loginAs($stranger, $w['centre']);

        $this->client->request('GET', '/reservas');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSectionIsNotFoundWhenItIsSwitchedOff(): void
    {
        $w = $this->makeBookingWorld();
        $this->switchOffTheSection();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/reservas');

        self::assertResponseStatusCodeSame(404);
    }

    // ── calendario ────────────────────────────────────────────────────────────

    public function testWeekViewShowsTheBookingsOfTheWeekAndHidesTheRejectedOnes(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-16', [$w['slots'][2][1]], ResourceBookingStatus::Rejected);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-22', [$w['slots'][1][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas?date=2026-09-14');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('td a[href*="/reservas/"]'), 'solo la reserva aprobada de esa semana');
        self::assertSelectorTextContains('tbody', 'Ana Propietaria');
    }

    public function testMonthViewListsTheBookingsOfTheMonth(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-29', [$w['slots'][1][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas?view=month&date=2026-09-01');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('a[href*="/reservas/"][title]'));
        self::assertSelectorTextContains('h2', 'Septiembre 2026');
    }

    public function testCalendarFiltersByTeacherAndByGroup(): void
    {
        $w = $this->makeBookingWorld();
        $mine  = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $mine->setGroups([$w['group']]);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-16', [$w['slots'][2][0]]);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $byTeacher = $this->client->request('GET', '/reservas?date=2026-09-14&teacher=' . $w['other']->getId()->toRfc4122());
        self::assertCount(1, $byTeacher->filter('td a[href*="/reservas/"]'));
        self::assertSelectorTextContains('tbody', 'Otro Docente');

        $byGroup = $this->client->request('GET', '/reservas?date=2026-09-14&group=' . $w['group']->getId()->toRfc4122());
        self::assertCount(1, $byGroup->filter('td a[href*="/reservas/"]'));
        self::assertSelectorTextContains('tbody', 'Ana Propietaria');

        $byCategory = $this->client->request('GET', '/reservas?date=2026-09-14&category=' . $w['category']->getId()->toRfc4122());
        self::assertCount(2, $byCategory->filter('td a[href*="/reservas/"]'));
    }

    // ── alta ──────────────────────────────────────────────────────────────────

    public function testTeacherBooksAResource(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()], 'groups' => [$w['group']->getId()->toRfc4122()], 'description' => 'Práctica de redes']);

        self::assertResponseRedirects();
        $bookings = $this->allBookings();
        self::assertCount(1, $bookings);
        self::assertSame(ResourceBookingStatus::Approved, $bookings[0]->getStatus());
        self::assertSame('Ana Propietaria', $bookings[0]->getTeacher()->getName()->full());
        self::assertSame('2026-09-15', $bookings[0]->getDate()->format('Y-m-d'));
        self::assertSame('Práctica de redes', $bookings[0]->getDescription());
        self::assertCount(1, $bookings[0]->getGroups());
        self::assertCount(1, $bookings[0]->getTimeSlots());
    }

    public function testNewFormOffersOnlyTheTimeSlotsOfEachWeekday(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/nueva?date=2026-09-15');

        self::assertResponseIsSuccessful();
        self::assertCount(10, $crawler->filter('input[name="slots[]"]'), '2 tramos × 5 días, filtrados en el navegador');
        self::assertSame('2026-09-15', $crawler->filter('#date')->attr('value'));
    }

    public function testAnOccupiedSlotIsRefusedAndNothingIsCreated(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-15', [$w['slots'][1][0], $w['slots'][1][1]]);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][1]->getId()->toRfc4122()]]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'ya está reservado');
        self::assertCount(1, $this->allBookings());
    }

    public function testAnotherSlotOfTheSameDayIsFree(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-15', [$w['slots'][1][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][1]->getId()->toRfc4122()]]);

        self::assertResponseRedirects();
        self::assertCount(2, $this->allBookings());
    }

    public function testAPendingBookingAlsoOccupiesTheSlot(): void
    {
        $w = $this->makeBookingWorld(true);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->allBookings());
    }

    public function testARejectedBookingFreesTheSlot(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Rejected);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);

        self::assertResponseRedirects();
        self::assertCount(2, $this->allBookings());
    }

    public function testNonWorkingDaysAreRefused(): void
    {
        $w = $this->makeBookingWorld();
        $this->persist((new NonWorkingDay())->setAcademicYear($w['year'])->setDate(new \DateTimeImmutable('2026-09-15'))->setDescription('Festivo local'));
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);

        self::assertSelectorTextContains('body', 'no es lectivo');
        self::assertCount(0, $this->allBookings());
    }

    public function testATeacherCannotBookInThePastButTheAdminCan(): void
    {
        $w = $this->makeBookingWorld();
        $slot = $w['slots'][0][0]->getId()->toRfc4122(); // lunes
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-07', 'slots' => [$slot]]);
        self::assertSelectorTextContains('body', 'fecha pasada');
        self::assertCount(0, $this->allBookings());

        $this->loginAs($w['admin'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-07', 'slots' => [$slot]]);
        self::assertResponseRedirects();
        self::assertCount(1, $this->allBookings());
    }

    public function testTheCentreSettingLimitsHowFarAheadATeacherCanBook(): void
    {
        $w = $this->makeBookingWorld();
        $this->setCentreAdvanceLimit(10);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-10-05', 'slots' => [$w['slots'][0][0]->getId()->toRfc4122()]]);
        self::assertSelectorTextContains('body', 'antelación máxima');
        self::assertCount(0, $this->allBookings());

        $this->postNew($w, ['date' => '2026-09-21', 'slots' => [$w['slots'][0][0]->getId()->toRfc4122()]]);
        self::assertResponseRedirects();
        self::assertCount(1, $this->allBookings());
    }

    public function testTheCategoryLimitOverridesTheCentreSettingAndManagersAreExempt(): void
    {
        $w = $this->makeBookingWorld();
        $this->setCentreAdvanceLimit(100);
        $w['category']->setMaxAdvanceDays(3);
        $this->flush();
        $slot = $w['slots'][0][0]->getId()->toRfc4122();

        $this->loginAs($w['owner'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-21', 'slots' => [$slot]]);
        self::assertSelectorTextContains('body', 'antelación máxima');

        $this->loginAs($w['manager'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-21', 'slots' => [$slot]]);
        self::assertResponseRedirects();
    }

    public function testSlotsOfAnotherWeekdayAreRefused(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        // 2026-09-15 es martes, el tramo es del lunes.
        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][0][0]->getId()->toRfc4122()]]);

        self::assertSelectorTextContains('body', 'no corresponde al día de la semana');
        self::assertCount(0, $this->allBookings());
    }

    public function testBookingNeedsAtLeastOneSlot(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => []]);

        self::assertSelectorTextContains('body', 'Elige al menos un tramo');
        self::assertCount(0, $this->allBookings());
    }

    public function testARestrictedCategoryCanOnlyBeBookedByItsManagersAndTheDirection(): void
    {
        $w = $this->makeBookingWorld();
        $w['category']->setRestricted(true);
        $this->flush();
        $slot = $w['slots'][1][0]->getId()->toRfc4122();

        $this->loginAs($w['owner'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$slot]]);
        self::assertSelectorTextContains('body', 'No puedes reservar ese recurso');
        self::assertCount(0, $this->allBookings());

        $this->loginAs($w['manager'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$slot]]);
        self::assertResponseRedirects();
        self::assertCount(1, $this->allBookings());
    }

    public function testAnInactiveResourceCannotBeBooked(): void
    {
        $w = $this->makeBookingWorld();
        $w['resource']->setActive(false);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);

        self::assertSelectorTextContains('body', 'No puedes reservar ese recurso');
        self::assertCount(0, $this->allBookings());
    }

    // ── a nombre de otro docente ──────────────────────────────────────────────

    public function testTheAdminBooksOnBehalfOfAnotherTeacher(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['admin'], $w['centre']);

        $this->postNew($w, ['teacher' => $w['other']->getId()->toRfc4122(), 'date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);

        self::assertResponseRedirects();
        $bookings = $this->allBookings();
        self::assertSame('Otro Docente', $bookings[0]->getTeacher()->getName()->full());
        self::assertSame(ResourceBookingStatus::Approved, $bookings[0]->getStatus());
    }

    public function testAPlainTeacherCannotBookOnBehalfOfAnotherOne(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/nueva');
        self::assertCount(0, $crawler->filter('select#teacher'), 'sin selector de docente');

        $this->postNew($w, ['teacher' => $w['other']->getId()->toRfc4122(), 'date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);

        self::assertSelectorTextContains('body', 'No puedes reservar a nombre de ese docente');
        self::assertCount(0, $this->allBookings());
    }

    // ── validación ────────────────────────────────────────────────────────────

    public function testABookingInACategoryWithApprovalNeedsValidationUnlessItIsAManager(): void
    {
        $w = $this->makeBookingWorld(true);
        $slot = $w['slots'][1][0]->getId()->toRfc4122();

        $this->loginAs($w['owner'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$slot]]);
        self::assertResponseRedirects();

        $this->loginAs($w['manager'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-16', 'slots' => [$w['slots'][2][0]->getId()->toRfc4122()]]);
        self::assertResponseRedirects();

        $byDate = [];
        foreach ($this->allBookings() as $booking) {
            $byDate[$booking->getDate()->format('Y-m-d')] = $booking->getStatus();
        }
        self::assertSame(ResourceBookingStatus::Pending, $byDate['2026-09-15']);
        self::assertSame(ResourceBookingStatus::Approved, $byDate['2026-09-16']);
    }

    public function testAManagerApprovesAPendingBooking(): void
    {
        $w = $this->makeBookingWorld(true);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['manager'], $w['centre']);

        $this->resolve($booking, 'validar');

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(ResourceBooking::class)->find($booking->getId());
        self::assertSame(ResourceBookingStatus::Approved, $saved?->getStatus());
        self::assertFalse($saved->isResolutionSeen(), 'el docente aún no ha visto la resolución');
    }

    public function testAManagerRejectsAPendingBookingWithAReasonAndTheSlotIsFreed(): void
    {
        $w = $this->makeBookingWorld(true);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['manager'], $w['centre']);

        $this->resolve($booking, 'rechazar', ['reason' => 'Hay examen ese día']);

        $this->em->clear();
        $saved = $this->em->getRepository(ResourceBooking::class)->find($booking->getId());
        self::assertSame(ResourceBookingStatus::Rejected, $saved?->getStatus());
        self::assertSame('Hay examen ese día', $saved->getRejectionReason());

        $this->loginAs($w['other'], $w['centre']);
        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()]]);
        self::assertResponseRedirects();
    }

    public function testTheOwnerCannotValidateTheirOwnPendingBooking(): void
    {
        $w = $this->makeBookingWorld(true);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]], ResourceBookingStatus::Pending);
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/validar"]'));

        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/validar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEditingAnApprovedBookingOfACategoryWithApprovalSendsItBackToPending(): void
    {
        $w = $this->makeBookingWorld(true);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postEdit($booking, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][1]->getId()->toRfc4122()]]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(ResourceBookingStatus::Pending, $this->em->getRepository(ResourceBooking::class)->find($booking->getId())?->getStatus());
    }

    public function testEditingOnlyTheDescriptionKeepsAnApprovedBookingApproved(): void
    {
        $w = $this->makeBookingWorld(true);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postEdit($booking, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()], 'description' => 'Nueva nota']);

        $this->em->clear();
        $saved = $this->em->getRepository(ResourceBooking::class)->find($booking->getId());
        self::assertSame(ResourceBookingStatus::Approved, $saved?->getStatus());
        self::assertSame('Nueva nota', $saved->getDescription());
    }

    // ── periodicidad ──────────────────────────────────────────────────────────

    public function testWeeklyRepetitionShowsAPreviewAndCreatesOnlyTheFreeDates(): void
    {
        $w = $this->makeBookingWorld();
        $slot = $w['slots'][1][0];
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-22', [$slot]);
        $this->persist((new NonWorkingDay())->setAcademicYear($w['year'])->setDate(new \DateTimeImmutable('2026-09-29'))->setDescription('Festivo'));
        $this->loginAs($w['owner'], $w['centre']);

        $fields = ['date' => '2026-09-15', 'slots' => [$slot->getId()->toRfc4122()], 'repeat' => '1', 'repeat_until' => '2026-10-06'];
        $crawler = $this->postNew($w, $fields);

        // Previsualización: 4 martes (15/9, 22/9, 29/9, 6/10); solo 2 libres.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Fechas de la reserva periódica');
        self::assertCount(4, $crawler->filter('input[name="dates[]"]'));
        self::assertCount(2, $crawler->filter('input[name="dates[]"]:not([disabled])'));
        self::assertCount(1, $this->allBookings(), 'la previsualización no crea nada');

        // Confirmar crea las libres marcadas.
        $form = $crawler->filter('form[action$="/reservas/nueva"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $dates = array_map(static fn (ResourceBooking $b): string => $b->getDate()->format('Y-m-d'), $this->allBookings());
        sort($dates);
        self::assertSame(['2026-09-15', '2026-09-22', '2026-10-06'], $dates);

        $series = array_values(array_filter($this->allBookings(), static fn (ResourceBooking $b): bool => $b->getSeriesId() !== null));
        self::assertCount(2, $series);
        self::assertTrue($series[0]->getSeriesId()?->equals($series[1]->getSeriesId() ?? $series[0]->getId()));
    }

    public function testTheUserCanUntickDatesInThePreview(): void
    {
        $w = $this->makeBookingWorld();
        $slot = $w['slots'][1][0];
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$slot->getId()->toRfc4122()], 'repeat' => '1', 'repeat_until' => '2026-09-29']);
        $form = $crawler->filter('form[action$="/reservas/nueva"]')->form();
        $form['dates']['1']->untick();
        $this->client->submit($form);

        $dates = array_map(static fn (ResourceBooking $b): string => $b->getDate()->format('Y-m-d'), $this->allBookings());
        sort($dates);
        self::assertSame(['2026-09-15', '2026-09-29'], $dates);
    }

    public function testRepetitionNeedsAnEndDateNotBeforeTheStart(): void
    {
        $w = $this->makeBookingWorld();
        $this->loginAs($w['owner'], $w['centre']);

        $this->postNew($w, ['date' => '2026-09-15', 'slots' => [$w['slots'][1][0]->getId()->toRfc4122()], 'repeat' => '1', 'repeat_until' => '2026-09-01']);

        self::assertSelectorTextContains('body', 'fecha final');
        self::assertCount(0, $this->allBookings());
    }

    // ── edición y baja ────────────────────────────────────────────────────────

    public function testTheOwnerEditsTheirBooking(): void
    {
        $w = $this->makeBookingWorld();
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $this->postEdit($booking, ['date' => '2026-09-16', 'slots' => [$w['slots'][2][1]->getId()->toRfc4122()], 'description' => 'Cambio']);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(ResourceBooking::class)->find($booking->getId());
        self::assertSame('2026-09-16', $saved?->getDate()->format('Y-m-d'));
        self::assertSame('Cambio', $saved->getDescription());
    }

    public function testAnotherTeacherCannotEditOrDeleteIt(): void
    {
        $w = $this->makeBookingWorld();
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->loginAs($w['other'], $w['centre']);

        $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122() . '/editar');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/eliminar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/editar"]'));
    }

    public function testTheManagerOfTheCategoryAndTheAdminCanEditAndDeleteOthersBookings(): void
    {
        $w = $this->makeBookingWorld();
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);

        $this->loginAs($w['manager'], $w['centre']);
        $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122() . '/editar');
        self::assertResponseIsSuccessful();

        $this->loginAs($w['admin'], $w['centre']);
        $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122() . '/editar');
        self::assertResponseIsSuccessful();

        $this->deleteBooking($booking);
        self::assertResponseRedirects();
        self::assertCount(0, $this->allBookings());
    }

    public function testAManagerOfAnotherCategoryCannotEdit(): void
    {
        $w = $this->makeBookingWorld();
        $other = (new \App\Entity\ResourceCategory())->setEducationalCentre($w['centre'])->setName('Carros')->addManager($w['other']);
        $this->persist($other);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->loginAs($w['other'], $w['centre']);

        $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditingTheWholeSeriesChangesTheFutureOccurrencesOnly(): void
    {
        $w = $this->makeBookingWorld();
        $series = \Symfony\Component\Uid\Uuid::v7();
        $past   = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-07', [$w['slots'][0][0]])->setSeriesId($series);
        $first  = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-21', [$w['slots'][0][0]])->setSeriesId($series);
        $second = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-28', [$w['slots'][0][0]])->setSeriesId($series);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->postEdit($first, [
            'date' => '2026-09-21', 'slots' => [$w['slots'][0][1]->getId()->toRfc4122()], 'description' => 'Toda la serie', 'scope' => 'series',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $repository = $this->em->getRepository(ResourceBooking::class);
        foreach ([$first, $second] as $changed) {
            $saved = $repository->find($changed->getId());
            self::assertSame('Toda la serie', $saved?->getDescription());
            self::assertSame($w['slots'][0][1]->getId()->toRfc4122(), $saved->getTimeSlots()->first()->getId()->toRfc4122());
        }
        self::assertNull($repository->find($past->getId())?->getDescription(), 'lo pasado conserva su historial');
    }

    public function testEditingOnlyOneOfASeriesLeavesTheRest(): void
    {
        $w = $this->makeBookingWorld();
        $series = \Symfony\Component\Uid\Uuid::v7();
        $first  = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-21', [$w['slots'][0][0]])->setSeriesId($series);
        $second = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-28', [$w['slots'][0][0]])->setSeriesId($series);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->postEdit($first, ['date' => '2026-09-21', 'slots' => [$w['slots'][0][0]->getId()->toRfc4122()], 'description' => 'Solo esta', 'scope' => 'single']);

        $this->em->clear();
        $repository = $this->em->getRepository(ResourceBooking::class);
        self::assertSame('Solo esta', $repository->find($first->getId())?->getDescription());
        self::assertNull($repository->find($second->getId())?->getDescription());
    }

    public function testDeletingTheWholeSeriesRemovesTheFutureOccurrences(): void
    {
        $w = $this->makeBookingWorld();
        $series = \Symfony\Component\Uid\Uuid::v7();
        $past   = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-07', [$w['slots'][0][0]])->setSeriesId($series);
        $first  = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-21', [$w['slots'][0][0]])->setSeriesId($series);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-28', [$w['slots'][0][0]])->setSeriesId($series);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->deleteBooking($first, 'series');

        self::assertResponseRedirects();
        $remaining = $this->allBookings();
        self::assertCount(1, $remaining);
        self::assertTrue($remaining[0]->getId()->equals($past->getId()));
    }

    public function testDeletingASingleBookingKeepsTheSeries(): void
    {
        $w = $this->makeBookingWorld();
        $series = \Symfony\Component\Uid\Uuid::v7();
        $first  = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-21', [$w['slots'][0][0]])->setSeriesId($series);
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-28', [$w['slots'][0][0]])->setSeriesId($series);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->deleteBooking($first, 'single');

        self::assertCount(1, $this->allBookings());
    }

    // ── listado ───────────────────────────────────────────────────────────────

    public function testTheListShowsTheOwnBookingsToATeacher(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $this->makeBooking($w['year'], $w['resource'], $w['other'], '2026-09-16', [$w['slots'][2][0]]);
        $this->loginAs($w['owner'], $w['centre']);

        $crawler = $this->client->request('GET', '/reservas/listado');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Ana Propietaria');
        self::assertSelectorTextNotContains('tbody', 'Otro Docente');
    }

    public function testVisitingTheListMarksTheResolutionsAsSeen(): void
    {
        $w = $this->makeBookingWorld(true);
        $booking = $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]]);
        $booking->setResolutionSeen(false);
        $this->flush();
        $this->loginAs($w['owner'], $w['centre']);

        $this->client->request('GET', '/reservas/listado');

        $this->em->clear();
        self::assertTrue($this->em->getRepository(ResourceBooking::class)->find($booking->getId())?->isResolutionSeen());
    }

    // ── consultas ─────────────────────────────────────────────────────────────

    public function testCalendarAndListQueryCountsDoNotGrowWithTheNumberOfBookings(): void
    {
        $w = $this->makeBookingWorld();
        $this->makeBooking($w['year'], $w['resource'], $w['owner'], '2026-09-15', [$w['slots'][1][0]])->setGroups([$w['group']]);
        $this->flush();
        $this->loginAs($w['admin'], $w['centre']);

        $urls = ['/reservas?date=2026-09-14', '/reservas?view=month&date=2026-09-01', '/reservas/listado'];
        $yearId     = $w['year']->getId();
        $resourceId = $w['resource']->getId();
        $groupId    = $w['group']->getId();
        $slotIds    = array_map(static fn (array $day): array => array_map(static fn ($slot) => $slot->getId(), $day), $w['slots']);

        $withOne = [];
        foreach ($urls as $url) {
            $withOne[$url] = $this->countQueries($url);
        }

        // countQueries() vacía el mapa de identidad: se vuelve a leer lo que hace falta.
        $year     = $this->em->find(AcademicYear::class, $yearId);
        $resource = $this->em->find(SharedResource::class, $resourceId);
        $group    = $this->em->find(Group::class, $groupId);
        self::assertNotNull($year);
        self::assertNotNull($resource);
        self::assertNotNull($group);
        for ($i = 0; $i < 6; ++$i) {
            $teacher = (new Teacher(new PersonName('Extra', 'Docente ' . $i)))->setUsername('extra.' . $i . uniqid('', false));
            $year->addTeacher($teacher);
            $this->persist($teacher);
            $day   = $i % 4 + 1;
            $slots = array_map(fn ($id) => $this->em->find(TimeSlot::class, $id), $slotIds[$day]);
            self::assertNotContains(null, $slots);
            $this->makeBooking($year, $resource, $teacher, '2026-09-' . (15 + $i % 4), $slots)->setGroups([$group]);
        }
        $this->flush();

        foreach ($urls as $url) {
            self::assertLessThanOrEqual($withOne[$url], $this->countQueries($url), $url);
        }
    }

    // ── utilidades ────────────────────────────────────────────────────────────

    /**
     * Rellena y envía el formulario de nueva reserva con el recurso del escenario.
     *
     * @param array<string, mixed> $w
     * @param array<string, mixed> $fields
     */
    private function postNew(array $w, array $fields): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/reservas/nueva');
        $token   = $crawler->filter('form[data-controller*="booking-form"] [name="_token"]')->attr('value');

        return $this->client->request('POST', '/reservas/nueva', $fields + [
            '_token'   => $token,
            'resource' => $w['resource']->getId()->toRfc4122(),
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function postEdit(ResourceBooking $booking, array $fields): void
    {
        $url     = '/reservas/' . $booking->getId()->toRfc4122() . '/editar';
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('form[data-controller*="booking-form"] [name="_token"]')->attr('value');

        $this->client->request('POST', $url, $fields + [
            '_token'   => $token,
            'resource' => $booking->getResource()->getId()->toRfc4122(),
        ]);
    }

    private function deleteBooking(ResourceBooking $booking, string $scope = 'single'): void
    {
        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/eliminar', ['_token' => $token, 'scope' => $scope]);
    }

    /**
     * @param array<string, string> $extra
     */
    private function resolve(ResourceBooking $booking, string $action, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/reservas/' . $booking->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/' . $action . '"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/reservas/' . $booking->getId()->toRfc4122() . '/' . $action, ['_token' => $token] + $extra);
    }

    /** @return list<ResourceBooking> */
    private function allBookings(): array
    {
        $this->em->clear();

        return array_values($this->em->getRepository(ResourceBooking::class)->findAll());
    }

    private function switchOffTheSection(): void
    {
        $definition = (new SettingDefinition())->setKey('features.bookings')->setType(SettingType::Boolean)->setCategory('settings.category.features')
            ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue('false'));
    }

    private function setCentreAdvanceLimit(int $days): void
    {
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => 'bookings.max_advance_days']);
        self::assertNotNull($definition);
        $this->persist((new GlobalSettingValue())->setDefinition($definition)->setValue((string) $days));
    }
}
