<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Controller\PushController;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\PushSubscription;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Support\RecordingPushDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

class PushControllerTest extends ControllerTestCase
{
    private const FCM = 'https://fcm.googleapis.com/fcm/send/dispositivo-1';

    protected function setUp(): void
    {
        parent::setUp();
        RecordingPushDispatcher::reset();
        RecordingPushDispatcher::$configured = true;
    }

    protected function tearDown(): void
    {
        RecordingPushDispatcher::reset();
        parent::tearDown();
    }

    // ── el endpoint que da el navegador: lista cerrada (SSRF) ─────────────────

    /** @return iterable<string, array{string, bool}> */
    public static function endpoints(): iterable
    {
        yield 'FCM (Chrome, Edge, Brave…)'  => ['https://fcm.googleapis.com/fcm/send/abc', true];
        yield 'Mozilla'                      => ['https://updates.push.services.mozilla.com/wpush/v2/abc', true];
        yield 'Apple'                        => ['https://web.push.apple.com/abc', true];
        yield 'Windows'                      => ['https://db5p.notify.windows.com/w/?token=abc', true];
        yield 'http sin cifrar'              => ['http://fcm.googleapis.com/fcm/send/abc', false];
        yield 'dirección interna'            => ['https://127.0.0.1/admin', false];
        yield 'metadatos de la nube'         => ['https://169.254.169.254/latest/meta-data', false];
        yield 'localhost'                    => ['https://localhost/x', false];
        yield 'dominio ajeno'                => ['https://evil.example/fcm.googleapis.com', false];
        yield 'sufijo engañoso'              => ['https://evilgoogleapis.com/x', false];
        yield 'con usuario (@)'              => ['https://fcm.googleapis.com@evil.example/x', false];
        yield 'con puerto'                   => ['https://fcm.googleapis.com:8443/x', false];
        yield 'el sufijo a secas'            => ['https://.googleapis.com/x', false];
        yield 'vacío'                        => ['', false];
    }

    #[DataProvider('endpoints')]
    public function testOnlyKnownPushServicesOverHttpsAreAccepted(string $endpoint, bool $allowed): void
    {
        self::assertSame($allowed, PushController::isAllowedEndpoint($endpoint));
    }

    // ── alta, baja y prueba ───────────────────────────────────────────────────

    public function testASubscriptionIsStoredForTheLoggedTeacherWithAValidCsrfToken(): void
    {
        [$teacher, $centre] = $this->scenario();
        $this->loginAs($teacher, $centre);

        $this->post('/push/suscripcion', ['endpoint' => self::FCM, 'keys' => ['p256dh' => 'abcDEF_-123', 'auth' => 'xyz_-9']]);

        self::assertResponseStatusCodeSame(201);
        $this->em->clear();
        $stored = $this->em->getRepository(PushSubscription::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame($teacher->getId()->toRfc4122(), $stored[0]->getTeacher()->getId()->toRfc4122());
    }

    public function testASubscriptionWithoutOrWithAWrongCsrfTokenIsForbidden(): void
    {
        [$teacher, $centre] = $this->scenario();
        $this->loginAs($teacher, $centre);

        $this->post('/push/suscripcion', ['endpoint' => self::FCM, 'keys' => ['p256dh' => 'abc', 'auth' => 'xyz']], 'token-falso');

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(PushSubscription::class)->findAll());
    }

