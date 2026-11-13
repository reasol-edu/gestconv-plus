<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Backup;

use App\Service\Backup\RestoreState;
use PHPUnit\Framework\TestCase;

/** Bloqueo y resultado de una restauración, en ficheros. */
class RestoreStateTest extends TestCase
{
    private string $dir;
    private RestoreState $state;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/gc-restore-state-' . bin2hex(random_bytes(5));
        $this->state = new RestoreState($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testNothingIsRunningAtFirst(): void
    {
        self::assertFalse($this->state->isRunning());
        self::assertNull($this->state->restoredAt());
        self::assertNull($this->state->lastResult());
    }

    public function testALockCanBeTakenOnceAndReleased(): void
    {
        self::assertTrue($this->state->acquire('admin', 'copia.zip'));
        self::assertTrue($this->state->isRunning());
        self::assertFalse($this->state->acquire('otro', 'otra.zip'), 'No puede haber dos restauraciones a la vez.');
        self::assertSame('admin', $this->state->lockInfo()['by']);

        $this->state->release();

        self::assertFalse($this->state->isRunning());
        self::assertTrue($this->state->acquire('otro', 'otra.zip'));
    }

    public function testALiveProcessKeepsTheLock(): void
    {
        $this->state->acquire('admin', 'copia.zip');
        $this->state->attachPid((int) getmypid());

        self::assertTrue($this->state->isRunning());
    }

    public function testALockWhoseProcessDiedIsAbandoned(): void
    {
        $this->state->acquire('admin', 'copia.zip');
        // Un PID que no existe (por encima del máximo habitual de los sistemas).
        $this->state->attachPid(4_194_000);

        if (!function_exists('posix_kill')) {
            self::markTestSkipped('Sin la extensión posix no se puede saber si un proceso vive.');
        }
        self::assertFalse($this->state->isRunning(), 'Un fallo no debe dejar el sitio en 503 para siempre.');
        self::assertFalse(is_file($this->dir . '/restore.lock'), 'Y se limpia el bloqueo abandonado.');
    }

    public function testALockThatNeverGotAProcessExpiresAfterAMinute(): void
    {
        $this->writeLock(['started' => time() - 30, 'pid' => 0, 'by' => 'a', 'file' => 'f.zip']);
        self::assertTrue($this->state->isRunning(), 'Recién lanzado: el proceso hijo aún puede estar arrancando.');

        $this->writeLock(['started' => time() - 120, 'pid' => 0, 'by' => 'a', 'file' => 'f.zip']);
        self::assertFalse($this->state->isRunning(), 'Si no arrancó en un minuto, no arrancará.');
    }

    public function testAVeryOldLockIsAbandonedEvenWithALivePid(): void
    {
        $this->writeLock(['started' => time() - 5 * 3600, 'pid' => (int) getmypid(), 'by' => 'a', 'file' => 'f.zip']);

        self::assertFalse($this->state->isRunning());
    }

    public function testForceUnlockRemovesAStuckLock(): void
    {
        $this->state->acquire('admin', 'copia.zip');

        self::assertTrue($this->state->forceUnlock());
        self::assertFalse($this->state->isRunning());
        self::assertFalse($this->state->forceUnlock(), 'Sin bloqueo no hay nada que quitar.');
    }

    public function testTheLastResultAndTheRestoredTimeArePersisted(): void
    {
        $this->state->writeResult(['ok' => true, 'by' => 'admin', 'file' => 'copia.zip']);
        $this->state->markRestored();

        $result = $this->state->lastResult();
        self::assertNotNull($result);
        self::assertTrue($result['ok']);
        self::assertSame('copia.zip', $result['file']);
        self::assertIsInt($result['at']);
        self::assertEqualsWithDelta(time(), $this->state->restoredAt(), 2);
    }

    /** @param array<string, mixed> $info */
    private function writeLock(array $info): void
    {
        @mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/restore.lock', json_encode($info));
    }
}
