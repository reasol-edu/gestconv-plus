<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\MessageHandler\PurgeEmailNotificationLogHandler;
use App\Message\PurgeEmailNotificationLogMessage;
use App\Repository\EmailNotificationLogRepository;
use App\Tests\Integration\RepositoryTestCase;

class PurgeEmailNotificationLogHandlerTest extends RepositoryTestCase
{
    private PurgeEmailNotificationLogHandler $handler;
    private EmailNotificationLogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDefinition();

        /** @var PurgeEmailNotificationLogHandler $handler */
        $handler       = self::getContainer()->get(PurgeEmailNotificationLogHandler::class);
        $this->handler = $handler;

        /** @var EmailNotificationLogRepository $logs */
        $logs       = self::getContainer()->get(EmailNotificationLogRepository::class);
        $this->logs = $logs;
    }

    public function testDeletesEntriesOlderThanConfiguredDays(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, sentAt: new \DateTimeImmutable('-100 days'));
        $this->setDays(30);

        ($this->handler)(new PurgeEmailNotificationLogMessage());

        self::assertCount(0, $this->logs->createFilteredQuery($centre)->getResult());
    }

    public function testKeepsEntriesWithinRetentionWindow(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, sentAt: new \DateTimeImmutable('-5 days'));
        $this->setDays(30);

        ($this->handler)(new PurgeEmailNotificationLogMessage());

        self::assertCount(1, $this->logs->createFilteredQuery($centre)->getResult());
    }

    public function testZeroDaysDisablesPurging(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, sentAt: new \DateTimeImmutable('-1000 days'));
        $this->setDays(0);

        ($this->handler)(new PurgeEmailNotificationLogMessage());

        self::assertCount(1, $this->logs->createFilteredQuery($centre)->getResult());
    }

    public function testUsesDefaultOfNinetyDaysWhenUnset(): void
    {
        $centre = $this->makeCentre();
        $this->makeLog($centre, sentAt: new \DateTimeImmutable('-91 days'));
        $kept = $this->makeLog($centre, sentAt: new \DateTimeImmutable('-89 days'));

        ($this->handler)(new PurgeEmailNotificationLogMessage());

        $remaining = $this->logs->createFilteredQuery($centre)->getResult();
        self::assertCount(1, $remaining);
        self::assertSame($kept->getId()->toRfc4122(), $remaining[0]->getId()->toRfc4122());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeCentre(): EducationalCentre
    {
        $centre = (new EducationalCentre())->setCode('41000' . substr(md5(uniqid('', true)), 0, 3))->setName('IES Test')->setCity('Sevilla');
        $this->persist($centre);

        return $centre;
    }

    private function makeLog(EducationalCentre $centre, \DateTimeImmutable $sentAt): EmailNotificationLog
    {
        $log = new EmailNotificationLog(
            $centre,
            null,
            'Test Teacher',
            'report_created',
            'Asunto de prueba',
            true,
            null,
            $sentAt,
        );
        $this->persist($log);

        return $log;
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
