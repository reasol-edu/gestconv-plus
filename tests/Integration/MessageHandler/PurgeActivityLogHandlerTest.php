<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\ActivityLog;
use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\MessageHandler\PurgeActivityLogHandler;
use App\Message\PurgeActivityLogMessage;
use App\Repository\ActivityLogRepository;
use App\Tests\Integration\RepositoryTestCase;

class PurgeActivityLogHandlerTest extends RepositoryTestCase
{
    private PurgeActivityLogHandler $handler;
    private ActivityLogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDefinition();

        /** @var PurgeActivityLogHandler $handler */
        $handler       = self::getContainer()->get(PurgeActivityLogHandler::class);
        $this->handler = $handler;

        /** @var ActivityLogRepository $logs */
        $logs       = self::getContainer()->get(ActivityLogRepository::class);
        $this->logs = $logs;
    }

    public function testDeletesEntriesOlderThanConfiguredDays(): void
    {
        $this->makeLog(new \DateTimeImmutable('-100 days'));
        $this->setDays(30);

        ($this->handler)(new PurgeActivityLogMessage());

        self::assertSame(0, $this->countAll());
    }

    public function testKeepsEntriesWithinRetentionWindow(): void
    {
        $this->makeLog(new \DateTimeImmutable('-5 days'));
        $this->setDays(30);

        ($this->handler)(new PurgeActivityLogMessage());

        self::assertSame(1, $this->countAll());
    }

    public function testZeroDaysDisablesPurging(): void
    {
        $this->makeLog(new \DateTimeImmutable('-1000 days'));
        $this->setDays(0);

        ($this->handler)(new PurgeActivityLogMessage());

        self::assertSame(1, $this->countAll());
    }

    public function testUsesDefaultOfNinetyDaysWhenUnset(): void
    {
        $this->makeLog(new \DateTimeImmutable('-91 days'));
        $this->makeLog(new \DateTimeImmutable('-89 days'));

        ($this->handler)(new PurgeActivityLogMessage());

        self::assertSame(1, $this->countAll());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeLog(\DateTimeImmutable $createdAt): ActivityLog
    {
        $log = new ActivityLog($createdAt, '127.0.0.1', 'test.action');
        $this->persist($log);

        return $log;
    }

    private function countAll(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(ActivityLog::class, 'l')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function seedDefinition(): void
    {
        $definition = (new SettingDefinition())
            ->setKey('notifications.log_retention_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('90')
            ->setGlobalScope(true)
            ->setCentreScope(false)
            ->setMinValue(0)
            ->setMaxValue(3650);
        $this->persist($definition);
    }

    private function setDays(int $days): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'notifications.log_retention_days']);

        $value = (new GlobalSettingValue())
            ->setDefinition($definition)
            ->setValue((string) $days);
        $this->persist($value);
    }
}
