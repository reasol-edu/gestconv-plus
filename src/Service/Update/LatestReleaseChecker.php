<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\AppSettingsInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Averigua la última versión publicada de GestConv+ (releases de GitHub) para avisar a los administradores.
 *
 * Es una consulta saliente del servidor con un tiempo máximo corto, y su resultado se guarda en caché: 24 horas si salió bien
 * y 1 hora si falló, para que un servidor sin salida a internet no se quede esperando en cada carga del panel. No envía
 * ningún dato del centro; solo recibe el número de versión y la dirección de la release, y **nunca** muestra el texto de la
 * release (es contenido de terceros). El ajuste global `updates.check_enabled` la desactiva por completo.
 */
final class LatestReleaseChecker
{
    public const string SETTING = 'updates.check_enabled';

    private const string CACHE_KEY   = 'latest_release_v1';
    private const int TTL_OK         = 86400;
    private const int TTL_FAILURE    = 3600;
    private const float TIMEOUT      = 3.0;

    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'app.update_check')]
        private readonly CacheInterface $cache,
        private readonly AppSettingsInterface $settings,
        #[Autowire('%app.repository%')]
        private readonly string $repository,
        #[Autowire('%app.version%')]
        private readonly string $currentVersion,
    ) {}

    public function isEnabled(): bool
    {
        // Sin definición (base sin migrar) se comprueba, que es el valor por defecto.
        return $this->settings->getGlobal(self::SETTING) !== false;
    }

    public function status(): UpdateStatus
    {
        if (!$this->isEnabled()) {
            return new UpdateStatus(UpdateStatus::DISABLED);
        }

        /** @var array{version?: string, url?: string}|null $latest */
        $latest = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?array {
            $release = $this->fetch();
            $item->expiresAfter($release === null ? self::TTL_FAILURE : self::TTL_OK);

            return $release;
        });

        if (!is_array($latest) || !isset($latest['version'], $latest['url'])) {
            return new UpdateStatus(UpdateStatus::UNKNOWN);
        }

        return version_compare($latest['version'], $this->currentVersion, '>')
            ? new UpdateStatus(UpdateStatus::NEWER, $latest['version'], $latest['url'])
            : new UpdateStatus(UpdateStatus::CURRENT, $latest['version'], $latest['url']);
    }

    /** @return array{version: string, url: string}|null null si no se pudo consultar o la respuesta no es una release válida */
    private function fetch(): ?array
    {
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/?$#', $this->repository, $match) !== 1) {
            return null;
        }

        try {
            $response = $this->client->request('GET', 'https://api.github.com/repos/' . $match[1] . '/releases/latest', [
                'headers'      => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'GestConv+/' . $this->currentVersion],
                'timeout'      => self::TIMEOUT,
                'max_duration' => self::TIMEOUT + 1,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = $response->toArray();
        } catch (\Throwable) {
            return null;
        }

        $tag = $data['tag_name'] ?? null;
        if (!is_string($tag) || preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $version) !== 1) {
            return null;
        }

        // La dirección se construye aquí en vez de fiarse de la respuesta: así siempre apunta al repositorio del proyecto.
        return ['version' => $version[1], 'url' => rtrim($this->repository, '/') . '/releases/tag/' . $tag];
    }
}
