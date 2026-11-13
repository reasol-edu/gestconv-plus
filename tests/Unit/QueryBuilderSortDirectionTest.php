<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Doctrine ORM 3.7 depreca pasar «ASC»/«DESC» (cadenas) a `QueryBuilder::orderBy()`/`addOrderBy()` y pide
 * `\SortDirection::Ascending`/`Descending` (el log de producción lo avisa una vez por enlace, así que un aviso oculta
 * a los demás). Este test recorre el código fuente y falla si vuelve a aparecer una cadena como sentido del orden.
 */
class QueryBuilderSortDirectionTest extends TestCase
{
    public function testNoOrderByReceivesAStringDirection(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                $inOrderBy       = preg_match('/(?:orderBy|addOrderBy)\(.*[\'"](?:ASC|DESC)[\'"]/i', $line) === 1;
                // Solo en mayúsculas: el estado de ordenación de la interfaz usa 'asc'/'desc' y no llega a Doctrine.
                $stringDirection = preg_match('/\?\s*[\'"](?:ASC|DESC)[\'"]\s*:\s*[\'"](?:ASC|DESC)[\'"]/', $line) === 1;
                if ($inOrderBy || $stringDirection) {
                    $offenders[] = sprintf('%s:%d  %s', substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1), $number + 1, trim($line));
                }
            }
        }

        self::assertSame([], $offenders, 'Usa \SortDirection::Ascending/Descending en vez de cadenas como sentido del orden.');
    }

    public function testTheScanRecognisesTheOldForm(): void
    {
        // Comprobación del propio detector: sin esto un patrón roto daría un verde falso.
        self::assertSame(1, preg_match('/(?:orderBy|addOrderBy)\(.*[\'"](?:ASC|DESC)[\'"]/i', "\$qb->orderBy('x.name', 'ASC');"));
        self::assertSame(1, preg_match('/\?\s*[\'"](?:ASC|DESC)[\'"]\s*:\s*[\'"](?:ASC|DESC)[\'"]/', "\$d = \$a ? 'ASC' : 'DESC';"));
        self::assertSame(0, preg_match('/\?\s*[\'"](?:ASC|DESC)[\'"]\s*:\s*[\'"](?:ASC|DESC)[\'"]/', "\$this->sortDir = \$a ? 'desc' : 'asc';"));
        self::assertSame(0, preg_match('/(?:orderBy|addOrderBy)\(.*[\'"](?:ASC|DESC)[\'"]/i', "\$qb->orderBy('x.name', \\SortDirection::Ascending);"));
    }
}
