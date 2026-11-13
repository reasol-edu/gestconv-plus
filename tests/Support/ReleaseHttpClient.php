<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Cliente HTTP de los tests para la consulta de la última versión: nunca sale a la red. Por defecto la consulta «falla» (como
 * en un servidor sin internet); `willPublish()` y `willReturnStatus()` programan la respuesta y `$requests` cuenta las peticiones.
 *
 * El estado es estático porque el cliente de pruebas reinicia el kernel entre peticiones y con él el contenedor: una instancia
 * no sobreviviría. Llama a `clear()` al empezar cada test.
 */
final class ReleaseHttpClient extends MockHttpClient
{
    public static int $requests = 0;
    public static string $lastUrl = '';

    /** @var array<mixed> */
    public static array $lastHeaders = [];

    /** @var array{status: int, body: string}|null */
    private static ?array $next = null;

    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options): MockResponse {
            ++self::$requests;
            self::$lastUrl     = $url;
            self::$lastHeaders = $options['headers'] ?? [];

            if (self::$next === null) {
                throw new TransportException('sin red en los tests');
            }

            return new MockResponse(self::$next['body'], ['http_code' => self::$next['status'], 'response_headers' => ['content-type' => 'application/json']]);
        });
    }

    public static function clear(): void
    {
        self::$requests    = 0;
        self::$lastUrl     = '';
        self::$lastHeaders = [];
        self::$next        = null;
    }

    public static function willPublish(string $tag): void
    {
        self::$next = ['status' => 200, 'body' => json_encode([
            'tag_name' => $tag,
            'html_url' => 'https://evil.example/' . $tag,
            'body'     => '<script>alert(1)</script> Notas de la release',
        ], JSON_THROW_ON_ERROR)];
    }

    public static function willReturnStatus(int $status, string $body = '{}'): void
    {
        self::$next = ['status' => $status, 'body' => $body];
    }

    public static function willFail(): void
    {
        self::$next = null;
    }
}
