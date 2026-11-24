<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El binario nativo (dist/install-ubuntu.sh, start.sh…) escribe su propio `.env` con una lista fija de variables y NO incluye el
 * `.env` del repositorio. Una variable que el código exige (`#[Autowire(env: 'X')]`, sin `default::`) y que ni tiene un valor por
 * defecto en config/services.yaml (`env(X)`) ni la escribe el instalador hace fallar, en una instalación nueva, toda página que la
 * necesite («Environment variable not found»). Al añadir una variable, hay que darle un valor por defecto ahí.
 */
final class EnvironmentVariablesTest extends TestCase
{
    public function testEveryRequiredVariableHasADefaultOrIsWrittenByTheInstaller(): void
    {
        $root      = \dirname(__DIR__, 2);
        $services  = (string) file_get_contents($root . '/config/services.yaml');
        $installer = (string) file_get_contents($root . '/dist/install-ubuntu.sh');

        $missing = [];
        $checked = 0;
        $files   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match_all("/Autowire\\(\\s*env:\\s*'(?:[a-z_]+:)*([A-Z][A-Z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $found, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($found as $match) {
                // `default::NOMBRE` se lee como vacío si falta: siempre es seguro.
                if (str_contains($match[0], 'default::')) {
                    continue;
                }
                ++$checked;
                $name = $match[1];
                if (!str_contains($services, "env({$name}):") && !str_contains($installer, $name)) {
                    $missing[$name] = $file->getPathname();
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'El test no encontró ninguna variable que comprobar: ¿cambió el patrón de #[Autowire(env: …)]?');
        self::assertSame([], $missing, 'Variables que el código exige, sin valor por defecto en config/services.yaml y que no escribe dist/install-ubuntu.sh');
    }
}
