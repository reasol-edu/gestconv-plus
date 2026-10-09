<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Un <form> dentro de otro no es HTML válido: el navegador ignora el <form> interior y su </form> cierra
 * el exterior, de modo que lo que viene después (campos, botón de guardar y controladores de Stimulus)
 * queda fuera del formulario. Ya ocurrió con la lista de observaciones dentro del formulario de sanción.
 *
 * Revisa de forma estática las plantillas: ninguna puede abrir un <form> dentro de otro ni incluir, dentro
 * de un <form>, una plantilla o componente que contenga uno. La única excepción es una inclusión con
 * `readonly: true` (el parcial oculta entonces sus formularios).
 */
final class NoNestedFormsTest extends TestCase
{
    /** @var array<string, string> */
    private array $templates = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3) . '/templates';
        foreach ((new Finder())->files()->in($root)->name('*.twig') as $file) {
            $content = file_get_contents($file->getPathname());
            self::assertIsString($content);
            $this->templates[str_replace('\\', '/', $file->getRelativePathname())] = (string) preg_replace('/\{#.*?#\}/s', '', $content);
        }
    }

    public function testNoTemplateNestsFormsDirectlyOrThroughIncludes(): void
    {
        self::assertNotEmpty($this->templates);

        $problems = [];
        foreach ($this->templates as $name => $source) {
            $depth = 0;
            preg_match_all(
                '/<form\b|<\/form>|\{%\s*(?:include|embed)\s+[\'"]([^\'"]+)[\'"][^%]*%\}|<twig:([A-Za-z:]+)/',
                $source,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $m) {
                $token = $m[0];
                if (str_starts_with($token, '<form')) {
                    if ($depth >= 1) {
                        $problems[] = $name . ': <form> dentro de otro <form>';
                    }
                    ++$depth;
                } elseif ($token === '</form>') {
                    $depth = max(0, $depth - 1);
                } elseif ($depth >= 1) {
                    $included = ($m[1] ?? '') !== '' ? $m[1] : 'components/' . str_replace(':', '/', $m[2] ?? '') . '.html.twig';
                    if ($this->containsForm($included) && !str_contains($token, 'readonly: true')) {
                        $problems[] = $name . ': incluye ' . $included . ', que contiene un <form>, dentro de un <form>';
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($problems)), "Formularios anidados:\n" . implode("\n", array_unique($problems)));
    }

    /** @param list<string> $seen */
    private function containsForm(string $name, array $seen = []): bool
    {
        if (!isset($this->templates[$name]) || in_array($name, $seen, true)) {
            return false;
        }
        $source = $this->templates[$name];
        if (preg_match('/<form\b/', $source) === 1) {
            return true;
        }
        preg_match_all('/\{%\s*(?:include|embed)\s+[\'"]([^\'"]+)[\'"]|<twig:([A-Za-z:]+)/', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $child = ($m[1] ?? '') !== '' ? $m[1] : 'components/' . str_replace(':', '/', $m[2] ?? '') . '.html.twig';
            if ($this->containsForm($child, [...$seen, $name])) {
                return true;
            }
        }

        return false;
    }
}
