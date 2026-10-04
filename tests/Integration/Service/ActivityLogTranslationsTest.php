<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Twig\ActivityLogDataExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * El registro de actividad no debe mostrar nada sin traducir: ni los tipos de acción, ni los nombres de
 * los campos auditados, ni los valores de los datos (enumerados, booleanos, fechas).
 */
class ActivityLogTranslationsTest extends KernelTestCase
{
    private TranslatorInterface&TranslatorBagInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface && $translator instanceof TranslatorBagInterface);
        $this->translator = $translator;
    }

    public function testEveryActionTypeEmittedByTheCodeHasALabel(): void
    {
        $actions = ['session.login', 'session.login_failed', 'session.logout', 'session.impersonate_start', 'session.impersonate_stop'];
        $prefixes = [];

        foreach ($this->sources() as $source) {
            preg_match_all("/activityLog->log\(\s*'([a-z_.]+)'/", $source, $m);
            array_push($actions, ...$m[1]);
            // Controladores de catálogo: el tipo de acción de borrado se construye con el prefijo del controlador.
            if (preg_match("/function logEventPrefix\(\): string\s*\{\s*return '([a-z_]+)';/", $source, $p)) {
                $prefixes[] = $p[1];
            }
        }
        foreach ($prefixes as $prefix) {
            $actions[] = $prefix . '.deleted';
        }

        self::assertGreaterThan(40, count($actions), 'No se ha encontrado ningún tipo de acción: ¿ha cambiado la forma de registrar?');
        $missing = array_filter(array_unique($actions), fn (string $a): bool => !$this->has('activity_log.action.' . $a));
        self::assertSame([], array_values($missing), 'Tipos de acción sin traducir en translations/admin.es.yaml');
    }

    public function testEveryAuditedFieldHasALabel(): void
    {
        $fields = [];
        foreach ($this->sources() as $source) {
            preg_match_all('/const\s+(?:array\s+)?(?:LOGGED_[A-Z_]*|CONTACT_FIELDS)\s*=\s*\[([^\]]*)\]/', $source, $consts);
            foreach ($consts[1] as $list) {
                preg_match_all("/'([A-Za-z0-9]+)'/", $list, $names);
                array_push($fields, ...$names[1]);
            }
        }

        self::assertGreaterThan(25, count(array_unique($fields)));
        $missing = array_filter(array_unique($fields), fn (string $f): bool => !$this->has('activity_log.change.' . $f) && !$this->has('activity_log.field.' . $f));
        self::assertSame([], array_values($missing), 'Campos auditados sin etiqueta (activity_log.change.* / activity_log.field.*)');
    }

    public function testEveryDataKeyHasALabel(): void
    {
        $keys = [];
        foreach ($this->sources() as $source) {
            preg_match_all("/activityLog->log\(\s*'[a-z_.]+',\s*\[(.*?)\]\s*\)\s*;/s", $source, $calls);
            foreach ($calls[1] as $body) {
                preg_match_all("/'([A-Za-z_]+)'\s*=>/", $body, $m);
                array_push($keys, ...$m[1]);
            }
        }
        // Claves que añade el registro automático de peticiones (ActivityLogSubscriber)
        array_push($keys, 'method', 'path', 'status', 'username');

        $missing = array_filter(array_unique($keys), fn (string $k): bool => $k !== 'changes' && !$this->has('activity_log.field.' . $k));
        self::assertSame([], array_values($missing), 'Claves de datos sin etiqueta (activity_log.field.*)');
    }

    public function testValuesAreTranslated(): void
    {
        $rows = (new ActivityLogDataExtension($this->translator))->formatRows([
            'result'  => 'notified',
            'source'  => 'seneca',
            'changes' => [
                'studentId'         => ['before' => 'A1', 'after' => 'A2'],
                'expelledFromClass' => ['before' => false, 'after' => true],
                'tasksCompleted'    => ['before' => 'unknown', 'after' => 'yes'],
                'occurredAt'        => ['before' => '2026-10-02T10:15:00+00:00', 'after' => '2026-10-05T10:20:00+00:00'],
                'effectiveFrom'     => ['before' => null, 'after' => '2026-10-05T00:00:00+00:00'],
            ],
        ]);

        $byLabel = [];
        foreach ($rows as $row) {
            $byLabel[$row['label']] = $row;
        }

        self::assertSame('Notificado', $byLabel['Resultado']['value'] ?? null);
        self::assertSame('Séneca', $byLabel['Origen']['value'] ?? null);
        self::assertSame(['type' => 'change', 'label' => 'NIE', 'before' => 'A1', 'after' => 'A2'], $byLabel['NIE']);
        self::assertSame('No', $byLabel['Expulsado de clase']['before'] ?? null);
        self::assertSame('Sí', $byLabel['Expulsado de clase']['after'] ?? null);
        self::assertSame('No se sabe', $byLabel['Tareas cumplimentadas']['before'] ?? null);
        self::assertSame('02/10/2026 10:15', $byLabel['Fecha y hora del suceso']['before'] ?? null);
        self::assertSame('05/10/2026', $byLabel['Vigente desde']['after'] ?? null);
        self::assertSame('—', $byLabel['Vigente desde']['before'] ?? null);
    }

    private function has(string $key): bool
    {
        return $this->translator->getCatalogue('es')->has($key, 'admin');
    }

    /** @return iterable<string> contenido de los ficheros PHP de src/ */
    private function sources(): iterable
    {
        foreach ((new Finder())->files()->in(self::getContainer()->getParameter('kernel.project_dir') . '/src')->name('*.php') as $file) {
            yield $file->getContents();
        }
    }
}
