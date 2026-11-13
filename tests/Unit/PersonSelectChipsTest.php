<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Las selecciones de personas (Tom Select) se ven igual en toda la aplicación: etiqueta redonda definida **una sola vez**
 * en `assets/styles/app.css`, con un icono según el tipo de persona (estudiante, docente u otro usuario) que sale de una
 * clase en el <select> (`ts-person-student|teacher|user`). Este test evita que se pierda esa unidad: un select de personas
 * sin su clase, una clase sin su CSS o una plantilla que vuelva a definir sus propias etiquetas.
 */
class PersonSelectChipsTest extends TestCase
{
    private const array KINDS = ['ts-person-student', 'ts-person-teacher', 'ts-person-user'];

    /** Marcas que identifican un select de personas dentro de su etiqueta. */
    private const array PERSON_MARKERS = [
        "alias: 'teacher_centre'", "alias: 'teacher_admin'", "alias: 'absence_teacher'",
        'name="registered_by"', 'name="students[]"', 'name="student_group"', 'name="managers[]"', 'name="assignee"',
        'name="teacher"', 'name="teacher_id"', 'name="coordinator"', 'name="companions[]"',
        'name="committee_members[]"', 'name="counselors[]"', 'name="activity_coordinators[]"', 'name="admins[]"',
        'id="staff-tutors"', 'id="staff-guards"',
    ];

    public function testEveryPersonSelectHasItsKindClass(): void
    {
        $missing = [];
        $count   = 0;

        foreach ($this->templates() as $path => $content) {
            if (preg_match_all('/<select\b.*?<\/select>/s', $content, $selects) === 0) {
                // Fragmentos como `_replaces_select`, cuyo <select> no cierra en el mismo fichero de forma limpia.
                $selects = [[$content]];
            }
            foreach ($selects[0] as $select) {
                $opening = (string) strstr($select, '>' . "\n", true) ?: substr($select, 0, 1200);
                foreach (self::PERSON_MARKERS as $marker) {
                    if (!str_contains($opening, $marker)) {
                        continue;
                    }
                    ++$count;
                    if (!$this->hasKind($opening)) {
                        $missing[] = sprintf('%s → %s', $path, $marker);
                    }
                    break;
                }
            }
        }

        self::assertGreaterThan(15, $count, 'El detector casi no encuentra selects de personas: ¿han cambiado las plantillas?');
        self::assertSame([], $missing, 'Falta la clase ts-person-student|teacher|user en estos selects de personas.');
    }

    public function testEveryKindIsDefinedWithItsIconInTheGlobalCss(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/styles/app.css');

        foreach (self::KINDS as $kind) {
            self::assertStringContainsString(':root .ts-wrapper.' . $kind . ' {', $css, "app.css no define el icono de $kind");
            self::assertStringContainsString('.ts-wrapper.' . $kind . ' .ts-control > .item::before', $css, "app.css no pinta el icono de $kind");
        }
        self::assertStringContainsString('.ts-wrapper.multi .ts-control > .item {', $css, 'app.css debe definir la etiqueta de las selecciones múltiples.');
    }

    public function testNoTemplateDefinesItsOwnChipStyles(): void
    {
        $own = [];
        foreach ($this->templates() as $path => $content) {
            if (preg_match('/\.ts-control\s+\.item|\.ts-wrapper\.multi|\.item\s+\.remove/', $content) === 1) {
                $own[] = $path;
            }
        }

        self::assertSame([], $own, 'Las etiquetas de Tom Select se definen una sola vez, en assets/styles/app.css.');
    }

    /** @return array<string, string> */
    private function templates(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    private function hasKind(string $opening): bool
    {
        foreach (self::KINDS as $kind) {
            if (str_contains($opening, $kind)) {
                return true;
            }
        }

        return false;
    }
}
