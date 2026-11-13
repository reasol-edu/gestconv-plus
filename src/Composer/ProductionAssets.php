<?php

declare(strict_types=1);

namespace App\Composer;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Compila el CSS (Tailwind) y los assets (AssetMapper) al terminar un `composer install` o `composer update` **de producción**
 * (`--no-dev`). Está pensado para los despliegues en los que se actualiza con Git y Composer (Plesk, Ubuntu a mano): sin esto,
 * cada actualización exigía acordarse de ejecutar `tailwind:build` y `asset-map:compile`, y un CSS viejo deja sin estilo las
 * pantallas nuevas.
 *
 * No hace nada en desarrollo (`composer install` normal): `asset-map:compile` crea `public/assets/` y congela AssetMapper en modo
 * compilado, y los cambios dejarían de verse. Tampoco en la imagen Docker ni en la compilación de los binarios, que instalan con
 * `--no-scripts` y compilan por su cuenta. Se puede omitir con `GESTCONV_SKIP_ASSET_BUILD=1`.
 *
 * Un fallo no rompe `composer install` (en hostings que no permiten ejecutar procesos, p. ej.): avisa y dice qué ejecutar a mano.
 *
 * Se invoca desde `composer.json` (`post-install-cmd` y `post-update-cmd`); recibe el evento de Composer, que no es una
 * dependencia del proyecto, por eso no lleva tipo.
 */
final class ProductionAssets
{
    /** @return list<list<string>> los argumentos de bin/console, en orden: primero el CSS, que el compilador de assets recoge */
    public static function commands(): array
    {
        return [
            ['tailwind:build', '--minify'],
            ['asset-map:compile'],
        ];
    }

    public static function build(mixed $event = null): void
    {
        if (is_object($event) && method_exists($event, 'isDevMode') && $event->isDevMode() === true) {
            return;
        }
        if (getenv('GESTCONV_SKIP_ASSET_BUILD') === '1') {
            return;
        }

        $projectDir = dirname(__DIR__, 2);
        $php        = (new PhpExecutableFinder())->find();
        if ($php === false) {
            self::warn('No se encontró PHP para compilar los assets.');

            return;
        }

        fwrite(\STDOUT, "Compilando el CSS y los assets de producción…\n");
        foreach (self::commands() as $arguments) {
            $process = new Process([$php, $projectDir . '/bin/console', ...$arguments, '--env=prod', '--no-interaction'], $projectDir, ['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);
            $process->setTimeout(900);

            try {
                $process->run(static function (string $type, string $buffer): void {
                    fwrite($type === Process::ERR ? \STDERR : \STDOUT, $buffer);
                });
            } catch (\Throwable $e) {
                self::warn($e->getMessage());

                return;
            }

            if (!$process->isSuccessful()) {
                self::warn(sprintf('`bin/console %s` terminó con error.', implode(' ', $arguments)));

                return;
            }
        }
    }

    private static function warn(string $reason): void
    {
        fwrite(\STDERR, "\nAVISO: no se pudieron compilar los assets automáticamente. {$reason}\n"
            . "Ejecuta a mano, antes de usar la aplicación:\n"
            . "    php bin/console tailwind:build --minify --env=prod\n"
            . "    php bin/console asset-map:compile --env=prod\n\n");
    }
}
