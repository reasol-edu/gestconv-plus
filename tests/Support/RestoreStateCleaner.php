<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;

/**
 * Vacía las carpetas de estado de restauración y de copias guardadas que usan los tests. Un bloqueo o una copia que sobrevivan a
 * un test que falle contaminarían a los siguientes (el bloqueo pone la aplicación en 503).
 */
final class RestoreStateCleaner
{
    public static function clean(ContainerInterface $container): void
    {
        foreach (['app.restore_state_dir', 'app.backup_dir'] as $parameter) {
            try {
                $dir = $container->get('parameter_bag')->get($parameter);
            } catch (\Throwable) {
                continue;
            }
            if (is_string($dir) && str_contains($dir, '/var/cache/test')) {
                self::remove($dir);
            }
        }
        RecordingRestoreLauncher::clear();
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
