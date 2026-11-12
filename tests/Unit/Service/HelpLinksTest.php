<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\HelpLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HelpLinksTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function mappedTargets(): iterable
    {
        foreach (HelpLinks::MAP as $prefix => [$chapter, $anchor]) {
            yield $prefix => [$chapter, $anchor];
        }
    }

    /** Cada ancla del mapa existe como `{#ancla}` explícito en su capítulo: si se renombra un título, falla aquí. */
    #[DataProvider('mappedTargets')]
    public function testEveryMappedAnchorExistsInItsChapter(string $chapter, string $anchor): void
    {
        $file = dirname(__DIR__, 3) . '/docs/manual/' . $chapter . '.md';
        self::assertFileExists($file);
        self::assertMatchesRegularExpression(
            '/^#{1,6} .*\{#' . preg_quote($anchor, '/') . '\}\s*$/m',
            (string) file_get_contents($file),
            sprintf('Falta el ancla {#%s} en %s.md', $anchor, $chapter),
        );
    }

    public function testTheLongestPrefixWinsAndUnknownRoutesHaveNoHelp(): void
    {
        $help = new HelpLinks('https://manual.example.org/');

        self::assertSame('https://manual.example.org/04-convivencia-profesorado.html#registrar-un-nuevo-parte', $help->urlForRoute('app_incidents_new'));
        self::assertSame('https://manual.example.org/04-convivencia-profesorado.html#partes-de-convivencia', $help->urlForRoute('app_incidents_index'));
        self::assertSame('https://manual.example.org/07-salidas.html#pase-prioritario', $help->urlForRoute('app_students_priority_pass_edit'));
        self::assertSame('https://manual.example.org/04-convivencia-profesorado.html#ficha-del-estudiante', $help->urlForRoute('app_students_show'));
        self::assertNull($help->urlForRoute('app_login'));
        self::assertNull($help->urlForRoute(null));
        // Un prefijo solo casa con nombres completos: «app_exitsx» no es «app_exits».
        self::assertNull($help->urlForRoute('app_exitsx'));
    }
}
