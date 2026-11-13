<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/**
 * La Content-Security-Policy de los ficheros de servidor limita todo al propio origen (`default-src 'self'`).
 * Estas pruebas protegen esa suposición: ninguna pantalla debe pedir recursos de otro dominio (p. ej. el
 * *polyfill* de importmap, que AssetMapper carga de un CDN si no está en local) y la política debe ser la misma
 * en Docker, en el binario nativo y en Plesk.
 */
class ContentSecurityPolicyTest extends ControllerTestCase
{
    /** Orígenes que pueden aparecer en el HTML sin que se carguen: espacio de nombres SVG y enlaces de ayuda. */
    private const array ALLOWED_PREFIXES = [
        'http://www.w3.org/',
        'https://reasol-edu.github.io/gestconv-plus/',
        'https://www.juntadeandalucia.es/educacion/secretariavirtual/',
    ];

    public function testNoPageLoadsResourcesFromAnotherOrigin(): void
    {
        $pages = ['/login' => $this->fetch('/login')];

        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('csp.' . uniqid('', false))->setPassword('x');
        $centre  = (new EducationalCentre())->setCode('9' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT))->setName('IES CSP')->setCity('Sevilla');
        $this->persist($teacher, $centre);
        $this->loginAs($teacher, $centre);
        foreach (['/', '/partes', '/partes/nuevo', '/perfil/ajustes'] as $url) {
            $pages[$url] = $this->fetch($url);
        }

        foreach ($pages as $url => $html) {
            preg_match_all('#https?://[^\s"\'<>)]+#', $html, $matches);
            $external = array_filter(
                array_unique($matches[0]),
                static fn (string $found): bool => array_filter(self::ALLOWED_PREFIXES, static fn (string $prefix): bool => str_starts_with($found, $prefix)) === [],
            );
            self::assertSame([], array_values($external), "La pantalla $url referencia otros orígenes: la CSP los bloquearía.");
        }
    }

    public function testTheSamePolicyIsSentByDockerNativeBinaryAndPlesk(): void
    {
        $root     = dirname(__DIR__, 3);
        $policies = [];
        foreach (['docker/Caddyfile', 'dist/Caddyfile', 'public/.htaccess'] as $file) {
            self::assertSame(1, preg_match('/Content-Security-Policy\s+"([^"]+)"/', (string) file_get_contents("$root/$file"), $m), "$file no define la Content-Security-Policy.");
            $policies[$file] = $m[1];
        }

        self::assertCount(1, array_unique($policies), 'Las tres configuraciones deben llevar la misma política.');

        $policy = reset($policies);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'", "connect-src 'self'"] as $directive) {
            self::assertStringContainsString($directive, $policy);
        }
        self::assertStringNotContainsString('*', $policy, 'Nada de comodines: la aplicación no carga recursos de terceros.');
        self::assertDoesNotMatchRegularExpression('#https?:#', $policy, 'La política no debe autorizar ningún dominio concreto.');
    }

    private function fetch(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }
}
