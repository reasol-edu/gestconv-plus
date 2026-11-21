<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Message\HeartbeatMessage;
use App\Message\PurgeActivityLogMessage;
use App\Messenger\RestoreLockMiddleware;
use App\Service\Backup\RestoreState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Mientras se restaura una copia el worker deja la base de datos en paz: los turnos del planificador se saltan y los demás mensajes esperan. */
final class RestoreLockMiddlewareTest extends TestCase
{
    private string $dir;
    private RestoreState $state;
    private RestoreLockMiddleware $middleware;

    protected function setUp(): void
    {
        $this->dir        = sys_get_temp_dir() . '/gestconv-restore-lock-' . bin2hex(random_bytes(6));
        $this->state      = new RestoreState($this->dir);
        $this->middleware = new RestoreLockMiddleware($this->state);
    }

    protected function tearDown(): void
    {
        $this->state->release();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function lock(): void
    {
        self::assertTrue($this->state->acquire('test', 'copia.zip'));
        $this->state->attachPid((int) getmypid());
        self::assertTrue($this->state->isRunning());
    }

    /** @return array{StackInterface, \stdClass} la pila, y un contador de cuántas veces se ejecutó el siguiente middleware */
    private function stack(): array
    {
        $calls    = new \stdClass();
        $calls->n = 0;

        $next = new class($calls) implements MiddlewareInterface {
            public function __construct(private readonly \stdClass $calls) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ++$this->calls->n;

                return $envelope;
            }
        };
        $stack = new class($next) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next) {}

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };

        return [$stack, $calls];
    }

    public function testWithNoRestoreRunningEverythingGoesThrough(): void
    {
        [$stack, $calls] = $this->stack();

        $this->middleware->handle((new Envelope(new \stdClass()))->with(new ReceivedStamp('async')), $stack);

        self::assertSame(1, $calls->n);
    }

    public function testAMessageTheWorkerReceivesWaitsWithoutUsingUpItsRetries(): void
    {
        $this->lock();
        [$stack, $calls] = $this->stack();

        try {
            $this->middleware->handle((new Envelope(new \stdClass()))->with(new ReceivedStamp('async')), $stack);
            self::fail('El mensaje debe devolverse a la cola.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(30_000, $e->getRetryDelay());
            self::assertTrue($e->forceRetry());
        }
        self::assertSame(0, $calls->n);
    }

    public function testASchedulerTickIsSkippedBecauseTheSameTaskComesRoundAgain(): void
    {
        $this->lock();
        [$stack, $calls] = $this->stack();

        $envelope = (new Envelope(new PurgeActivityLogMessage()))->with(new ReceivedStamp('scheduler_default'));
        $result   = $this->middleware->handle($envelope, $stack);

        self::assertSame($envelope, $result);
        self::assertSame(0, $calls->n, 'La tarea no se ejecutó.');
    }

    public function testTheWorkersHeartbeatStillGoesThroughBecauseItOnlyWritesAFile(): void
    {
        $this->lock();
        [$stack, $calls] = $this->stack();

        $this->middleware->handle((new Envelope(new HeartbeatMessage()))->with(new ReceivedStamp('scheduler_default')), $stack);

        self::assertSame(1, $calls->n);
    }

    public function testAMessageNotComingFromAWorkerIsNotHeldBack(): void
    {
        $this->lock();
        [$stack, $calls] = $this->stack();

        // Enviado desde la consola o la web (sin ReceivedStamp): la propia restauración puede necesitar enviar cosas.
        $this->middleware->handle(new Envelope(new \stdClass()), $stack);

        self::assertSame(1, $calls->n);
    }
}
