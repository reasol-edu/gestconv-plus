<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Message\HeartbeatMessage;
use App\Message\PurgeActivityLogMessage;
use App\Service\Backup\RestoreState;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** El middleware está de verdad en el bus por defecto (no solo probado a mano): un mensaje recibido por el worker espera si hay una restauración. */
class RestoreLockWiringTest extends RepositoryTestCase
{
    private function bus(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('messenger.default_bus');

        return $bus;
    }

    private function lockRestore(): void
    {
        /** @var RestoreState $state */
        $state = self::getContainer()->get(RestoreState::class);
        self::assertTrue($state->acquire('test', 'copia.zip'));
        $state->attachPid((int) getmypid());
    }

    public function testAMessageReceivedByTheWorkerWaitsWhileABackupIsBeingRestored(): void
    {
        $this->lockRestore();

        $this->expectException(RecoverableMessageHandlingException::class);
        $this->bus()->dispatch((new Envelope(new PurgeActivityLogMessage()))->with(new ReceivedStamp('async')));
    }

    public function testTheWorkersHeartbeatKeepsGoingDuringARestore(): void
    {
        $this->lockRestore();

        $result = $this->bus()->dispatch((new Envelope(new HeartbeatMessage()))->with(new ReceivedStamp('scheduler_default')));

        self::assertInstanceOf(Envelope::class, $result);
    }

    public function testTheSameMessageGoesThroughWhenNoRestoreIsRunning(): void
    {
        $result = $this->bus()->dispatch((new Envelope(new HeartbeatMessage()))->with(new ReceivedStamp('async')));

        self::assertInstanceOf(Envelope::class, $result);
    }
}
