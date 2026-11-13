<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine ORM 3.7 depreca indicar el sentido de `#[ORM\OrderBy]` con cadenas («ASC»/«DESC») y pide una instancia de
 * `SortDirection` (el log de producción avisa al cargar cada entidad). `doctrine/deprecations` solo avisa una vez por
 * enlace, así que se comprueba por reflexión en todas las entidades.
 */
class OrderByDirectionTest extends TestCase
{
    public function testEveryOrderByUsesASortDirectionInsteadOfAString(): void
    {
        $checked = 0;
        $strings = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                foreach ($property->getAttributes(ORM\OrderBy::class) as $attribute) {
                    foreach ($attribute->getArguments()[0] ?? [] as $field => $direction) {
                        ++$checked;
                        if (!$direction instanceof \SortDirection) {
                            $strings[] = sprintf('%s::$%s (%s => %s)', $class, $property->getName(), $field, is_string($direction) ? "'$direction'" : get_debug_type($direction));
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'No se ha encontrado ningún #[ORM\OrderBy]: ¿cambió la estructura de las entidades?');
        self::assertSame([], $strings, 'Usa \SortDirection::Ascending/Descending en vez de cadenas.');
    }
}
