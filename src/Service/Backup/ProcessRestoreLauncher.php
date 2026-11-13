<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lanza la restauración como proceso independiente de la petición web: la restauración tarda minutos y debe sobrevivir a que
 * se cierre la conexión del navegador (y, con FrankenPHP o PHP-FPM, a que termine el worker que atendió la petición).
 *
 * El proceso se desacopla con el propio intérprete de órdenes (`nohup … &` en Linux/macOS, `start /B` en Windows) en lugar de
 * con Process::start(): el destructor de Process mata a un hijo que siga vivo al terminar la petición. Los argumentos se
 * escapan y solo llegan nombres de copia ya validados por BackupLibrary.
 *
 * En Windows no está probado: se ha escrito siguiendo la documentación del sistema.
 */
final class ProcessRestoreLauncher implements RestoreLauncher
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%app.restore_state_dir%')]
        private readonly string $stateDir,
    ) {}

    public function isAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true) && !in_array('exec', $disabled, true) && $this->phpCommand() !== null;
    }

    public function launch(string $zipPath, string $by): void
    {
        $php = $this->phpCommand();
        if ($php === null) {
            throw new BackupException('No se encontró el intérprete de PHP para lanzar la restauración.');
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0700, true) && !is_dir($this->stateDir)) {
            throw new BackupException('No se pudo crear la carpeta de estado de la restauración.');
        }

        $arguments = [...$php, $this->projectDir . '/bin/console', 'app:backup:restore', $zipPath, '--force', '--lock-held', '--previous-backup', '--by=' . $by, '--no-interaction'];
        $log       = $this->stateDir . '/restore.log';

        $line = '\\' === \DIRECTORY_SEPARATOR
            ? sprintf('start /B "" %s >> %s 2>&1', implode(' ', array_map($this->windowsQuote(...), $arguments)), $this->windowsQuote($log))
            : sprintf('nohup %s >> %s 2>&1 &', implode(' ', array_map('escapeshellarg', $arguments)), escapeshellarg($log));

        // El intérprete vuelve en cuanto lanza el proceso en segundo plano; su salida va al fichero, no a una tubería que se cierre.
        $shell = Process::fromShellCommandline($line, $this->projectDir);
        $shell->setTimeout(15);
        $shell->run();
        if (!$shell->isSuccessful()) {
            throw new BackupException('No se pudo lanzar el proceso de restauración: ' . trim($shell->getErrorOutput()));
        }
    }

    public function consoleCommand(string $zipPath): string
    {
        $php = $this->phpCommand();

        return implode(' ', array_map('escapeshellarg', [...($php ?? ['php']), 'bin/console', 'app:backup:restore', $zipPath]));
    }

    /**
     * El intérprete de PHP con el que lanzar la consola. En el binario de FrankenPHP (que es el servidor y no un PHP de línea de
     * órdenes) hay que usar `frankenphp php-cli`.
     *
     * @return list<string>|null
     */
    private function phpCommand(): ?array
    {
        if (str_contains(strtolower(basename(\PHP_BINARY)), 'frankenphp')) {
            return [\PHP_BINARY, 'php-cli'];
        }

        $php = (new PhpExecutableFinder())->find();

        return $php === false ? null : [$php];
    }

    private function windowsQuote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
