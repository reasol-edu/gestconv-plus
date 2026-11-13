<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Backup\BackupException;
use App\Service\Backup\RestoreLauncher;

/**
 * Sustituye al lanzador real en los tests: no arranca ningún proceso (restaurar de verdad sustituiría la base de datos), solo anota
 * lo que se le pidió. El estado es estático porque el cliente de pruebas reinicia el kernel entre peticiones; llama a `clear()`
 * al empezar cada test.
 */
final class RecordingRestoreLauncher implements RestoreLauncher
{
    /** @var list<array{zip: string, by: string}> */
    public static array $launched = [];

    public static bool $available = true;
    public static ?string $failWith = null;

    public static function clear(): void
    {
        self::$launched  = [];
        self::$available = true;
        self::$failWith  = null;
    }

    public function isAvailable(): bool
    {
        return self::$available;
    }

    public function launch(string $zipPath, string $by): void
    {
        if (self::$failWith !== null) {
            throw new BackupException(self::$failWith);
        }
        self::$launched[] = ['zip' => $zipPath, 'by' => $by];
    }

    public function consoleCommand(string $zipPath): string
    {
        return 'php bin/console app:backup:restore ' . escapeshellarg($zipPath);
    }
}