    public function testInvalidEndpointsAndKeysAreRejectedAndNothingIsStored(): void
    {
        [$teacher, $centre] = $this->scenario();
        $this->loginAs($teacher, $centre);

        foreach ([
            ['endpoint' => 'https://127.0.0.1/x', 'keys' => ['p256dh' => 'abc', 'auth' => 'xyz']],
            ['endpoint' => self::FCM, 'keys' => ['p256dh' => '<script>', 'auth' => 'xyz']],
            ['endpoint' => self::FCM, 'keys' => ['p256dh' => 'abc']],
            ['endpoint' => self::FCM],
        ] as $body) {
            $this->post('/push/suscripcion', $body);
            self::assertResponseStatusCodeSame(400);
        }
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(PushSubscription::class)->findAll());
    }

    public function testTheFeatureDoesNotExistWithoutVapidKeys(): void
    {
        [$teacher, $centre] = $this->scenario();
        $this->loginAs($teacher, $centre);
        $token = $this->csrfToken(); // la tarjeta con el token solo se pinta mientras hay claves
        RecordingPushDispatcher::$configured = false;

        $this->post('/push/suscripcion', ['endpoint' => self::FCM, 'keys' => ['p256dh' => 'abc', 'auth' => 'xyz']], $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testATeacherCanOnlyUnsubscribeTheirOwnDevice(): void
    {
        [$teacher, $centre] = $this->scenario();
        $other = $this->makeTeacher('other.push');
        $this->persist($other, new PushSubscription($other, self::FCM, 'clave', 'auth'));
        $this->loginAs($teacher, $centre);

        $this->post('/push/suscripcion', ['endpoint' => self::FCM], null, 'DELETE');

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(PushSubscription::class)->findAll(), 'El dispositivo de otro docente sigue ahí.');
    }

    public function testTheTestNoticeGoesOnlyToTheOwnDevices(): void
    {
        [$teacher, $centre] = $this->scenario();
        $other = $this->makeTeacher('other.push2');
        $this->persist(
            $other,
            new PushSubscription($other, 'https://fcm.googleapis.com/fcm/send/ajeno', 'clave', 'auth'),
            new PushSubscription($teacher, self::FCM, 'clave', 'auth'),
        );
        $this->loginAs($teacher, $centre);

        $this->post('/push/prueba', []);

        self::assertResponseIsSuccessful();
        self::assertCount(1, RecordingPushDispatcher::$sent);
        self::assertSame([self::FCM], RecordingPushDispatcher::$sent[0]['endpoints']);
    }

    // ── la pantalla ───────────────────────────────────────────────────────────

    public function testTheProfileOffersTheSwitchOnlyWhenPushIsConfigured(): void
    {
        [$teacher, $centre] = $this->scenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/perfil/ajustes');
        self::assertSelectorExists('[data-controller="push"][data-push-public-key-value="BClavePublicaDePrueba"]');

        RecordingPushDispatcher::$configured = false;
        $this->client->request('GET', '/perfil/ajustes');
        self::assertSelectorNotExists('[data-controller="push"]');
    }

    // ── el service worker ─────────────────────────────────────────────────────

    public function testTheServiceWorkerNeverCachesAppPagesOrPersonalData(): void
    {
        $sw = (string) file_get_contents(dirname(__DIR__, 3) . '/public/sw.js');

        // Las navegaciones van siempre a la red (con la página «sin conexión» como único respaldo)…
        self::assertStringContainsString("request.mode === 'navigate'", $sw);
        self::assertStringContainsString('caches.match(OFFLINE_URL)', $sw);
        // …y lo único que se guarda en caché con `put` son recursos públicos de /assets/ y /static/.
        self::assertSame(1, substr_count($sw, 'cache.put('));
        self::assertStringContainsString("url.pathname.startsWith('/assets/')", $sw);
        self::assertStringContainsString("url.pathname.startsWith('/static/')", $sw);
        self::assertFileExists(dirname(__DIR__, 3) . '/public/offline.html');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body, ?string $token = null, string $method = 'POST'): void
    {
        $token ??= $this->csrfToken();
        $this->client->request($method, $url, [], [], [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function csrfToken(): string
    {
        // El token se genera con el mismo almacén de la sesión del cliente.
        $crawler = $this->client->request('GET', '/perfil/ajustes');

        return (string) $crawler->filter('[data-push-csrf-value]')->first()->attr('data-push-csrf-value');
    }

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function scenario(): array
    {
        $teacher = $this->makeTeacher('push.teacher.' . uniqid('', false));
        $centre  = (new EducationalCentre())->setCode('9' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT))->setName('IES Push')->setCity('Sevilla');
        $this->persist($teacher, $centre);

        return [$teacher, $centre];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username)->setPassword('x');
    }
}
