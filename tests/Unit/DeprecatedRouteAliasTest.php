<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Alias de rutas que Symfony UX ha deprecado: generar una URL con ellos escribe una deprecación en el registro del
 * servidor en cada petición. Este test recorre plantillas y código y falla si vuelven a aparecer.
 */
class DeprecatedRouteAliasTest extends TestCase
{
    /** alias deprecado => el que lo sustituye */
    private const array DEPRECATED = [
        'ux_entity_autocomplete' => 'ux_autocomplete', // symfony/ux-autocomplete 3.5
    ];

    public function testNoDeprecatedRouteAliasIsUsed(): void
    {
        $root     = dirname(__DIR__, 2);
        $found    = [];
        $iterator = new \AppendIterator();
        foreach (['templates', 'src', 'assets', 'config'] as $dir) {
            $iterator->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS)));
        }

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || str_contains($file->getPathname(), '/assets/vendor/')) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            foreach (self::DEPRECATED as $old => $new) {
                if (str_contains($content, $old)) {
                    $found[] = sprintf('%s usa «%s»: usa «%s»', substr($file->getPathname(), strlen($root) + 1), $old, $new);
                }
            }
        }

        self::assertSame([], $found);
    }
}
