<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EmailNotificationLogRepository;
use App\Tests\Integration\RepositoryTestCase;

class EmailNotificationLogRepositoryTest extends RepositoryTestCase
{
    private EmailNotificationLogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EmailNotificationLogRepository $repo */
        $repo       = self::getContainer()->get(EmailNotificationLogRepository::class);
        $this->repo = $repo;
    }

    public function testCreateFilteredQueryIsScopedToTheGivenCentre(): void
    {
        $centreA = $this->makeCentre('a');
        $centreB = $this->makeCentre('b');
        $this->makeLog($centreA, eventKey: 'report_created');
        $this->makeLog($centreB, eventKey: 'report_created');

        $results = $this->repo->createFilteredQuery($centreA)->getResult();

        self::assertCount(1, $results);
    }

    public function testCreateFilteredQuerySearchMatchesRecipientName(): void
    {
        $centre   = $this->makeCentre();
        $teacher  = $this->makeTeacher('search.recipient', 'Marta', 'Ruiz');
        $this->persist($teacher);
        $this->makeLog($centre, recipient: $teacher, recipientName: 'Marta Ruiz');

        self::assertCount(1, $this->repo->createFilteredQuery($centre, ['search' => 'marta'])->getResult());
        self::assertCount(1, $this->repo->createFilteredQuery($centre, ['search' => 'ruiz'])->getResult());
        self::assertCount(0, $this->repo->createFilteredQuery($centre, ['search' => 'nadie'])->getResult());
    }

    public function testCreateFilteredQuerySearchMatchesSubject(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, subject: 'Aviso de parte registrado');

        self::assertCount(1, $this->repo->createFilteredQuery($centre, ['search' => 'parte registrado'])->getResult());
        self::assertCount(0, $this->repo->createFilteredQuery($centre, ['search' => 'sanción'])->getResult());
    }

    public function testCreateFilteredQueryFiltersByEventKey(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, eventKey: 'report_created');
        $this->makeLog($centre, eventKey: 'report_deleted');

        $results = $this->repo->createFilteredQuery($centre, ['eventKey' => 'report_created'])->getResult();

        self::assertCount(1, $results);
        self::assertSame('report_created', $results[0]->getEventKey());
    }

    public function testCreateFilteredQueryFiltersByStatus(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, success: true);
        $this->makeLog($centre, success: false);

        $successOnly = $this->repo->createFilteredQuery($centre, ['status' => 'success'])->getResult();
        self::assertCount(1, $successOnly);
        self::assertTrue($successOnly[0]->isSuccess());

        $failedOnly = $this->repo->createFilteredQuery($centre, ['status' => 'failed'])->getResult();
        self::assertCount(1, $failedOnly);
        self::assertFalse($failedOnly[0]->isSuccess());

        self::assertCount(2, $this->repo->createFilteredQuery($centre)->getResult());
    }

    public function testCreateFilteredQueryFiltersByDateRange(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, sentAt: new \DateTimeImmutable('-10 days'));
        $inRange = $this->makeLog($centre, sentAt: new \DateTimeImmutable('-2 days'));

        $results = $this->repo->createFilteredQuery($centre, [
            'dateFrom' => (new \DateTimeImmutable('-5 days'))->format('Y-m-d'),
            'dateTo'   => (new \DateTimeImmutable())->format('Y-m-d'),
        ])->getResult();

        self::assertCount(1, $results);
        self::assertSame($inRange->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCreateFilteredQueryIgnoresInvalidDates(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre);

        $results = $this->repo->createFilteredQuery($centre, ['dateFrom' => 'no es una fecha'])->getResult();

        self::assertCount(1, $results);
    }

    public function testCreateFilteredQueryOrdersBySentAtDescending(): void
    {
        $centre = $this->makeCentre();
        $older  = $this->makeLog($centre, sentAt: new \DateTimeImmutable('-2 days'));
        $newer  = $this->makeLog($centre, sentAt: new \DateTimeImmutable());

        $results = $this->repo->createFilteredQuery($centre)->getResult();

        self::assertCount(2, $results);
        self::assertSame($newer->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
        self::assertSame($older->getId()->toRfc4122(), $results[1]->getId()->toRfc4122());
    }

    public function testFindDistinctEventKeysReturnsSortedUniqueKeysForCentre(): void
    {
        $centreA = $this->makeCentre('a');
        $centreB = $this->makeCentre('b');
        $this->makeLog($centreA, eventKey: 'report_deleted');
        $this->makeLog($centreA, eventKey: 'report_created');
        $this->makeLog($centreA, eventKey: 'report_created');
        $this->makeLog($centreB, eventKey: 'sanction_notified');

        self::assertSame(['report_created', 'report_deleted'], $this->repo->findDistinctEventKeys($centreA));
    }

    public function testDeleteOlderThanRemovesOldEntriesAcrossAllCentres(): void
    {
        $centreA = $this->makeCentre('a');
        $centreB = $this->makeCentre('b');
        $this->makeLog($centreA, sentAt: new \DateTimeImmutable('-100 days'));
        $this->makeLog($centreB, sentAt: new \DateTimeImmutable('-100 days'));
        $recent = $this->makeLog($centreA, sentAt: new \DateTimeImmutable('-1 day'));

        $deleted = $this->repo->deleteOlderThan(new \DateTimeImmutable('-90 days'));

        self::assertSame(2, $deleted);
        $remaining = $this->repo->createFilteredQuery($centreA)->getResult();
        self::assertCount(1, $remaining);
        self::assertSame($recent->getId()->toRfc4122(), $remaining[0]->getId()->toRfc4122());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeCentre(string $suffix = ''): EducationalCentre
    {
        $centre = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . uniqid('', true)), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $this->persist($centre);

        return $centre;
    }

    private function makeTeacher(string $username, string $firstName = 'Test', string $lastName = 'Teacher'): Teacher
    {
        return (new Teacher(new PersonName($firstName, $lastName)))->setUsername($username);
    }

    private function makeLog(
        EducationalCentre $centre,
        ?Teacher $recipient = null,
        string $recipientName = 'Test Teacher',
        string $eventKey = 'report_created',
        string $subject = 'Asunto de prueba',
        bool $success = true,
        ?string $errorMessage = null,
        ?\DateTimeImmutable $sentAt = null,
    ): EmailNotificationLog {
        $log = new EmailNotificationLog(
            $centre,
            $recipient,
            $recipientName,
            $eventKey,
            $subject,
            $success,
            $errorMessage,
            $sentAt ?? new \DateTimeImmutable(),
        );
        $this->persist($log);

        return $log;
    }
}
